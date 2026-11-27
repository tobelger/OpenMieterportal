<?php

defined('ABSPATH') || exit;

/** Tenant-owned account details; contract records remain managed separately. */
final class BPM_Account
{
    private static function owner(object $h): int
    {
        $uid = get_current_user_id();
        if (!$uid || (int)$h->user_id !== $uid || !BPM_Portal::can_access($h)) {
            throw new RuntimeException(esc_html__('Keine Berechtigung.', 'openmieterportal'));
        }
        return $uid;
    }
    public static function boot(): void
    {
        add_action('bpm_account_digest', [self::class,'digest']);
        add_action('init', static function () {
            if (!wp_next_scheduled('bpm_account_digest')) {
                wp_schedule_event(time() + 300, 'hourly', 'bpm_account_digest');
            }
        });
        // Capture confirmation fragments before theme or analytics scripts run.
        add_action('wp_head', static function () {
            if (is_page((int)get_option('bpm_page_id'))) {
                echo '<script>(()=>' . esc_html__('{const p=new URLSearchParams(location.hash.slice(1));const t=p.get("email-bestaetigen");if(t&&/^[a-f0-9]{64}$/.test(t)){sessionStorage.setItem("bpmEmailConfirmation",t);history.replaceState(null,"",location.pathname+location.search);}})();', 'openmieterportal') . '</script>';
            }
        }, -1100);
    }
    public static function install(): void
    {
        global $wpdb;
        dbDelta('CREATE TABLE ' . BPM_Portal::table('notification_queue') . " (\nid bigint unsigned NOT NULL AUTO_INCREMENT,\nhousehold_id bigint unsigned NOT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY household_id (household_id)\n) " . $wpdb->get_charset_collate() . ';');
    }
    private static function key(): string
    {
        return hash_hkdf('sha256', BPM_Crypto::legacy_auth_salt(), 32, 'OpenMieterportal account data v1');
    }
    public static function encrypt(array $data, int $uid = 0): string
    {
        if (BPM_Crypto::configured()) {
            return BPM_Crypto::seal(wp_json_encode($data), 'profile/' . $uid);
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(wp_json_encode($data), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'bpm-account-v1');
        if ($cipher === false) {
            throw new RuntimeException(esc_html__('Profildaten konnten nicht geschützt gespeichert werden.', 'openmieterportal'));
        }
        return base64_encode($iv . $tag . $cipher);
    }
    public static function decrypt(string $encoded, int $uid = 0): array
    {
        if (BPM_Crypto::sealed($encoded)) {
            return json_decode(BPM_Crypto::open($encoded, 'profile/' . $uid), true, 32, JSON_THROW_ON_ERROR);
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException(esc_html__('Profildaten sind nicht lesbar.', 'openmieterportal'));
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'bpm-account-v1');
        $data = $plain === false ? null : json_decode($plain, true);
        if (!is_array($data)) {
            throw new RuntimeException(esc_html__('Profildaten sind nicht lesbar. Bitte wenden Sie sich an die Verwaltung.', 'openmieterportal'));
        }
        return $data;
    }
    public static function profile(object $h): array
    {
        global $wpdb;
        $saved = get_user_meta((int)$h->user_id, 'bpm_account_profile', true);
        if ($saved) {
            return self::decrypt($saved, (int)$h->user_id);
        }
        $address = $h->unit_id ? (BPM_Portal::unit((int)$h->unit_id)->address ?? '') : '';
        if (!$address) {
            $address = explode(' · ', $h->unit_label)[0];
        }
        return ['address' => $address,'phone' => '','holder' => '','iban' => '','bic' => ''];
    }
    public static function iban(string $value): string
    {
        $iban = strtoupper(preg_replace('/\s+/u', '', $value));
        // ISO country lengths, including all SEPA countries. Validate MOD-97 as well.
        $lengths = ['AL' => 28,'AD' => 24,'AT' => 20,'AZ' => 28,'BH' => 22,'BE' => 16,'BA' => 20,'BR' => 29,'BG' => 22,'CR' => 22,'HR' => 21,'CY' => 28,'CZ' => 24,'DK' => 18,'DO' => 28,'EE' => 20,'FO' => 18,'FI' => 18,'FR' => 27,'GE' => 22,'DE' => 22,'GI' => 23,'GR' => 27,'GL' => 18,'GT' => 28,'HU' => 28,'IS' => 26,'IE' => 22,'IL' => 23,'IT' => 27,'JO' => 30,'KZ' => 20,'XK' => 20,'KW' => 30,'LV' => 21,'LB' => 28,'LI' => 21,'LT' => 20,'LU' => 20,'MT' => 31,'MR' => 27,'MU' => 30,'MD' => 24,'MC' => 27,'ME' => 22,'NL' => 18,'MK' => 19,'NO' => 15,'PK' => 24,'PS' => 29,'PL' => 28,'PT' => 25,'QA' => 29,'RO' => 24,'SM' => 27,'SA' => 24,'RS' => 22,'SK' => 24,'SI' => 19,'ES' => 24,'SE' => 24,'CH' => 21,'TN' => 24,'TR' => 26,'AE' => 23,'GB' => 22,'UA' => 29,'VA' => 22,'BY' => 28,'SV' => 28,'LC' => 32,'SC' => 31,'ST' => 25,'TL' => 23,'IQ' => 23,'BI' => 27,'DJ' => 27,'RU' => 33,'SO' => 23,'LY' => 25,'SD' => 18,'NI' => 28,'MN' => 20,'FK' => 18,'OM' => 23,'YE' => 30];
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/D', $iban) || strlen($iban) !== ($lengths[substr($iban, 0, 2)] ?? 0)) {
            throw new InvalidArgumentException(esc_html__('Bitte eine gültige IBAN eingeben.', 'openmieterportal'));
        }
        $rotated = substr($iban, 4) . substr($iban, 0, 4);
        $mod = 0;
        foreach (str_split($rotated) as $char) {
            $number = ctype_alpha($char) ? (string)(ord($char) - 55) : $char;
            foreach (str_split($number) as $digit) {
                $mod = ($mod * 10 + (int)$digit) % 97;
            }
        }
        if ($mod !== 1) {
            throw new InvalidArgumentException(esc_html__('Die Prüfziffer der IBAN stimmt nicht.', 'openmieterportal'));
        }
        return $iban;
    }
    public static function validate_profile(array $data): array
    {
        $profile = [];
        foreach (['address' => 500,'phone' => 50,'holder' => 190,'iban' => 50,'bic' => 11] as $field => $max) {
            $value = trim((string)($data[$field] ?? ''));
            if (mb_strlen($value) > $max) {
                throw new InvalidArgumentException(esc_html__('Eine Profilangabe ist zu lang.', 'openmieterportal'));
            }$profile[$field] = $field === 'address' ? sanitize_textarea_field($value) : sanitize_text_field($value);
        }
        if ($profile['address'] === '' || $profile['holder'] === '') {
            throw new InvalidArgumentException(esc_html__('Bitte Anschrift und Kontoinhaber ausfüllen.', 'openmieterportal'));
        }
        $profile['iban'] = self::iban($profile['iban']);
        $profile['bic'] = strtoupper($profile['bic']);
        if ($profile['bic'] !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/D', $profile['bic'])) {
            throw new InvalidArgumentException(esc_html__('Bitte eine gültige BIC eingeben oder das optionale Feld leer lassen.', 'openmieterportal'));
        }
        return $profile;
    }
    public static function save_profile(object $h, array $data): void
    {
        $uid = self::owner($h);
        $profile = self::validate_profile($data);
        $old = self::profile($h);
        $bank_changed = false;
        foreach (['holder','iban','bic'] as $field) {
            if (($old[$field] ?? '') !== $profile[$field]) {
                $bank_changed = true;
            }
        }
        if ($bank_changed && !wp_check_password((string)($data['current_password'] ?? ''), get_userdata($uid)->user_pass, $uid)) {
            throw new InvalidArgumentException(esc_html__('Bitte bestätigen Sie die Änderung der Bankverbindung mit Ihrem aktuellen Passwort.', 'openmieterportal'));
        }
        update_user_meta($uid, 'bpm_account_profile', self::encrypt($profile, $uid));
        if ($bank_changed) {
            BPM_Portal::event('account_bank_updated', (int)$h->id);
            if (!BPM_Portal::mail(get_userdata($uid)->user_email, __('Mieterportal: Bankverbindung geändert', 'openmieterportal'), __('Die Bankverbindung Ihres Mieterzugangs wurde geändert. Falls Sie die Änderung nicht veranlasst haben, melden Sie sich bitte umgehend bei der Verwaltung und ändern Sie Ihr Passwort. Es werden keine Bankdaten per E-Mail versendet.', 'openmieterportal'))) {
                BPM_Portal::event('mail_failed', (int)$h->id);
            }
        }
        BPM_Portal::event('account_profile_updated', (int)$h->id);
        BPM_Portal::notify((int)$h->id, false);
    }
    private static function locked(int $uid, callable $fn)
    {
        $key = 'bpm_account_lock_' . $uid;
        $value = time() . ':' . bin2hex(random_bytes(12));
        $old = get_option($key);
        if ($old && (int)$old < time() - 120) {
            global $wpdb;
            $wpdb->delete($wpdb->options, ['option_name' => $key,'option_value' => $old]);
            wp_cache_delete($key, 'options');
        }
        // option_name has a database UNIQUE constraint; usermeta does not.
        if (!add_option($key, $value, '', false)) {
            throw new RuntimeException(esc_html__('Eine Kontoänderung läuft bereits. Bitte versuchen Sie es gleich erneut.', 'openmieterportal'));
        }
        try {
            return $fn();
        } finally {
            if (get_option($key) === $value) {
                delete_option($key);
            }
        }
    }
    public static function pending(int $uid): ?array
    {
        $p = get_user_meta($uid, 'bpm_pending_email', true);
        return is_array($p) && ($p['expires'] ?? 0) > time() ? $p : null;
    }
    public static function request_email(object $h, string $email, string $password): void
    {
        $uid = self::owner($h);
        $user = get_userdata($uid);
        $email = trim($email);
        if (!is_email($email) || strlen($email) > 190) {
            throw new InvalidArgumentException(esc_html__('Bitte eine gültige E-Mail-Adresse eingeben.', 'openmieterportal'));
        }
        if (strcasecmp($user->user_email, $email) === 0) {
            throw new InvalidArgumentException(esc_html__('Diese E-Mail-Adresse ist bereits bestätigt.', 'openmieterportal'));
        }
        if (!wp_check_password($password, $user->user_pass, $uid)) {
            throw new InvalidArgumentException(esc_html__('Das aktuelle Passwort stimmt nicht.', 'openmieterportal'));
        }
        if (email_exists($email)) {
            throw new InvalidArgumentException(esc_html__('Diese E-Mail-Adresse kann nicht verwendet werden.', 'openmieterportal'));
        }
        self::locked($uid, static function () use ($uid, $h, $email) {
            $last = get_user_meta($uid, 'bpm_email_requested_at', true);
            if ($last && (int)$last > time() - 60) {
                throw new InvalidArgumentException(esc_html__('Bitte warten Sie eine Minute, bevor Sie einen neuen Bestätigungslink anfordern.', 'openmieterportal'));
            }
            $old = get_user_meta($uid, 'bpm_pending_email', true);
            $token = bin2hex(random_bytes(32));
            update_user_meta($uid, 'bpm_pending_email', ['email' => $email,'hash' => hash('sha256', $token),'expires' => time() + DAY_IN_SECONDS]);
            $url = BPM_Portal::url() . '#email-bestaetigen=' . $token;
            $sent = BPM_Portal::mail($email, __('Mieterportal: Neue E-Mail-Adresse bestätigen', 'openmieterportal'), "Sie möchten diese E-Mail-Adresse für Ihren Mieterzugang verwenden. Öffnen Sie den folgenden Link, melden Sie sich mit Ihrem bisherigen Konto an und bestätigen Sie die Änderung im Profil:\n\n$url\n\nDer Link ist 24 Stunden gültig und kann nur einmal verwendet werden. Bis zur Bestätigung bleibt Ihre bisherige E-Mail-Adresse aktiv. Wenn Sie die Änderung nicht angefordert haben, verwenden Sie den Link nicht.");
            if (!$sent) {
                if ($old) {
                    update_user_meta($uid, 'bpm_pending_email', $old);
                } else {
                    delete_user_meta($uid, 'bpm_pending_email');
                }
                throw new RuntimeException(esc_html__('Die Bestätigungsmail konnte nicht versendet werden. Ihre bisherige E-Mail-Adresse bleibt aktiv.', 'openmieterportal'));
            }
            update_user_meta($uid, 'bpm_email_requested_at', time());
            BPM_Portal::event('account_email_requested', (int)$h->id);
        });
    }
    public static function confirm_email(object $h, string $token): void
    {
        $uid = self::owner($h);
        self::locked($uid, static function () use ($uid, $h, $token) {
            $p = self::pending($uid);
            if (!$p || !preg_match('/^[a-f0-9]{64}$/D', $token) || !hash_equals($p['hash'], hash('sha256', $token))) {
                throw new InvalidArgumentException(esc_html__('Der Bestätigungslink ist ungültig oder abgelaufen. Fordern Sie im Profil einen neuen Link an.', 'openmieterportal'));
            }
            if (email_exists($p['email'])) {
                throw new InvalidArgumentException(esc_html__('Diese E-Mail-Adresse kann nicht mehr verwendet werden.', 'openmieterportal'));
            }
            $result = wp_update_user(['ID' => $uid,'user_email' => $p['email']]);
            if (is_wp_error($result)) {
                throw new RuntimeException(esc_html__('Die E-Mail-Adresse konnte nicht geändert werden.', 'openmieterportal'));
            }
            delete_user_meta($uid, 'bpm_pending_email');
            global $wpdb;
            BPM_Data::update(BPM_Portal::table('households'), ['expected_email' => $p['email']], ['id' => (int)$h->id]);
            BPM_Portal::event('account_email_confirmed', (int)$h->id);
        });
    }
    public static function frequency(int $uid): string
    {
        $value = get_user_meta($uid, 'bpm_notice_frequency', true);
        return in_array($value, ['daily','weekly'], true) ? $value : 'immediate';
    }
    public static function save_settings(object $h, string $value): void
    {
        $uid = self::owner($h);
        if (!in_array($value, ['immediate','daily','weekly'], true)) {
            throw new InvalidArgumentException(esc_html__('Bitte eine gültige Häufigkeit auswählen.', 'openmieterportal'));
        }
        update_user_meta($uid, 'bpm_notice_frequency', $value);
        BPM_Portal::event('account_settings_updated', (int)$h->id);
    }
    public static function notify(int $hid): void
    {
        global $wpdb;
        if ($wpdb->insert(BPM_Portal::table('notification_queue'), ['household_id' => $hid,'created_at' => BPM_Portal::now()]) === false) {
            throw new RuntimeException(esc_html__('Der Benachrichtigungshinweis konnte nicht gespeichert werden.', 'openmieterportal'));
        }
        // The cron worker also retries failed immediate deliveries.
        $h = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', BPM_Portal::table('households'), $hid));
        if ($h && self::frequency((int)$h->user_id) === 'immediate') {
            self::digest($hid);
        }
    }
    public static function digest(int $only = 0): void
    {
        global $wpdb;
        $key = 'bpm_digest_lock';
        $lock = time() . ':' . bin2hex(random_bytes(8));
        $old = get_option($key);
        if ($old && (int)$old < time() - 600) {
            delete_option($key);
        }
        if (!add_option($key, $lock, '', false)) {
            return;
        }
        try {
            $groups = BPM_Data::rows('SELECT household_id,MAX(id) max_id,MIN(created_at) first_at,COUNT(*) count FROM ' . BPM_Portal::table('notification_queue') . ($only ? $wpdb->prepare(' WHERE household_id=%d', $only) : '') . ' GROUP BY household_id');
            foreach ($groups as $g) {
                $h = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', BPM_Portal::table('households'), $g->household_id));
                if (!$h || !BPM_Portal::can_access($h) || !$h->user_id) {
                    continue;
                }
                $f = self::frequency((int)$h->user_id);
                $delay = ['immediate' => 0,'daily' => DAY_IN_SECONDS,'weekly' => 7 * DAY_IN_SECONDS][$f];
                if (strtotime($g->first_at . ' UTC') + $delay > time()) {
                    continue;
                }
                $user = get_userdata((int)$h->user_id);
                if (!$user) {
                    continue;
                }
                // Persistent demo accounts intentionally never send real notifications.
                $ok = str_starts_with($h->reference, 'DEMO-') || BPM_Portal::mail($user->user_email, __('Neue Information im Mieterportal', 'openmieterportal'), __('Im Mieterportal liegen neue Informationen für Ihre Mietpartei bereit. Bitte melden Sie sich an:
', 'openmieterportal') . BPM_Portal::url() . __('

Diese E-Mail enthält keine Nachrichtentexte, Bankdaten oder Dokumente.', 'openmieterportal'));
                if ($ok) {
                    $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE household_id=%d AND id<=%d', BPM_Portal::table('notification_queue'), $h->id, $g->max_id));
                    BPM_Portal::event('account_digest_sent', (int)$h->id);
                } else {
                    BPM_Portal::event('mail_failed', (int)$h->id);
                }
            }
        } finally {
            if (get_option($key) === $lock) {
                delete_option($key);
            }
        }
    }
    public static function action(string $operation, object $h): bool
    {
        if (!str_starts_with($operation, 'account_')) {
            return false;
        }
        check_admin_referer('bpm_' . $operation);
        if ($operation === 'account_profile') {
            self::save_profile($h, wp_unslash($_POST));
        } elseif ($operation === 'account_settings') {
            self::save_settings($h, sanitize_key(wp_unslash($_POST['frequency'] ?? '')));
        } elseif ($operation === 'account_email') {
            // Preserve the exact unslashed password for wp_check_password; sanitizing it would change valid credentials.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            self::request_email($h, sanitize_email(wp_unslash($_POST['email'] ?? '')), (string)wp_unslash($_POST['current_password'] ?? ''));
        } elseif ($operation === 'account_email_confirm') {
            self::confirm_email($h, sanitize_text_field(wp_unslash($_POST['email_token'] ?? '')));
        } elseif ($operation === 'account_email_cancel') {
            $uid = self::owner($h);
            self::locked($uid, static fn()=>delete_user_meta($uid, 'bpm_pending_email'));
        } else {
            throw new InvalidArgumentException(esc_html__('Unbekannte Kontoaktion.', 'openmieterportal'));
        }
        $tab = $operation === 'account_settings' ? 'bpm-settings' : 'bpm-account-profile';
        wp_safe_redirect(add_query_arg('bpm_done', '1', BPM_Portal::url()) . '#' . $tab);
        exit;
    }
    public static function admin_card(object $h): void
    {
        if (!BPM_Portal::manager() || !$h->user_id) {
            return;
        }
        $p = self::profile($h);
        $user = get_userdata((int)$h->user_id);
        echo '<section class="bpm-card"><h2>' . esc_html__('Kontaktdaten und Bankverbindung', 'openmieterportal') . '</h2><p>' . esc_html__('Von der Mietpartei gepflegte Angaben. Änderungen werden unmittelbar übernommen; eine Bankangabe erteilt kein SEPA-Lastschriftmandat.', 'openmieterportal') . '</p><dl class="bpm-data">';
        foreach (['address' => __('Anschrift', 'openmieterportal'),'phone' => __('Telefon', 'openmieterportal'),'holder' => __('Kontoinhaber', 'openmieterportal'),'iban' => 'IBAN','bic' => 'BIC'] as $k => $label) {
            echo '<dt>' . esc_html($label) . '</dt><dd>' . nl2br(esc_html($p[$k] ?: esc_html__('Noch nicht hinterlegt', 'openmieterportal'))) . '</dd>';
        }
        echo '<dt>' . esc_html__('Bestätigte E-Mail-Adresse', 'openmieterportal') . '</dt><dd>' . esc_html($user->user_email) . '</dd></dl></section>';
    }
}
