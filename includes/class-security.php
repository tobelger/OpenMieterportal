<?php

defined('ABSPATH') || exit;
final class BPM_Security
{
    public static function limit(string $scope, string $identity, int $max, int $seconds): void
    {
        global $wpdb;
        $slot = (int)(floor(time() / $seconds) * $seconds);
        $name = 'bpm_limit_' . $slot . '_' . hash_hmac('sha256', $scope . '|' . $identity, wp_salt('nonce'));
        $ok = $wpdb->query($wpdb->prepare("INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,'1','off') ON DUPLICATE KEY UPDATE option_value=CAST(option_value AS UNSIGNED)+1", $wpdb->options, $name));
        if ($ok === false) {
            throw new RuntimeException(esc_html__('Die Sicherheitsprüfung ist derzeit nicht verfügbar.', 'openmieterportal'));
        }
        $count = (int)$wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, $name));
        if ($count > $max) {
            if ($count === $max + 1) {
                BPM_Portal::event('security_rate_limited');
            }throw new RuntimeException(esc_html__('Zu viele Versuche. Bitte versuchen Sie es später erneut.', 'openmieterportal'));
        }
    }
    public static function ip(): string
    {
        return sanitize_text_field(wp_unslash((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')));
    }
    public static function boot(): void
    {
        add_filter('two_factor_enabled_providers_for_user', static function ($providers, $uid) {
            if (user_can($uid, 'bpm_manage') && get_option('bpm_manager_2fa_required', false)) {
                $providers = array_values(array_unique(array_merge($providers, ['Two_Factor_Email'])));
            }return $providers;
        }, 20, 2);
        add_filter('authenticate', static function ($user) {
            if ($user instanceof WP_User && user_can($user, 'bpm_manage') && get_option('bpm_manager_2fa_required', false) && (!class_exists('Two_Factor_Core') || !is_email($user->user_email))) {
                return new WP_Error('bpm_2fa_unavailable', __('Die Zwei-Faktor-Anmeldung für die Verwaltung ist derzeit nicht verfügbar.', 'openmieterportal'));
            }return $user;
        }, 90);
        add_action('wp_login', static function ($login, $user) {
            update_user_meta($user->ID, 'bpm_last_login_utc', gmdate('Y-m-d H:i:s'));
            BPM_Portal::event('security_login_success', (int)(BPM_Portal::household($user->ID)->id ?? 0));
        }, 10, 2);
        add_action('wp_login_failed', static function ($login) {
            BPM_Portal::event('security_login_failed');
        });
        add_filter('authenticate', static function ($user, $login, $password) {
            if ($login === '' || $password === '') {
                return $user;
            }
            try {
                self::limit('login-ip', self::ip(), 60, 900);
                self::limit('login-name', strtolower($login), 30, 900);
            } catch (RuntimeException $e) {
                return new WP_Error('bpm_login_limit', $e->getMessage());
            }return $user;
        }, 30, 3);
        add_filter('auth_cookie_expiration', static function ($length, $uid, $remember) {
            $user = get_userdata($uid);
            if (!$user) {
                return $length;
            }if (user_can($user, 'bpm_manage')) {
                return min($length, $remember ? DAY_IN_SECONDS : 8 * HOUR_IN_SECONDS);
            }if (BPM_Portal::household($uid)) {
                return min($length, $remember ? 7 * DAY_IN_SECONDS : 12 * HOUR_IN_SECONDS);
            }return $length;
        }, 20, 3);
        add_action('bpm_account_digest', [self::class,'cleanup']);
    }
    public static function cleanup(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s AND CAST(SUBSTRING(option_name,11,10) AS UNSIGNED)<%d', $wpdb->options, $wpdb->esc_like('bpm_limit_') . '%', time() - DAY_IN_SECONDS));
    }
}
