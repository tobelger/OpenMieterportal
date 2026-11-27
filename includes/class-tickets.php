<?php
defined('ABSPATH') || exit;

/** Integrates the unmodified SupportCandy plugin with portal account boundaries. */
final class BPM_Tickets {
    public static function owner_id(object $ticket): int {
        $customer=$ticket->customer;
        $user=$customer ? $customer->user : null;
        return $user instanceof WP_User ? (int)$user->ID : 0;
    }
    public static function enabled(?object $h = null): bool {
        $mode = get_option('bpm_supportcandy_enabled', '');
        return class_exists('WPSC_Ticket') && ($mode === 'production' || ($mode === 'pilot' && $h && str_starts_with($h->reference, 'DEMO-')));
    }
    public static function boot(): void {
        add_action('init', [self::class, 'guard'], 20);
        add_filter('wpsc_shortcode_sections', static function ($sections) {
            if (isset($sections['ticket-list'])) { $sections['ticket-list']['label']='Vorgänge auflisten'; }
            if (isset($sections['new-ticket'])) { $sections['new-ticket']['label']='Neues Anliegen'; }
            return $sections;
        });
        add_filter('rest_pre_dispatch', static function ($result, $server, $request) {
            if (str_starts_with($request->get_route(), '/supportcandy/')) {
                if (!BPM_Portal::manager() && (!BPM_Portal::can_access(BPM_Portal::household()) || !self::enabled(BPM_Portal::household()))) { return new WP_Error('bpm_ticket_forbidden', 'Keine Berechtigung.', ['status'=>403]); }
            }
            return $result;
        }, 1, 3);
        add_filter('wpsc_load_frontend_scripts', static fn($load) => is_page((int)get_option('bpm_page_id')) && (BPM_Portal::manager() || (BPM_Portal::can_access(BPM_Portal::household()) && self::enabled(BPM_Portal::household()))));
        add_action('wpsc_create_new_ticket', [self::class, 'link_ticket'], 20);
        // Filter before SupportCandy validates recipients. Clearing them in
        // wpsc_en_before_sending is too late and queues recipientless mail.
        add_filter('wpsc_en_to_addresses', [self::class, 'notification_recipients'], 20, 2);
        add_filter('wpsc_en_cc_addresses', '__return_empty_array', 20);
        add_filter('wpsc_en_bcc_addresses', '__return_empty_array', 20);
        add_filter('wpsc_en_before_sending', static function ($en) {
            $en->subject = 'Neue Information im Mieterportal';
            $en->body = '<p>Im Mieterportal liegt eine neue Information zu einem Ticket bereit.</p><p><a href="'.esc_url(BPM_Portal::url()).'">Mieterportal öffnen</a></p>';
            if (get_option('bpm_noreply_notice', false)) { $en->body .= '<p>Bitte antworten Sie nicht auf diese automatisch versandte E-Mail. Nutzen Sie für Ihr Anliegen das Mieterportal.</p>'; }
            $en->attachments = [];
            $en->cc = []; $en->bcc = [];
            return $en;
        });
        add_filter('wpsc_restricted_ticket_action', static function ($restricted, $ticket) {
            if (BPM_Portal::manager()) { return $restricted; }
            $h = BPM_Portal::household();
            return $restricted || !BPM_Portal::can_access($h) || self::owner_id($ticket) !== get_current_user_id();
        }, 10, 2);
        add_filter('wpsc_get_ticket_url', static fn($url, $ticket) => add_query_arg(['wpsc-section'=>'ticket-list','ticket-id'=>$ticket->id], BPM_Portal::url()) . '#bpm-messages', 10, 2);
        add_action('admin_menu', static function () {
            $hook=add_submenu_page('belger-mieterportal', 'Tickets', 'Tickets', 'bpm_manage', 'bpm-tickets', '__return_null');
            add_action('load-'.$hook, static function () { wp_safe_redirect(admin_url('admin.php?page=wpsc-tickets')); exit; });
        }, 20);
        add_filter('body_class', static function ($classes) { if (is_page((int)get_option('bpm_page_id'))) { $classes[]='bpm-portal-page'; } return $classes; });
    }
    public static function notification_recipients(array $to, object $en): array {
        $to=array_values(array_filter($to,static fn($email)=>is_string($email)&&is_email($email)));
        $uid=self::owner_id($en->ticket);
        $h=$uid ? BPM_Portal::household($uid) : null;
        if (!$h) { return $to; }
        if (str_starts_with($h->reference,'DEMO-')) { return []; }
        $user=get_userdata($uid);
        if (!$user) { return []; }
        $email=$user->user_email;
        $tenant_receives=(bool)array_filter($to,static fn($recipient)=>strcasecmp($recipient,$email)===0);
        $active=BPM_Portal::can_access($h);
        if (!$active || BPM_Account::frequency($uid)!=='immediate') {
            if ($active && $tenant_receives) { BPM_Account::notify((int)$h->id); }
            $to=array_values(array_filter($to,static fn($recipient)=>strcasecmp($recipient,$email)!==0));
        }
        return $to;
    }
    public static function guard(): void {
        $action = sanitize_key($_REQUEST['action'] ?? '');
        $ticket_request = (wp_doing_ajax() && str_starts_with($action, 'wpsc_')) || isset($_REQUEST['wpsc_attachment']) || isset($_REQUEST['wpsc_rest_attachment']);
        if (!$ticket_request || BPM_Portal::manager()) { return; }
        $h = BPM_Portal::household();
        if (!BPM_Portal::can_access($h) || !self::enabled($h)) {
            BPM_Portal::headers();
            wp_send_json_error(['message'=>'Bitte mit einem aktivierten Mieterzugang anmelden.'], 403);
        }
        $ticket_id=absint($_REQUEST['ticket_id'] ?? $_REQUEST['ticket-id'] ?? 0);
        $attachment_id=absint($_REQUEST['wpsc_attachment'] ?? $_REQUEST['wpsc_rest_attachment'] ?? 0);
        if ($attachment_id) { $attachment=new WPSC_Attachment($attachment_id); if ($attachment->id && $attachment->ticket_id) { $ticket_id=(int)$attachment->ticket_id; } }
        if ($ticket_id) { $ticket=new WPSC_Ticket($ticket_id); if (!$ticket->id || self::owner_id($ticket)!==get_current_user_id()) { wp_send_json_error(['message'=>'Keine Berechtigung für dieses Ticket.'],403); } }
        foreach ($_FILES as $file) {
            if (!is_array($file) || is_array($file['name'] ?? null) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) { continue; }
            $tmp=$file['tmp_name'] ?? '';
            if (!is_uploaded_file($tmp)) { wp_send_json_error(['message'=>'Ungültiger Upload.'],400); }
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if (filesize($tmp)>8*1024*1024 || !in_array($mime,['application/pdf','image/jpeg','image/png'],true)) { wp_send_json_error(['message'=>'Nur PDF, JPG oder PNG bis 8 MB.'],400); }
            if (str_starts_with($mime,'image/')) { $size=@getimagesize($tmp); if(!$size || $size[0]*$size[1]>12000000) { wp_send_json_error(['message'=>'Das Foto darf höchstens 12 Megapixel haben.'],400); } }
        }
    }
    public static function link_ticket(object $ticket, ?object $household = null, ?int $legacy = null): void {
        global $wpdb;
        $uid = self::owner_id($ticket);
        $h = $household ?: ($uid ? BPM_Portal::household($uid) : null);
        if (!$h) { return; }
        $table = BPM_Portal::table('ticket_links');
        if (!$wpdb->get_var($wpdb->prepare("SELECT ticket_id FROM $table WHERE ticket_id=%d",$ticket->id))) {
            BPM_Portal::insert('ticket_links', ['ticket_id'=>$ticket->id,'household_id'=>$h->id,'legacy_thread_id'=>$legacy,'created_at'=>BPM_Portal::now()]);
        }
        $fields = get_option('bpm_supportcandy_fields', []);
        $values = ['reference'=>$h->reference, 'unit'=>$h->unit_label, 'building'=>''];
        if ($h->unit_id) { $unit=BPM_Portal::unit((int)$h->unit_id); $values['building']=$unit->building_label; }
        foreach ($fields as $name=>$slug) { $ticket->{$slug} = $values[$name] ?? ''; }
        $ticket->save();
    }
    public static function render(object $h): string {
        if (!BPM_Portal::can_access($h) || !self::enabled($h)) { return ''; }
        return '<section class="bpm-card bpm-tickets" id="bpm-messages"><h3>Ihre Anliegen</h3><p>Melden Sie ein Anliegen oder antworten Sie auf einen vorhandenen Vorgang. Hier sehen Sie den Bearbeitungsstand. Bei akuten Gefahren wenden Sie sich an die zuständigen Notdienste.</p>' . do_shortcode('[supportcandy]') . '</section>';
    }
    public static function admin_list(object $h): void {
        global $wpdb;
        echo '<section class="bpm-card"><h2>Tickets dieser Mietpartei</h2><p><a class="bpm-button" href="'.esc_url(admin_url('admin.php?page=wpsc-tickets')).'">Ticketverwaltung öffnen</a></p><ul>';
        $ids=$wpdb->get_col($wpdb->prepare('SELECT ticket_id FROM '.BPM_Portal::table('ticket_links').' WHERE household_id=%d ORDER BY ticket_id DESC LIMIT 100',$h->id));
        foreach($ids as $id){$t=new WPSC_Ticket((int)$id);if(!$t->id)continue;echo '<li><a href="'.esc_url(add_query_arg(['page'=>'wpsc-tickets','section'=>'ticket-list','ticket-id'=>$id],admin_url('admin.php'))).'">#'.(int)$id.' · '.esc_html($t->subject).'</a> · '.esc_html((string)($t->status->name ?? '')).'</li>';}
        echo '</ul></section>';
    }
}
