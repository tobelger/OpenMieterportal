<?php
defined('ABSPATH') || exit;

final class BPM_Portal {
    public static function table(string $name): string { global $wpdb; return $wpdb->prefix . 'bpm_' . $name; }
    public static function now(): string { return gmdate('Y-m-d H:i:s'); }
    public static function manager(): bool { return current_user_can('bpm_manage'); }
    public static function category_label(string $category): string {
        return ['vertrag' => 'Vertrag', 'abrechnung' => 'Abrechnung', 'mitteilung' => 'Mitteilung', 'sonstiges' => 'Sonstiges'][$category] ?? 'Dokument';
    }
    public static function url(): string { return get_permalink((int) get_option('bpm_page_id')) ?: home_url('/mieterportal/'); }
    public static function activation_url(): string { return get_permalink((int) get_option('bpm_activation_page_id')) ?: home_url('/activate/'); }
    public static function is_activation(): bool { return (int) get_queried_object_id() === (int) get_option('bpm_activation_page_id') && (int) get_option('bpm_activation_page_id') > 0; }
    public static function current_rent(int $hid): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('rent_terms') . ' WHERE household_id=%d AND effective_from<=%s ORDER BY effective_from DESC LIMIT 1', $hid, self::today())) ?: null;
    }
    public static function next_rent(int $hid): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('rent_terms') . ' WHERE household_id=%d AND effective_from>%s ORDER BY effective_from ASC LIMIT 1', $hid, self::today())) ?: null;
    }
    public static function euros(int $cents): string { return number_format($cents / 100, 2, ',', '.') . ' €'; }
    public static function today(): string { return (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d'); }
    public static function past_deadline(object $h): bool {
        try { $until = BPM_Domain::access_deadline($h->tenancy_end ?? null); }
        catch (Throwable $e) { return true; }
        return $until !== null && self::today() > $until;
    }
    public static function can_access(?object $h): bool { return $h && $h->status === 'active' && !self::past_deadline($h); }
    public static function expire_household(object $h): void {
        global $wpdb;
        if (!self::past_deadline($h) || !in_array($h->status, ['active', 'pending_email', 'invited'], true)) { return; }
        $changed = $wpdb->update(self::table('households'), ['status' => 'expired'], ['id' => $h->id, 'status' => $h->status]);
        if ($h->user_id) { WP_Session_Tokens::get_instance((int) $h->user_id)->destroy_all(); }
        if ($changed === 1) { self::event('access_expired', (int) $h->id); }
    }
    public static function expire_access(): void {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table('households') . " WHERE tenancy_end IS NOT NULL AND status IN ('active','pending_email','invited')");
        foreach ($rows as $h) { self::expire_household($h); }
    }
    public static function deactivate(): void { wp_clear_scheduled_hook('bpm_expire_access'); wp_clear_scheduled_hook('bpm_account_digest'); }
    public static function boot(): void {
        add_action('init', static function () { if (get_option('bpm_schema_version') !== BPM_VERSION) { self::activate(); } }, 1);
        add_action('bpm_expire_access', [self::class, 'expire_access']);
        add_action('init', static function () {
            $h = self::household();
            if ($h && !self::manager() && self::past_deadline($h)) { self::expire_household($h); wp_logout(); }
        }, 20);
        add_action('template_redirect', static function () { if (self::is_activation() && is_user_logged_in()) { wp_safe_redirect(self::url()); exit; } }, 0);
        add_action('template_redirect', static function () {
            if ((int) get_option('bpm_legacy_login_page') > 0 && is_page((int) get_option('bpm_legacy_login_page')) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') { wp_safe_redirect(self::url(), 301); exit; }
        }, 1);
        add_filter('wp_nav_menu_items', static function ($items, $args) {
            if (($args->theme_location ?? '') === 'secondary-menu') {
                $items = preg_replace('~<li\b[^>]*>\s*<a\b[^>]*href=["\'][^"\']*/(?:login|admin-login)/?["\'][^>]*>.*?</a>\s*</li>~is', '', $items);
            }
            return $items;
        }, 100, 2);
        add_shortcode('belger_admin_login', static function () {
            if (is_user_logged_in()) { return '<div class="bpm-portal"><section class="bpm-card"><h2>Verwaltungszugang</h2><a class="bpm-button" href="' . esc_url(self::manager() ? admin_url('admin.php?page=belger-mieterportal') : self::url()) . '">Verwaltung öffnen</a></section></div>'; }
            return '<div class="bpm-portal"><section class="bpm-card"><h2>Verwaltungszugang</h2>' . wp_login_form(['echo'=>false,'redirect'=>admin_url('admin.php?page=belger-mieterportal'),'label_username'=>'E-Mail-Adresse oder Benutzername','label_password'=>'Passwort','label_log_in'=>'Anmelden']) . '</section></div>';
        });
        add_shortcode('belger_mieterportal', [self::class, 'render']);
        // Handle our signed endpoint before legacy child-theme admin redirects.
        add_action('admin_init', static function () {
            if (($GLOBALS['pagenow'] ?? '') === 'admin-post.php' && ($_REQUEST['action'] ?? '') === 'bpm_action') { self::dispatch(); exit; }
        }, -100);
        add_action('admin_post_bpm_action', [self::class, 'dispatch']);
        add_action('admin_post_nopriv_bpm_action', [self::class, 'dispatch']);
        add_action('after_password_reset', [self::class, 'verified'], 10, 1);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        add_action('template_redirect', [self::class, 'private_headers']);
        add_action('wp_head', static function () {
            if (is_singular() && (has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'belger_mieterportal') || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'belger_admin_login'))) {
                // Strip the bearer invitation from the address bar before theme scripts run.
                echo '<script>(()=>{const p=new URLSearchParams(location.hash.slice(1));const t=p.get("einladung");if(t&&/^(?:[A-HJ-NP-Z2-9]{16}|[a-f0-9]{64})$/i.test(t)){history.replaceState(null,"",location.pathname+location.search);document.addEventListener("DOMContentLoaded",()=>{const f=document.getElementById("bpm-code");if(f){f.value=t;document.getElementById("bpm-activation")?.scrollIntoView({block:"start"});}},{once:true});}})();</script>';
            }
        }, -1000);
        add_filter('login_redirect', static function ($redirect, $requested, $user) {
            return $user instanceof WP_User && in_array('bpm_tenant', $user->roles, true) ? self::url() : $redirect;
        }, 100, 3);
        add_action('admin_init', static function () {
            if (self::household() && !self::manager() && ($GLOBALS['pagenow'] ?? '') !== 'admin-post.php' && !wp_doing_ajax()) {
                wp_safe_redirect(self::url()); exit;
            }
        });
        add_filter('show_admin_bar', static function ($show) { return self::household() && !self::manager() ? false : $show; });
        add_filter('wp_authenticate_user', static function ($user) {
            if ($user instanceof WP_User) {
                $h = self::household($user->ID);
                if ($h && !self::can_access($h)) {
                    return new WP_Error('bpm_account_unavailable', 'Der Mieterzugang ist noch nicht aktiviert, gesperrt oder nach Ende der Zugangsfrist abgelaufen. Bitte wenden Sie sich an die Verwaltung.');
                }
            }
            return $user;
        });
    }

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();
        $definitions = [
            'ticket_links' => "ticket_id bigint unsigned NOT NULL,\nhousehold_id bigint unsigned NOT NULL,\nlegacy_thread_id bigint unsigned DEFAULT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (ticket_id),\nUNIQUE KEY legacy_thread_id (legacy_thread_id),\nKEY household_id (household_id)",
            'buildings' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nreference varchar(100) NOT NULL,\nlabel varchar(190) NOT NULL,\naddress varchar(190) NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY reference (reference)",
            'units' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nbuilding_id bigint unsigned NOT NULL,\nreference varchar(100) NOT NULL,\nlabel varchar(190) NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY reference (reference),\nKEY building_id (building_id)",
            'households' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nreference varchar(100) NOT NULL,\nlabel varchar(190) NOT NULL,\nunit_label varchar(190) NOT NULL,\nunit_id bigint unsigned DEFAULT NULL,\nuser_id bigint unsigned DEFAULT NULL,\nexpected_email varchar(190) NOT NULL DEFAULT '',\nstatus varchar(30) NOT NULL DEFAULT 'invited',\nregistration_lock varchar(64) NOT NULL DEFAULT '',\ncreated_at datetime NOT NULL,\nactivated_at datetime DEFAULT NULL,\ntenancy_start date DEFAULT NULL,\ntenancy_end date DEFAULT NULL,\nnotice_received date DEFAULT NULL,\ntermination_by varchar(20) NOT NULL DEFAULT '',\nPRIMARY KEY  (id),\nUNIQUE KEY reference (reference),\nUNIQUE KEY user_id (user_id)",
            'rent_terms' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nhousehold_id bigint unsigned NOT NULL,\neffective_from date NOT NULL,\nrent_cents bigint unsigned NOT NULL,\nadvance_cents bigint unsigned NOT NULL,\ncreated_at datetime NOT NULL,\nupdated_at datetime NOT NULL,\nactor_id bigint unsigned NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY household_effective (household_id,effective_from)",
            'invites' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nhousehold_id bigint unsigned NOT NULL,\ntoken_hash char(64) NOT NULL,\nexpires_at datetime NOT NULL,\nstate varchar(20) NOT NULL DEFAULT 'open',\ncreated_at datetime NOT NULL,\nused_at datetime DEFAULT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY token_hash (token_hash),\nKEY household_id (household_id)",
            'documents' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nhousehold_id bigint unsigned NOT NULL,\ntitle varchar(190) NOT NULL,\ncategory varchar(60) NOT NULL,\nstorage_key char(48) NOT NULL,\nfilename varchar(190) NOT NULL,\nmime varchar(60) NOT NULL,\nbytes bigint unsigned NOT NULL,\nsha256 char(64) NOT NULL,\nfirst_download_at datetime NULL,\nfirst_download_user_id bigint unsigned NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY household_id (household_id)",
            'threads' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nhousehold_id bigint unsigned NOT NULL,\nsubject varchar(190) NOT NULL,\nkind varchar(30) NOT NULL,\nstatus varchar(30) NOT NULL DEFAULT 'offen',\ncreated_at datetime NOT NULL,\nupdated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY household_id (household_id)",
            'messages' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nthread_id bigint unsigned NOT NULL,\nuser_id bigint unsigned NOT NULL,\nbody text NOT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY thread_id (thread_id)",
            'meter_points' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nunit_id bigint unsigned NOT NULL,\nlabel varchar(190) NOT NULL,\nunit varchar(20) NOT NULL,\nlegacy_meter_id bigint unsigned DEFAULT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY legacy_meter_id (legacy_meter_id),\nKEY unit_id (unit_id)",
            'meters' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nhousehold_id bigint unsigned NOT NULL DEFAULT 0,\npoint_id bigint unsigned DEFAULT NULL,\nunit_id bigint unsigned DEFAULT NULL,\ninstalled_on date DEFAULT NULL,\nremoved_on date DEFAULT NULL,\nnumber varchar(100) NOT NULL,\nlabel varchar(190) NOT NULL,\nunit varchar(20) NOT NULL,\nactive tinyint NOT NULL DEFAULT 1,\nPRIMARY KEY  (id),\nUNIQUE KEY number (number),\nKEY point_id (point_id),\nKEY household_id (household_id)",
            'readings' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nhousehold_id bigint unsigned NOT NULL,\nmeter_id bigint unsigned NOT NULL,\nvalue decimal(14,3) NOT NULL,\nread_at date NOT NULL,\nsource varchar(30) NOT NULL DEFAULT 'mieter',\nstatus varchar(30) NOT NULL DEFAULT 'ungeprueft',\nphoto_key char(48) NOT NULL DEFAULT '',\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY household_id (household_id),\nKEY meter_id (meter_id)",
            'preferences' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nhousehold_id bigint unsigned NOT NULL,\nuser_id bigint unsigned NOT NULL,\nchoice varchar(30) NOT NULL,\nversion varchar(60) NOT NULL,\nwording text NOT NULL,\nauthority_confirmed tinyint NOT NULL DEFAULT 0,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY household_id (household_id)",
            'events' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nhousehold_id bigint unsigned NOT NULL DEFAULT 0,\nactor_id bigint unsigned NOT NULL DEFAULT 0,\nkind varchar(60) NOT NULL,\nobject_id bigint unsigned NOT NULL DEFAULT 0,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY kind (kind)"
        ];
        foreach ($definitions as $name => $definition) { dbDelta('CREATE TABLE ' . self::table($name) . " (\n$definition\n) $cc;"); }
        add_role('bpm_tenant', 'Mietpartei', ['read' => true]);
        $admin = get_role('administrator');
        if ($admin) { $admin->add_cap('bpm_manage'); }
        if (!get_option('bpm_page_id')) {
            $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'private', 'post_title' => 'Mieterportal', 'post_name' => 'mieterportal', 'post_content' => '[belger_mieterportal]', 'comment_status' => 'closed']);
            if ($id && !is_wp_error($id)) { update_option('bpm_page_id', $id); }
        }
        if (!get_option('bpm_admin_login_page_id')) {
            $admin_page = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Verwaltungszugang','post_name'=>'admin-login','post_content'=>'[belger_admin_login]','comment_status'=>'closed']);
            if ($admin_page && !is_wp_error($admin_page)) { update_option('bpm_admin_login_page_id',$admin_page); update_post_meta($admin_page,'_et_pb_page_layout','et_full_width_page'); }
            $widgets = get_option('widget_text', []);
            foreach ($widgets as $key => $widget) {
                if (is_array($widget) && str_contains($widget['text'] ?? '', home_url('/login/'))) {
                    $text = str_replace(home_url('/login/'), self::url(), $widget['text']);
                    $text = preg_replace('/>\s*Login\s*</', '>Mieterportal<', $text);
                    $widgets[$key]['text'] = $text . '<p><a href="' . esc_url(home_url('/admin-login/')) . '">Verwaltungszugang</a></p>';
                }
            }
            update_option('widget_text',$widgets);
        }
        if (!wp_next_scheduled('bpm_expire_access')) { wp_schedule_event(time() + 60, 'hourly', 'bpm_expire_access'); }
        self::migrate_units();
        if (!BPM_Meters::migrate()) { return; }
        BPM_Account::install();
        update_option('bpm_schema_version', BPM_VERSION);
    }

    /** Preserve contract IDs, accounts, documents and consent during the additive migration. */
    public static function migrate_units(): void {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table('households') . ' WHERE unit_id IS NULL');
        foreach ($rows as $h) {
            // Only split an explicitly structured legacy address; unknown records require manual assignment.
            $parts = explode(' · ', $h->unit_label, 2);
            if (count($parts) !== 2) { continue; }
            $bref = 'B-' . substr(hash('sha256', $parts[0]), 0, 24);
            $bid = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('buildings') . ' WHERE reference=%s', $bref));
            if (!$bid) {
                self::insert('buildings', ['reference'=>$bref, 'label'=>$parts[0], 'address'=>$parts[0]]);
                $bid = $wpdb->insert_id;
            }
            $uref = 'W-' . substr(hash('sha256', $h->unit_label), 0, 24);
            $uid = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('units') . ' WHERE reference=%s', $uref));
            if (!$uid) {
                self::insert('units', ['building_id'=>$bid, 'reference'=>$uref, 'label'=>$parts[1]]);
                $uid = $wpdb->insert_id;
            }
            if ($wpdb->update(self::table('households'), ['unit_id'=>$uid], ['id'=>$h->id]) === false) { throw new RuntimeException('Wohnungsübernahme fehlgeschlagen.'); }
            if ($wpdb->update(self::table('meters'), ['unit_id'=>$uid], ['household_id'=>$h->id, 'unit_id'=>null]) === false) { throw new RuntimeException('Zählerübernahme fehlgeschlagen.'); }
        }
    }
    public static function unit(int $id): object {
        global $wpdb;
        $u = $wpdb->get_row($wpdb->prepare('SELECT u.*, b.address, b.label AS building_label FROM ' . self::table('units') . ' u JOIN ' . self::table('buildings') . ' b ON b.id=u.building_id WHERE u.id=%d', $id));
        if (!$u) { throw new InvalidArgumentException('Bitte eine vorhandene Wohnung auswählen.'); }
        return $u;
    }
    public static function meters_for(object $h): array {
        global $wpdb;
        if (empty($h->unit_id) || empty($h->tenancy_start) || $h->tenancy_start > self::today() || (!empty($h->tenancy_end) && $h->tenancy_end < self::today())) { return []; }
        return $wpdb->get_results($wpdb->prepare('SELECT m.* FROM ' . self::table('meters') . ' m WHERE m.unit_id=%d AND m.active=1 AND (m.installed_on IS NULL OR m.installed_on<=%s) AND (m.removed_on IS NULL OR m.removed_on>=%s) AND NOT EXISTS (SELECT 1 FROM '.self::table('meters').' n WHERE m.point_id IS NOT NULL AND n.point_id=m.point_id AND n.active=1 AND (n.installed_on IS NULL OR n.installed_on<=%s) AND (n.removed_on IS NULL OR n.removed_on>=%s) AND (COALESCE(n.installed_on,\'0000-01-01\')>COALESCE(m.installed_on,\'0000-01-01\') OR (COALESCE(n.installed_on,\'0000-01-01\')=COALESCE(m.installed_on,\'0000-01-01\') AND n.id>m.id))) ORDER BY m.label,m.number', $h->unit_id, self::today(), self::today(), self::today(), self::today()));
    }
    public static function meter_for_reading(int $id, object $h, string $date, bool $manager = false): object {
        global $wpdb;
        $m = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('meters') . ' WHERE id=%d', $id));
        if (!$m || empty($h->unit_id) || (int)$m->unit_id !== (int)$h->unit_id || (!$manager && !$m->active) || empty($h->tenancy_start) || $date < $h->tenancy_start || (!empty($h->tenancy_end) && $date > $h->tenancy_end) || (!empty($m->installed_on) && $date < $m->installed_on) || (!empty($m->removed_on) && $date > $m->removed_on)) {
            throw new InvalidArgumentException('Dieser Zähler ist für Ihre Wohnung und diesen Mietzeitraum nicht verfügbar.');
        }
        return $m;
    }

    public static function household(?int $user_id = null): ?object {
        global $wpdb;
        $user_id = $user_id ?? get_current_user_id();
        if (!$user_id) { return null; }
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('households') . ' WHERE user_id=%d', $user_id)) ?: null;
    }
    public static function event(string $kind, int $household = 0, int $object = 0): void {
        global $wpdb;
        $wpdb->insert(self::table('events'), ['household_id' => $household, 'actor_id' => get_current_user_id(), 'kind' => $kind, 'object_id' => $object, 'created_at' => self::now()]);
    }
    public static function private_headers(): void {
        if (is_singular() && (has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'belger_mieterportal') || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'belger_admin_login'))) { self::headers(); }
    }
    public static function headers(): void {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        // No third-party scripts, fonts, pixels, frames or QR services on sensitive screens.
        // Applies only to this portal and its own form endpoints, not the public website.
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'");
    }
    public static function assets(): void {
        if (is_singular() && (has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'belger_mieterportal') || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'belger_admin_login'))) {
            wp_enqueue_style('bpm-portal', plugins_url('assets/portal.css', BPM_FILE), [], BPM_VERSION);
            wp_enqueue_script('bpm-portal', plugins_url('assets/portal.js', BPM_FILE), [], BPM_VERSION, true);
        }
    }
    public static function verified(WP_User $user): void {
        global $wpdb;
        $household = self::household($user->ID);
        if ($household && self::past_deadline($household)) { self::expire_household($household); return; }
        if ($household && $household->status === 'pending_email' && in_array('bpm_tenant', $user->roles, true)) {
            $wpdb->update(self::table('households'), ['status' => 'active', 'activated_at' => self::now()], ['id' => $household->id, 'status' => 'pending_email']);
            self::event('account_verified', (int) $household->id);
        }
    }
    public static function activation_mail(int $user_id): bool {
        $user = get_userdata($user_id);
        if (!$user) { return false; }
        $key = get_password_reset_key($user);
        if (is_wp_error($key)) { return false; }
        $url = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($user->user_login), 'login');
        $ok = wp_mail($user->user_email, 'Mieterportal: E-Mail bestätigen und Passwort festlegen', "Bitte legen Sie über diesen Link das Passwort für den gemeinsamen Zugang Ihrer Mietpartei fest:\n\n$url\n\nDanach erreichen Sie das Portal unter:\n" . self::url() . "\n\nWenn Sie diese Registrierung nicht veranlasst haben, verwenden Sie den Link nicht und kontaktieren Sie die Verwaltung.");
        self::event($ok ? 'activation_mail_sent' : 'mail_failed', (int) (self::household($user_id)->id ?? 0));
        return $ok;
    }
    public static function notify(int $household_id, bool $to_tenant): void {
        if ($to_tenant) { BPM_Account::notify($household_id); return; }
        global $wpdb;
        $household = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('households') . ' WHERE id=%d', $household_id));
        $user = $household && $household->user_id ? get_userdata((int) $household->user_id) : null;
        $email = $to_tenant ? ($user->user_email ?? '') : get_option('admin_email');
        if ($email && !wp_mail($email, 'Neue Information im Mieterportal', "Im Mieterportal liegt eine neue Information bereit. Bitte melden Sie sich an:\n" . self::url() . "\n\nDiese E-Mail enthält aus Datenschutzgründen keine Nachrichtentexte oder Dokumente.")) {
            self::event('mail_failed', $household_id);
        }
    }
    public static function text(string $key, int $max = 5000): string {
        $value = sanitize_textarea_field(wp_unslash((string) ($_POST[$key] ?? '')));
        if ($value === '' || mb_strlen($value) > $max) { throw new InvalidArgumentException('Bitte alle Pflichtfelder vollständig und in zulässiger Länge ausfüllen.'); }
        return $value;
    }
    public static function form(string $operation): void {
        echo '<input type="hidden" name="action" value="bpm_action"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
        wp_nonce_field('bpm_' . $operation);
    }
    public static function dispatch(): void {
        global $wpdb;
        self::headers();
        $operation = sanitize_key($_REQUEST['operation'] ?? '');
        try {
            if ($operation === 'download' && $_SERVER['REQUEST_METHOD'] === 'GET') { self::download(); exit; }
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new RuntimeException('Diese Aktion benötigt eine Formularübermittlung.'); }
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'bpm_' . $operation)) { throw new RuntimeException('Die Sitzung ist abgelaufen. Bitte laden Sie das Formular neu.'); }
            if ($operation === 'activate') {
                if (get_post_status((int) get_option('bpm_page_id')) !== 'publish') {
                    throw new RuntimeException('Das Mieterportal ist noch nicht freigeschaltet.');
                }
                self::claim_invite(); exit;
            }
            if (in_array($operation, ['building', 'unit', 'unit_update', 'unit_meter_save', 'unit_meter_assign', 'point_save', 'device_save', 'device_exchange', 'household', 'tenancy', 'rent', 'invite', 'resend', 'document', 'meter', 'admin_reply', 'settings', 'review_reading', 'admin_reading', 'access'], true)) {
                if (!self::manager()) { throw new RuntimeException('Keine Berechtigung.'); }
                BPM_Admin::action($operation); exit;
            }
            $h = self::household();
            if (!self::can_access($h)) { throw new RuntimeException('Bitte melden Sie sich mit einem aktivierten Mieterzugang an.'); }
            if (BPM_Account::action($operation, $h)) { return; }
            $hid = (int) $h->id;
            if (in_array($operation, ['message','reply'], true) && BPM_Tickets::enabled($h)) { throw new InvalidArgumentException('Bitte verwenden Sie die Ticketfunktion im Portal.'); }
            if ($operation === 'message') {
                $kind = sanitize_key($_POST['kind'] ?? 'anliegen');
                if (!in_array($kind, ['anliegen', 'schaden', 'stammdaten'], true)) { throw new InvalidArgumentException('Ungültiger Nachrichtentyp.'); }
                $subject = self::text('subject', 160);
                $body = self::text('body');
                self::insert('threads', ['household_id' => $hid, 'subject' => $subject, 'kind' => $kind, 'status' => 'offen', 'created_at' => self::now(), 'updated_at' => self::now()]);
                $thread = (int) $wpdb->insert_id;
                self::insert('messages', ['thread_id' => $thread, 'user_id' => get_current_user_id(), 'body' => $body, 'created_at' => self::now()]);
                self::event('message_created', $hid, $thread);
                self::notify($hid, false);
            } elseif ($operation === 'reply') {
                $thread = self::owned('threads', absint($_POST['thread_id'] ?? 0), $hid);
                self::insert('messages', ['thread_id' => $thread->id, 'user_id' => get_current_user_id(), 'body' => self::text('body'), 'created_at' => self::now()]);
                $wpdb->update(self::table('threads'), ['status' => 'offen', 'updated_at' => self::now()], ['id' => $thread->id]);
                self::event('message_created', $hid, (int) $thread->id);
                self::notify($hid, false);
            } elseif ($operation === 'reading') {
                $date = BPM_Domain::date((string) ($_POST['read_at'] ?? ''));
                $meter = self::meter_for_reading(absint($_POST['meter_id'] ?? 0), $h, $date);
                if (!(int) $meter->active) { throw new InvalidArgumentException('Dieser Zähler ist nicht mehr aktiv.'); }
                $value = BPM_Domain::reading((string) ($_POST['value'] ?? ''));
                $date = BPM_Domain::date((string) ($_POST['read_at'] ?? ''));
                $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('readings') . ' WHERE meter_id=%d AND read_at=%s AND value=%s', $meter->id, $date, $value));
                if ($exists) { throw new InvalidArgumentException('Dieser Wert wurde für das angegebene Datum bereits gemeldet.'); }
                $photo = '';
                if (!empty($_FILES['photo']['name'])) { $photo = BPM_Storage::upload($_FILES['photo'], false)['storage_key']; }
                try {
                    self::insert('readings', ['household_id' => $hid, 'meter_id' => $meter->id, 'value' => $value, 'read_at' => $date, 'source' => 'mieter', 'status' => 'ungeprueft', 'photo_key' => $photo, 'created_at' => self::now()]);
                } catch (Throwable $e) { if ($photo) { @unlink(BPM_Storage::path($photo)); } throw $e; }
                self::event('reading_created', $hid, (int) $wpdb->insert_id);
                self::notify($hid, false);
            } elseif ($operation === 'preference') {
                $choice = sanitize_key($_POST['choice'] ?? '');
                if (!in_array($choice, ['portal', 'widerrufen'], true)) { throw new InvalidArgumentException('Bitte eine gültige Auswahl treffen.'); }
                if ($choice === 'portal' && (!get_option('bpm_consent_enabled') || empty($_POST['authority']) || empty($_POST['agree']))) {
                    throw new InvalidArgumentException('Die Vereinbarung ist noch nicht freigegeben oder die erforderlichen Bestätigungen fehlen.');
                }
                self::insert('preferences', ['household_id' => $hid, 'user_id' => get_current_user_id(), 'choice' => $choice, 'version' => BPM_Domain::CONSENT_VERSION, 'wording' => BPM_Domain::CONSENT_TEXT, 'authority_confirmed' => $choice === 'portal' ? 1 : 0, 'created_at' => self::now()]);
                self::event('communication_' . $choice, $hid);
                self::notify($hid, false);
            } else { throw new InvalidArgumentException('Unbekannte Aktion.'); }
            $return_section = match ($operation) { 'reading' => 'bpm-readings', 'preference' => 'bpm-preference', default => 'bpm-messages' };
            wp_safe_redirect(add_query_arg('bpm_done', '1', self::url()) . '#' . $return_section); exit;
        } catch (Throwable $e) {
            $message = $e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : 'Die Aktion konnte nicht abgeschlossen werden. Bitte wenden Sie sich an die Verwaltung.';
            wp_die(esc_html($message) . '<p><a href="' . esc_url(self::url()) . '">Zurück zum Mieterportal</a></p>', 'Mieterportal', ['response' => 400]);
        }
    }
    public static function insert(string $table, array $data): void {
        global $wpdb;
        if ($wpdb->insert(self::table($table), $data) === false) { throw new RuntimeException('Die Angaben konnten nicht gespeichert werden. Bitte kontaktieren Sie die Verwaltung.'); }
    }
    public static function owned(string $table, int $id, int $hid, bool $manager = false): object {
        global $wpdb;
        if (!in_array($table, ['threads', 'meters', 'documents', 'readings'], true)) { throw new RuntimeException('Ungültiger Datentyp.'); }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table($table) . ' WHERE id=%d', $id));
        if (!$row || !BPM_Domain::allowed((int) $row->household_id, $hid, $manager)) { throw new RuntimeException('Dieser Eintrag ist nicht verfügbar.'); }
        return $row;
    }
    public static function claim_invite(): void {
        global $wpdb;
        $token = BPM_Domain::normalize_invitation((string) ($_POST['invite_code'] ?? ''));
        $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
        $rate = 'bpm_reg_' . hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), wp_salt('nonce'));
        $count = (int) get_transient($rate);
        if ($count >= 8) { throw new RuntimeException('Zu viele Versuche. Bitte versuchen Sie es in 15 Minuten erneut.'); }
        set_transient($rate, $count + 1, 15 * MINUTE_IN_SECONDS);
        if (!BPM_Domain::valid_invitation($token) || !is_email($email)) { throw new InvalidArgumentException('Bitte einen gültigen Einladungscode und eine gültige E-Mail-Adresse angeben.'); }
        $invite = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('invites') . ' WHERE token_hash=%s AND state=%s AND expires_at>%s', BPM_Domain::token_hash($token), 'open', self::now()));
        $h = $invite ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('households') . ' WHERE id=%d', $invite->household_id)) : null;
        if (!$h || self::past_deadline($h) || $h->user_id || ($h->expected_email && strcasecmp($h->expected_email, $email) !== 0) || email_exists($email)) { throw new InvalidArgumentException('Die Einladung kann mit diesen Angaben nicht verwendet werden. Bitte wenden Sie sich an die Verwaltung.'); }
        $lock = BPM_Domain::token();
        $claimed = $wpdb->query($wpdb->prepare('UPDATE ' . self::table('households') . ' SET registration_lock=%s WHERE id=%d AND user_id IS NULL AND registration_lock=%s AND status=%s', $lock, $h->id, '', 'invited'));
        if ($claimed !== 1) { throw new RuntimeException('Die Einladung wird bereits verarbeitet oder wurde verwendet.'); }
        $user_id = 0;
        try {
            $taken = $wpdb->query($wpdb->prepare('UPDATE ' . self::table('invites') . ' SET state=%s WHERE id=%d AND state=%s AND expires_at>%s', 'claimed', $invite->id, 'open', self::now()));
            if ($taken !== 1) { throw new RuntimeException('Die Einladung ist nicht mehr gültig.'); }
            $user_id = wp_insert_user(['user_login' => 'mietpartei_' . $h->id . '_' . substr(BPM_Domain::token(), 0, 8), 'user_pass' => BPM_Domain::token(), 'user_email' => $email, 'display_name' => $h->label, 'role' => 'bpm_tenant', 'show_admin_bar_front' => 'false']);
            if (is_wp_error($user_id)) { throw new RuntimeException('Der Zugang konnte nicht angelegt werden. Bitte kontaktieren Sie die Verwaltung.'); }
            $updated = $wpdb->update(self::table('households'), ['user_id' => $user_id, 'status' => 'pending_email', 'registration_lock' => ''], ['id' => $h->id, 'registration_lock' => $lock]);
            if ($updated !== 1) { throw new RuntimeException('Die Zuordnung konnte nicht gespeichert werden. Bitte kontaktieren Sie die Verwaltung.'); }
            $wpdb->update(self::table('invites'), ['state' => 'used', 'used_at' => self::now()], ['id' => $invite->id]);
        } catch (Throwable $e) {
            // Fail closed: if a user was created, keep the claimed invitation for administrator review.
            if (!is_int($user_id) || $user_id === 0) {
                $wpdb->update(self::table('invites'), ['state' => 'open'], ['id' => $invite->id, 'state' => 'claimed']);
                $wpdb->update(self::table('households'), ['registration_lock' => ''], ['id' => $h->id, 'registration_lock' => $lock]);
            }
            throw $e;
        }
        $sent = self::activation_mail($user_id);
        self::event('invite_claimed', (int) $h->id, (int) $invite->id);
        $message = $sent ? 'Bitte prüfen Sie Ihr E-Mail-Postfach. Über den Bestätigungslink legen Sie Ihr Passwort fest und aktivieren den Zugang Ihrer Mietpartei.' : 'Der Zugang wurde vorbereitet, die Aktivierungsmail konnte aber nicht versendet werden. Bitte kontaktieren Sie die Verwaltung.';
        wp_die(esc_html($message) . '<p><a href="' . esc_url(self::url()) . '">Zum Mieterportal</a></p>', 'Registrierung', ['response' => 200]);
    }
    public static function download_url(int $id, bool $photo = false): string {
        return wp_nonce_url(add_query_arg(['action' => 'bpm_action', 'operation' => 'download', 'id' => $id, 'photo' => $photo ? 1 : 0], admin_url('admin-post.php')), 'bpm_download_' . ($photo ? 'photo_' : 'doc_') . $id);
    }
    public static function download(): void {
        $hid = (int) (self::household()->id ?? 0);
        if (!self::manager() && !self::can_access(self::household())) { throw new RuntimeException('Keine Berechtigung.'); }
        $id = absint($_GET['id'] ?? 0);
        $photo = !empty($_GET['photo']);
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'bpm_download_' . ($photo ? 'photo_' : 'doc_') . $id)) { throw new RuntimeException('Der Downloadlink ist abgelaufen. Bitte laden Sie die Portalseite neu.'); }
        $row = self::owned($photo ? 'readings' : 'documents', $id, $hid, self::manager());
        $path = $photo ? BPM_Storage::path($row->photo_key) : null;
        $verified = null;
        if (!$photo) {
            try { $verified = BPM_Storage::verified_document($row->storage_key, $row->sha256, (int) $row->bytes); }
            catch (RuntimeException $e) { self::event('document_integrity_failed', (int) $row->household_id, $id); throw $e; }
        }
        $name = $photo ? 'zaehlerfoto.jpg' : $row->filename;
        header('Content-Type: ' . ($photo ? 'image/jpeg' : 'application/pdf'));
        header('Content-Security-Policy: sandbox');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . ($photo ? filesize($path) : (int) $row->bytes));
        if (!$photo) { header('Content-Digest: sha-256=:' . base64_encode(hex2bin($row->sha256)) . ':'); }
        self::event('file_download', (int) $row->household_id, $id);
        if ($photo) { $sent = readfile($path); }
        else { try { $sent = fpassthru($verified); } finally { fclose($verified); } }
        if (!$photo && !self::manager() && $sent !== false && $sent === (int) $row->bytes && !connection_aborted()) {
            global $wpdb;
            $changed = $wpdb->query($wpdb->prepare('UPDATE ' . self::table('documents') . ' SET first_download_at=%s, first_download_user_id=%d WHERE id=%d AND household_id=%d AND first_download_at IS NULL', self::now(), get_current_user_id(), $id, $hid));
            if ($changed === 1) { self::event('document_first_download', $hid, $id); }
        }
    }
    public static function render($atts = []): string {
        global $wpdb;
        ob_start();
        $atts = shortcode_atts(['header'=>'on'], (array)$atts, 'belger_mieterportal');
        $show_header = $atts['header'] !== 'off';
        require __DIR__ . '/view-portal.php';
        return (string) ob_get_clean();
    }
}
