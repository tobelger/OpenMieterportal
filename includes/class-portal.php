<?php

defined('ABSPATH') || exit;

final class BPM_Portal
{
    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bpm_' . $name;
    }
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
    public static function manager(): bool
    {
        return current_user_can('bpm_manage');
    }
    public static function category_label(string $category): string
    {
        return ['vertrag' => __('Vertrag', 'openmieterportal'), 'abrechnung' => __('Abrechnung', 'openmieterportal'), 'mitteilung' => __('Mitteilung', 'openmieterportal'), 'sonstiges' => __('Sonstiges', 'openmieterportal')][$category] ?? __('Dokument', 'openmieterportal');
    }
    public static function url(): string
    {
        return get_permalink((int) get_option('bpm_page_id')) ?: home_url('/mieterportal/');
    }
    public static function activation_url(): string
    {
        return get_permalink((int) get_option('bpm_activation_page_id')) ?: home_url('/activate/');
    }
    public static function is_activation(): bool
    {
        return (int) get_queried_object_id() === (int) get_option('bpm_activation_page_id') && (int) get_option('bpm_activation_page_id') > 0;
    }
    public static function current_rent(int $hid): ?object
    {
        global $wpdb;
        return BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE household_id=%d AND effective_from<=%s ORDER BY effective_from DESC LIMIT 1', self::table('rent_terms'), $hid, self::today())) ?: null;
    }
    public static function next_rent(int $hid): ?object
    {
        global $wpdb;
        return BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE household_id=%d AND effective_from>%s ORDER BY effective_from ASC LIMIT 1', self::table('rent_terms'), $hid, self::today())) ?: null;
    }
    public static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' €';
    }
    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    }
    public static function past_deadline(object $h): bool
    {
        try {
            $until = BPM_Domain::access_deadline($h->tenancy_end ?? null);
        } catch (Throwable $e) {
            return true;
        }
        return $until !== null && self::today() > $until;
    }
    public static function can_access(?object $h): bool
    {
        return $h && $h->status === 'active' && !self::past_deadline($h);
    }
    public static function expire_household(object $h): void
    {
        global $wpdb;
        if (!self::past_deadline($h) || !in_array($h->status, ['active', 'pending_email', 'invited'], true)) {
            return;
        }
        $changed = BPM_Data::update(self::table('households'), ['status' => 'expired'], ['id' => $h->id, 'status' => $h->status]);
        if ($h->user_id) {
            WP_Session_Tokens::get_instance((int) $h->user_id)->destroy_all();
        }
        if ($changed === 1) {
            self::event('access_expired', (int) $h->id);
        }
    }
    public static function expire_access(): void
    {
        global $wpdb;
        $rows = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE tenancy_end IS NOT NULL AND status IN (\'active\',\'pending_email\',\'invited\')', self::table('households')));
        foreach ($rows as $h) {
            self::expire_household($h);
        }
    }
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('bpm_expire_access');
        wp_clear_scheduled_hook('bpm_account_digest');
    }
    public static function boot(): void
    {
        add_action('init', static function () {
            if (get_option('bpm_schema_version') !== BPM_VERSION) {
                self::activate();
            }
        }, 1);
        add_action('bpm_expire_access', [self::class, 'expire_access']);
        add_action('init', static function () {
            $h = self::household();
            if ($h && !self::manager() && self::past_deadline($h)) {
                self::expire_household($h);
                wp_logout();
            }
        }, 20);
        add_action('template_redirect', static function () {
            if (self::is_activation() && is_user_logged_in()) {
                wp_safe_redirect(self::url());
                exit;
            }
        }, 0);
        add_action('template_redirect', static function () {
            if ((int) get_option('bpm_legacy_login_page') > 0 && is_page((int) get_option('bpm_legacy_login_page')) && sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
                wp_safe_redirect(self::url(), 301);
                exit;
            }
        }, 1);
        add_shortcode('belger_admin_login', static function () {
            if (is_user_logged_in()) {
                return '<div class="bpm-portal"><section class="bpm-card"><h2>' . esc_html__('Verwaltungszugang', 'openmieterportal') . '</h2><a class="bpm-button" href="' . esc_url(self::manager() ? admin_url('admin.php?page=belger-mieterportal') : self::url()) . ('">' . esc_html__('Verwaltung öffnen', 'openmieterportal') . '</a></section></div>');
            }
            return '<div class="bpm-portal"><section class="bpm-card"><h2>' . esc_html__('Verwaltungszugang', 'openmieterportal') . '</h2>' . wp_login_form(['echo' => false,'redirect' => admin_url('admin.php?page=belger-mieterportal'),'label_username' => 'E-Mail-Adresse oder Benutzername','label_password' => __('Passwort', 'openmieterportal'),'label_log_in' => __('Anmelden', 'openmieterportal')]) . '</section></div>';
        });
        add_shortcode('belger_mieterportal', [self::class, 'render']); // Existing installations.
        add_shortcode('openmieterportal', [self::class, 'render']);
        // Handle our signed endpoint before legacy child-theme admin redirects.
        add_action('admin_init', static function () {
            if (($GLOBALS['pagenow'] ?? '') === 'admin-post.php' && sanitize_key(wp_unslash($_REQUEST['action'] ?? '')) === 'bpm_action') {
                self::dispatch();
                exit;
            }
        }, -100);
        add_action('admin_post_bpm_action', [self::class, 'dispatch']);
        add_action('admin_post_nopriv_bpm_action', [self::class, 'dispatch']);
        add_action('after_password_reset', [self::class, 'verified'], 10, 1);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        add_action('template_redirect', [self::class, 'private_headers']);
        add_action('wp_head', static function () {
            if (is_singular() && ((has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'belger_mieterportal') || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'openmieterportal')) || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'belger_admin_login'))) {
                // Strip the bearer invitation from the address bar before theme scripts run.
                echo '<script>(()=>{const p=new URLSearchParams(location.hash.slice(1));const t=p.get("einladung");if(t&&/^(?:[A-HJ-NP-Z2-9]{16}|[a-f0-9]{64})$/i.test(t)){history.replaceState(null,"",location.pathname+location.search);document.addEventListener("DOMContentLoaded",()=>' . esc_html__('{const f=document.getElementById("bpm-code");if(f){f.value=t;document.getElementById("bpm-activation")?.scrollIntoView({block:"start"});}},{once:true});}})();', 'openmieterportal') . '</script>';
            }
        }, -1000);
        add_filter('login_redirect', static function ($redirect, $requested, $user) {
            return $user instanceof WP_User && in_array('bpm_tenant', $user->roles, true) ? self::url() : $redirect;
        }, 100, 3);
        add_action('admin_init', static function () {
            if (self::household() && !self::manager() && ($GLOBALS['pagenow'] ?? '') !== 'admin-post.php' && !wp_doing_ajax()) {
                wp_safe_redirect(self::url());
                exit;
            }
        });
        add_filter('show_admin_bar', static function ($show) {
            return self::household() && !self::manager() ? false : $show;
        });
        add_filter('wp_authenticate_user', static function ($user) {
            if ($user instanceof WP_User) {
                $h = self::household($user->ID);
                if ($h && !self::can_access($h)) {
                    return new WP_Error('bpm_account_unavailable', __('Der Mieterzugang ist noch nicht aktiviert, gesperrt oder nach Ende der Zugangsfrist abgelaufen. Bitte wenden Sie sich an die Verwaltung.', 'openmieterportal'));
                }
            }
            return $user;
        });
    }

    public static function activate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();
        $definitions = [
            'ticket_links' => "ticket_id bigint unsigned NOT NULL,\nhousehold_id bigint unsigned NOT NULL,\nlegacy_thread_id bigint unsigned DEFAULT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (ticket_id),\nUNIQUE KEY legacy_thread_id (legacy_thread_id),\nKEY household_id (household_id)",
            'buildings' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nreference varchar(100) NOT NULL,\nlabel varchar(190) NOT NULL,\naddress varchar(190) NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY reference (reference)",
            'units' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nbuilding_id bigint unsigned NOT NULL,\nreference varchar(100) NOT NULL,\nlabel varchar(190) NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY reference (reference),\nKEY building_id (building_id)",
            'households' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nreference varchar(100) NOT NULL,\nlabel varchar(190) NOT NULL,\nunit_label varchar(190) NOT NULL,\nunit_id bigint unsigned DEFAULT NULL,\nuser_id bigint unsigned DEFAULT NULL,\nexpected_email varchar(190) NOT NULL DEFAULT '',\nstatus varchar(30) NOT NULL DEFAULT 'invited',\nregistration_lock varchar(64) NOT NULL DEFAULT '',\ncreated_at datetime NOT NULL,\nactivated_at datetime DEFAULT NULL,\ntenancy_start date DEFAULT NULL,\ntenancy_end date DEFAULT NULL,\nnotice_received date DEFAULT NULL,\ntermination_by varchar(20) NOT NULL DEFAULT '',\nPRIMARY KEY  (id),\nUNIQUE KEY reference (reference),\nUNIQUE KEY user_id (user_id)",
            'rent_terms' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nhousehold_id bigint unsigned NOT NULL,\neffective_from date NOT NULL,\nrent_cents bigint unsigned NOT NULL,\nadvance_cents bigint unsigned NOT NULL,\noperating_cents bigint unsigned DEFAULT NULL,\nheating_cents bigint unsigned DEFAULT NULL,\ngarage_cents bigint unsigned DEFAULT NULL,\ncreated_at datetime NOT NULL,\nupdated_at datetime NOT NULL,\nactor_id bigint unsigned NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY household_effective (household_id,effective_from)",
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
        foreach ($definitions as $name => $definition) {
            if (isset(BPM_Data::FIELDS[$name])) {
                $definition = "protected_data longtext NULL,\n" . $definition;
            }
            dbDelta('CREATE TABLE ' . self::table($name) . " (\n$definition\n) $cc;");
        }
        BPM_Data::install();
        add_role('bpm_tenant', __('Mietpartei', 'openmieterportal'), ['read' => true]);
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('bpm_manage');
        }
        if (!get_option('bpm_page_id')) {
            $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'private', 'post_title' => __('Mieterportal', 'openmieterportal'), 'post_name' => 'mieterportal', 'post_content' => '[openmieterportal]', 'comment_status' => 'closed']);
            if ($id && !is_wp_error($id)) {
                update_option('bpm_page_id', $id);
            }
        }
        if (!get_option('bpm_admin_login_page_id')) {
            $admin_page = wp_insert_post(['post_type' => 'page','post_status' => 'publish','post_title' => __('Verwaltungszugang', 'openmieterportal'),'post_name' => 'admin-login','post_content' => '[belger_admin_login]','comment_status' => 'closed']);
            if ($admin_page && !is_wp_error($admin_page)) {
                update_option('bpm_admin_login_page_id', $admin_page);
            }
        }
        if (!wp_next_scheduled('bpm_expire_access')) {
            wp_schedule_event(time() + 60, 'hourly', 'bpm_expire_access');
        }
        self::migrate_units();
        if (!BPM_Meters::migrate()) {
            return;
        }
        BPM_Account::install();
        update_option('bpm_schema_version', BPM_VERSION);
    }

    /** Preserve contract IDs, accounts, documents and consent during the additive migration. */
    public static function migrate_units(): void
    {
        global $wpdb;
        $rows = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE unit_id IS NULL', self::table('households')));
        foreach ($rows as $h) {
            // Only split an explicitly structured legacy address; unknown records require manual assignment.
            $parts = explode(' · ', $h->unit_label, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $bref = 'B-' . substr(hash('sha256', $parts[0]), 0, 24);
            $bid = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE reference=%s', self::table('buildings'), $bref));
            if (!$bid) {
                self::insert('buildings', ['reference' => $bref, 'label' => $parts[0], 'address' => $parts[0]]);
                $bid = $wpdb->insert_id;
            }
            $uref = 'W-' . substr(hash('sha256', $h->unit_label), 0, 24);
            $uid = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE reference=%s', self::table('units'), $uref));
            if (!$uid) {
                self::insert('units', ['building_id' => $bid, 'reference' => $uref, 'label' => $parts[1]]);
                $uid = $wpdb->insert_id;
            }
            if (BPM_Data::update(self::table('households'), ['unit_id' => $uid], ['id' => $h->id]) === false) {
                throw new RuntimeException(esc_html__('Wohnungsübernahme fehlgeschlagen.', 'openmieterportal'));
            }
            if (BPM_Data::update(self::table('meters'), ['unit_id' => $uid], ['household_id' => $h->id, 'unit_id' => null]) === false) {
                throw new RuntimeException(esc_html__('Zählerübernahme fehlgeschlagen.', 'openmieterportal'));
            }
        }
    }
    public static function unit(int $id): object
    {
        global $wpdb;
        $u = BPM_Data::row($wpdb->prepare('SELECT u.*, b.address, b.label AS building_label FROM %i u JOIN %i b ON b.id=u.building_id WHERE u.id=%d', self::table('units'), self::table('buildings'), $id));
        if (!$u) {
            throw new InvalidArgumentException(esc_html__('Bitte eine vorhandene Wohnung auswählen.', 'openmieterportal'));
        }
        return $u;
    }
    public static function meters_for(object $h): array
    {
        global $wpdb;
        if (empty($h->unit_id) || empty($h->tenancy_start) || $h->tenancy_start > self::today() || (!empty($h->tenancy_end) && $h->tenancy_end < self::today())) {
            return [];
        }
        return BPM_Data::rows($wpdb->prepare('SELECT m.* FROM %i m WHERE m.unit_id=%d AND m.active=1 AND (m.installed_on IS NULL OR m.installed_on<=%s) AND (m.removed_on IS NULL OR m.removed_on>=%s) AND NOT EXISTS (SELECT 1 FROM %i n WHERE m.point_id IS NOT NULL AND n.point_id=m.point_id AND n.active=1 AND (n.installed_on IS NULL OR n.installed_on<=%s) AND (n.removed_on IS NULL OR n.removed_on>=%s) AND (COALESCE(n.installed_on,\'0000-01-01\')>COALESCE(m.installed_on,\'0000-01-01\') OR (COALESCE(n.installed_on,\'0000-01-01\')=COALESCE(m.installed_on,\'0000-01-01\') AND n.id>m.id))) ORDER BY m.label,m.number', self::table('meters'), $h->unit_id, self::today(), self::today(), self::table('meters'), self::today(), self::today()));
    }
    public static function meter_for_reading(int $id, object $h, string $date, bool $manager = false): object
    {
        global $wpdb;
        $m = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', self::table('meters'), $id));
        if (!$m || empty($h->unit_id) || (int)$m->unit_id !== (int)$h->unit_id || (!$manager && !$m->active) || empty($h->tenancy_start) || $date < $h->tenancy_start || (!empty($h->tenancy_end) && $date > $h->tenancy_end) || (!empty($m->installed_on) && $date < $m->installed_on) || (!empty($m->removed_on) && $date > $m->removed_on)) {
            throw new InvalidArgumentException(esc_html__('Dieser Zähler ist für Ihre Wohnung und diesen Mietzeitraum nicht verfügbar.', 'openmieterportal'));
        }
        return $m;
    }

    public static function household(?int $user_id = null): ?object
    {
        global $wpdb;
        $user_id = $user_id ?? get_current_user_id();
        if (!$user_id) {
            return null;
        }
        return BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE user_id=%d', self::table('households'), $user_id)) ?: null;
    }
    public static function event(string $kind, int $household = 0, int $object = 0): void
    {
        global $wpdb;
        $wpdb->insert(self::table('events'), ['household_id' => $household, 'actor_id' => get_current_user_id(), 'kind' => $kind, 'object_id' => $object, 'created_at' => self::now()]);
    }
    public static function private_headers(): void
    {
        if (is_singular() && ((has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'belger_mieterportal') || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'openmieterportal')) || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'belger_admin_login'))) {
            self::headers();
        }
    }
    public static function headers(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        // No third-party scripts, fonts, pixels, frames or QR services on sensitive screens.
        // Applies only to this portal and its own form endpoints, not the public website.
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'");
    }
    public static function assets(): void
    {
        if (is_singular() && ((has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'belger_mieterportal') || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'openmieterportal')) || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'belger_admin_login'))) {
            wp_enqueue_style('bpm-portal', plugins_url('assets/portal.css', BPM_FILE), [], BPM_VERSION);
            wp_enqueue_script('bpm-portal', plugins_url('assets/portal.js', BPM_FILE), [], BPM_VERSION, true);
            wp_localize_script('bpm-portal', 'OpenMieterportalI18n', [
                /* translators: 1: number of visible documents, 2: total documents. */
                'documentCount' => __('%1$d von %2$d Dokumenten', 'openmieterportal'),
                'locale' => str_replace('_', '-', determine_locale()),
            ]);
        }
    }
    public static function verified(WP_User $user): void
    {
        global $wpdb;
        $household = self::household($user->ID);
        if ($household && self::past_deadline($household)) {
            self::expire_household($household);
            return;
        }
        if ($household && $household->status === 'pending_email' && in_array('bpm_tenant', $user->roles, true)) {
            BPM_Data::update(self::table('households'), ['status' => 'active', 'activated_at' => self::now()], ['id' => $household->id, 'status' => 'pending_email']);
            self::event('account_verified', (int) $household->id);
        }
    }
    public static function activation_mail(int $user_id): bool
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            return false;
        }
        $url = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($user->user_login), 'login');
        $ok = self::mail($user->user_email, __('Mieterportal: E-Mail bestätigen und Passwort festlegen', 'openmieterportal'), "Bitte legen Sie über diesen Link das Passwort für den gemeinsamen Zugang Ihrer Mietpartei fest:\n\n$url\n\nDanach erreichen Sie das Portal unter:\n" . self::url() . __('

Wenn Sie diese Registrierung nicht veranlasst haben, verwenden Sie den Link nicht und kontaktieren Sie die Verwaltung.', 'openmieterportal'));
        self::event($ok ? 'activation_mail_sent' : 'mail_failed', (int) (self::household($user_id)->id ?? 0));
        return $ok;
    }
    /** SMTP credentials and sender are configured in the site's standard mail plugin. */
    public static function mail($to, string $subject, string $message): bool
    {
        if (get_option('bpm_noreply_notice', false)) {
            $message .= __('

Bitte antworten Sie nicht auf diese automatisch versandte E-Mail. Nutzen Sie für Ihr Anliegen das Mieterportal:
', 'openmieterportal') . self::url();
        }
        return wp_mail($to, $subject, $message);
    }
    public static function notify(int $household_id, bool $to_tenant): void
    {
        if ($to_tenant) {
            BPM_Account::notify($household_id);
            return;
        }
        global $wpdb;
        $household = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', self::table('households'), $household_id));
        $user = $household && $household->user_id ? get_userdata((int) $household->user_id) : null;
        $email = $to_tenant ? ($user->user_email ?? '') : get_option('admin_email');
        if (
            $email && !self::mail($email, __('Neue Information im Mieterportal', 'openmieterportal'), __('Im Mieterportal liegt eine neue Information bereit. Bitte melden Sie sich an:
', 'openmieterportal') . self::url() . __('

Diese E-Mail enthält aus Datenschutzgründen keine Nachrichtentexte oder Dokumente.', 'openmieterportal'))
        ) {
            self::event('mail_failed', $household_id);
        }
    }
    public static function text(string $key, int $max = 5000): string
    {
        // Reusable field reader: dispatch and every public write handler verify the operation nonce first.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $value = sanitize_textarea_field(wp_unslash((string) ($_POST[$key] ?? '')));
        if ($value === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException(esc_html__('Bitte alle Pflichtfelder vollständig und in zulässiger Länge ausfüllen.', 'openmieterportal'));
        }
        return $value;
    }
    public static function form(string $operation): void
    {
        echo '<input type="hidden" name="action" value="bpm_action"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
        wp_nonce_field('bpm_' . $operation);
    }
    public static function dispatch(): void
    {
        global $wpdb;
        self::headers();
        $operation = sanitize_key(wp_unslash($_REQUEST['operation'] ?? ''));
        try {
            if ($operation === 'download' && sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')) === 'GET') {
                self::download();
                exit;
            }
            if (sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                throw new RuntimeException(esc_html__('Diese Aktion benötigt eine Formularübermittlung.', 'openmieterportal'));
            }
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'bpm_' . $operation)) {
                throw new RuntimeException(esc_html__('Die Sitzung ist abgelaufen. Bitte laden Sie das Formular neu.', 'openmieterportal'));
            }
            if ($operation === 'activate') {
                if (get_post_status((int) get_option('bpm_page_id')) !== 'publish') {
                    throw new RuntimeException(esc_html__('Das Mieterportal ist noch nicht freigeschaltet.', 'openmieterportal'));
                }
                self::claim_invite();
                exit;
            }
            if (in_array($operation, ['branding', 'building', 'unit', 'unit_update', 'unit_meter_save', 'unit_meter_assign', 'point_save', 'device_save', 'device_exchange', 'household', 'tenancy', 'rent', 'invite', 'resend', 'document', 'meter', 'admin_reply', 'settings', 'review_reading', 'admin_reading', 'access'], true)) {
                if (!self::manager()) {
                    throw new RuntimeException(esc_html__('Keine Berechtigung.', 'openmieterportal'));
                }
                BPM_Admin::action($operation);
                exit;
            }
            $h = self::household();
            BPM_Security::limit('portal-write', get_current_user_id() . '|' . BPM_Security::ip(), 60, 300);
            if (!self::can_access($h)) {
                throw new RuntimeException(esc_html__('Bitte melden Sie sich mit einem aktivierten Mieterzugang an.', 'openmieterportal'));
            }
            if (BPM_Account::action($operation, $h)) {
                return;
            }
            $hid = (int) $h->id;
            if (in_array($operation, ['message','reply'], true) && BPM_Tickets::enabled($h)) {
                throw new InvalidArgumentException(esc_html__('Bitte verwenden Sie die Ticketfunktion im Portal.', 'openmieterportal'));
            }
            if ($operation === 'message') {
                $kind = sanitize_key($_POST['kind'] ?? 'anliegen');
                if (!in_array($kind, ['anliegen', 'schaden', 'stammdaten'], true)) {
                    throw new InvalidArgumentException(esc_html__('Ungültiger Nachrichtentyp.', 'openmieterportal'));
                }
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
                BPM_Data::update(self::table('threads'), ['status' => 'offen', 'updated_at' => self::now()], ['id' => $thread->id]);
                self::event('message_created', $hid, (int) $thread->id);
                self::notify($hid, false);
            } elseif ($operation === 'reading') {
                $date = BPM_Domain::date((string) (sanitize_text_field(wp_unslash($_POST['read_at'] ?? ''))));
                $meter = self::meter_for_reading(absint($_POST['meter_id'] ?? 0), $h, $date);
                if (!(int) $meter->active) {
                    throw new InvalidArgumentException(esc_html__('Dieser Zähler ist nicht mehr aktiv.', 'openmieterportal'));
                }
                $value = BPM_Domain::reading((string) (sanitize_text_field(wp_unslash($_POST['value'] ?? ''))));
                $date = BPM_Domain::date((string) (sanitize_text_field(wp_unslash($_POST['read_at'] ?? ''))));
                $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE meter_id=%d AND read_at=%s', self::table('readings'), $meter->id, $date));
                if ($exists) {
                    throw new InvalidArgumentException(esc_html__('Dieser Wert wurde für das angegebene Datum bereits gemeldet.', 'openmieterportal'));
                }
                $photo = '';
                if (!empty($_FILES['photo']['name'])) {
                    // Raw upload metadata is validated by is_uploaded_file, finfo, size and image checks in BPM_Storage.
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                    $photo = BPM_Storage::upload($_FILES['photo'], false)['storage_key'];
                }
                try {
                    self::insert('readings', ['household_id' => $hid, 'meter_id' => $meter->id, 'value' => $value, 'read_at' => $date, 'source' => 'mieter', 'status' => 'ungeprueft', 'photo_key' => $photo, 'created_at' => self::now()]);
                } catch (Throwable $e) {
                    if ($photo) {
                        wp_delete_file(BPM_Storage::path($photo));
                    } throw $e;
                }
                self::event('reading_created', $hid, (int) $wpdb->insert_id);
                self::notify($hid, false);
            } elseif ($operation === 'preference') {
                $choice = sanitize_key($_POST['choice'] ?? '');
                if (!in_array($choice, ['portal', 'widerrufen'], true)) {
                    throw new InvalidArgumentException(esc_html__('Bitte eine gültige Auswahl treffen.', 'openmieterportal'));
                }
                if ($choice === 'portal' && (!get_option('bpm_consent_enabled') || empty($_POST['authority']) || empty($_POST['agree']))) {
                    throw new InvalidArgumentException(esc_html__('Die Vereinbarung ist noch nicht freigegeben oder die erforderlichen Bestätigungen fehlen.', 'openmieterportal'));
                }
                self::insert('preferences', ['household_id' => $hid, 'user_id' => get_current_user_id(), 'choice' => $choice, 'version' => BPM_Settings::consent_version(), 'wording' => BPM_Settings::consent_text(), 'authority_confirmed' => $choice === 'portal' ? 1 : 0, 'created_at' => self::now()]);
                self::event('communication_' . $choice, $hid);
                self::notify($hid, false);
            } else {
                throw new InvalidArgumentException(esc_html__('Unbekannte Aktion.', 'openmieterportal'));
            }
            $return_section = match ($operation) {
                'reading' => 'bpm-readings', 'preference' => 'bpm-preference', default => 'bpm-messages'
            };
            wp_safe_redirect(add_query_arg('bpm_done', '1', self::url()) . '#' . $return_section);
            exit;
        } catch (Throwable $e) {
            $message = $e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : __('Die Aktion konnte nicht abgeschlossen werden. Bitte wenden Sie sich an die Verwaltung.', 'openmieterportal');
            wp_die(esc_html($message) . '<p><a href="' . esc_url(self::url()) . ('">' . esc_html__('Zurück zum Mieterportal', 'openmieterportal') . '</a></p>'), esc_html__('Mieterportal', 'openmieterportal'), ['response' => 400]);
        }
    }
    public static function insert(string $table, array $data): void
    {
        global $wpdb;
        if (BPM_Data::insert(self::table($table), $data) === false) {
            throw new RuntimeException(esc_html__('Die Angaben konnten nicht gespeichert werden. Bitte kontaktieren Sie die Verwaltung.', 'openmieterportal'));
        }
    }
    public static function owned(string $table, int $id, int $hid, bool $manager = false): object
    {
        global $wpdb;
        if (!in_array($table, ['threads', 'meters', 'documents', 'readings'], true)) {
            throw new RuntimeException(esc_html__('Ungültiger Datentyp.', 'openmieterportal'));
        }
        $row = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', self::table($table), $id));
        if (!$row || !BPM_Domain::allowed((int) $row->household_id, $hid, $manager)) {
            throw new RuntimeException(esc_html__('Dieser Eintrag ist nicht verfügbar.', 'openmieterportal'));
        }
        return $row;
    }
    public static function claim_invite(): void
    {
        check_admin_referer('bpm_activate');
        global $wpdb;
        $token = BPM_Domain::normalize_invitation(sanitize_text_field(wp_unslash($_POST['invite_code'] ?? '')));
        $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
        BPM_Security::limit('activation', BPM_Security::ip(), 8, 15 * MINUTE_IN_SECONDS);
        $rate = 'bpm_reg_' . hash_hmac('sha256', sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')), wp_salt('nonce'));
        $count = (int) get_transient($rate);
        if ($count >= 8) {
            throw new RuntimeException(esc_html__('Zu viele Versuche. Bitte versuchen Sie es in 15 Minuten erneut.', 'openmieterportal'));
        }
        set_transient($rate, $count + 1, 15 * MINUTE_IN_SECONDS);
        if (!BPM_Domain::valid_invitation($token) || !is_email($email)) {
            throw new InvalidArgumentException(esc_html__('Bitte einen gültigen Einladungscode und eine gültige E-Mail-Adresse angeben.', 'openmieterportal'));
        }
        $invite = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE token_hash=%s AND state=%s AND expires_at>%s', self::table('invites'), BPM_Domain::token_hash($token), 'open', self::now()));
        $h = $invite ? BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', self::table('households'), $invite->household_id)) : null;
        if (!$h || self::past_deadline($h) || $h->user_id || ($h->expected_email && strcasecmp($h->expected_email, $email) !== 0) || email_exists($email)) {
            throw new InvalidArgumentException(esc_html__('Die Einladung kann mit diesen Angaben nicht verwendet werden. Bitte wenden Sie sich an die Verwaltung.', 'openmieterportal'));
        }
        $lock = BPM_Domain::token();
        $claimed = $wpdb->query($wpdb->prepare('UPDATE %i SET registration_lock=%s WHERE id=%d AND user_id IS NULL AND registration_lock=%s AND status=%s', self::table('households'), $lock, $h->id, '', 'invited'));
        if ($claimed !== 1) {
            throw new RuntimeException(esc_html__('Die Einladung wird bereits verarbeitet oder wurde verwendet.', 'openmieterportal'));
        }
        $user_id = 0;
        try {
            $taken = $wpdb->query($wpdb->prepare('UPDATE %i SET state=%s WHERE id=%d AND state=%s AND expires_at>%s', self::table('invites'), 'claimed', $invite->id, 'open', self::now()));
            if ($taken !== 1) {
                throw new RuntimeException(esc_html__('Die Einladung ist nicht mehr gültig.', 'openmieterportal'));
            }
            $user_id = wp_insert_user(['user_login' => 'mietpartei_' . $h->id . '_' . substr(BPM_Domain::token(), 0, 8), 'user_pass' => BPM_Domain::token(), 'user_email' => $email, 'display_name' => $h->label, 'role' => 'bpm_tenant', 'show_admin_bar_front' => 'false']);
            if (is_wp_error($user_id)) {
                throw new RuntimeException(esc_html__('Der Zugang konnte nicht angelegt werden. Bitte kontaktieren Sie die Verwaltung.', 'openmieterportal'));
            }
            $updated = BPM_Data::update(self::table('households'), ['user_id' => $user_id, 'status' => 'pending_email', 'registration_lock' => ''], ['id' => $h->id, 'registration_lock' => $lock]);
            if ($updated !== 1) {
                throw new RuntimeException(esc_html__('Die Zuordnung konnte nicht gespeichert werden. Bitte kontaktieren Sie die Verwaltung.', 'openmieterportal'));
            }
            BPM_Data::update(self::table('invites'), ['state' => 'used', 'used_at' => self::now()], ['id' => $invite->id]);
        } catch (Throwable $e) {
            // Fail closed: if a user was created, keep the claimed invitation for administrator review.
            if (!is_int($user_id) || $user_id === 0) {
                BPM_Data::update(self::table('invites'), ['state' => 'open'], ['id' => $invite->id, 'state' => 'claimed']);
                BPM_Data::update(self::table('households'), ['registration_lock' => ''], ['id' => $h->id, 'registration_lock' => $lock]);
            }
            throw $e;
        }
        $sent = self::activation_mail($user_id);
        self::event('invite_claimed', (int) $h->id, (int) $invite->id);
        $message = $sent ? __('Bitte prüfen Sie Ihr E-Mail-Postfach. Über den Bestätigungslink legen Sie Ihr Passwort fest und aktivieren den Zugang Ihrer Mietpartei.', 'openmieterportal') : __('Der Zugang wurde vorbereitet, die Aktivierungsmail konnte aber nicht versendet werden. Bitte kontaktieren Sie die Verwaltung.', 'openmieterportal');
        wp_die(esc_html($message) . '<p><a href="' . esc_url(self::url()) . ('">' . esc_html__('Zum Mieterportal', 'openmieterportal') . '</a></p>'), esc_html__('Registrierung', 'openmieterportal'), ['response' => 200]);
    }
    public static function download_url(int $id, bool $photo = false): string
    {
        return wp_nonce_url(add_query_arg(['action' => 'bpm_action', 'operation' => 'download', 'id' => $id, 'photo' => $photo ? 1 : 0], admin_url('admin-post.php')), 'bpm_download_' . ($photo ? 'photo_' : 'doc_') . $id);
    }
    public static function download(): void
    {
        $hid = (int) (self::household()->id ?? 0);
        if (!self::manager() && !self::can_access(self::household())) {
            throw new RuntimeException(esc_html__('Keine Berechtigung.', 'openmieterportal'));
        }
        BPM_Security::limit('download-user', (string)get_current_user_id(), 120, 600);
        BPM_Security::limit('download-ip', BPM_Security::ip(), 240, 600);
        $id = absint($_GET['id'] ?? 0);
        $photo = !empty($_GET['photo']);
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'bpm_download_' . ($photo ? 'photo_' : 'doc_') . $id)) {
            throw new RuntimeException(esc_html__('Der Downloadlink ist abgelaufen. Bitte laden Sie die Portalseite neu.', 'openmieterportal'));
        }
        $row = self::owned($photo ? 'readings' : 'documents', $id, $hid, self::manager());
        $photo_data = $photo ? BPM_Storage::plaintext($row->photo_key) : null;
        $verified = null;
        if (!$photo) {
            try {
                $verified = BPM_Storage::verified_document($row->storage_key, $row->sha256, (int) $row->bytes);
            } catch (RuntimeException $e) {
                self::event('document_integrity_failed', (int) $row->household_id, $id);
                throw $e;
            }
        }
        $name = $photo ? 'zaehlerfoto.jpg' : $row->filename;
        header('Content-Type: ' . ($photo ? 'image/jpeg' : 'application/pdf'));
        header('Content-Security-Policy: sandbox');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . ($photo ? strlen($photo_data) : (int) $row->bytes));
        if (!$photo) {
            header('Content-Digest: sha-256=:' . base64_encode(hex2bin($row->sha256)) . ':');
        }
        self::event('file_download', (int) $row->household_id, $id);
        if ($photo) {
            // Authenticated, validated JPEG bytes are a binary response, not HTML.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $photo_data;
            $sent = strlen($photo_data);
        } else {
            try {
                $sent = fpassthru($verified);
            } finally {
                // Close the verified php://memory response stream; WP_Filesystem has no stream-handle equivalent.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($verified);
            }
        }
        if (!$photo && !self::manager() && $sent !== false && $sent === (int) $row->bytes && !connection_aborted()) {
            global $wpdb;
            $changed = $wpdb->query($wpdb->prepare('UPDATE %i SET first_download_at=%s, first_download_user_id=%d WHERE id=%d AND household_id=%d AND first_download_at IS NULL', self::table('documents'), self::now(), get_current_user_id(), $id, $hid));
            if ($changed === 1) {
                self::event('document_first_download', $hid, $id);
            }
        }
    }
    public static function render($atts = []): string
    {
        global $wpdb;
        ob_start();
        $atts = shortcode_atts(['header' => 'on'], (array)$atts, 'belger_mieterportal');
        $show_header = $atts['header'] !== 'off';
        require __DIR__ . '/view-portal.php';
        return (string) ob_get_clean();
    }
}
