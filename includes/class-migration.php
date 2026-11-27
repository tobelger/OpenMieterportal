<?php

defined('ABSPATH') || exit;
/** Explicit CLI-only maintenance. Never run bulk encryption during a page request. */
final class BPM_Migration
{
    public static function run(): array
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            throw new RuntimeException(esc_html__('Die Migration benötigt den geschützten Wartungszugang.', 'openmieterportal'));
        }
        global $wpdb;
        BPM_Crypto::ring();
        $result = ['tables' => BPM_Data::migrate(),'profiles' => 0];
        foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE user_id IS NOT NULL', BPM_Portal::table('households'))) as $row) {
            $uid = (int)$row->user_id;
            $old = get_user_meta($uid, 'bpm_account_profile', true);
            if ($old) {
                $plain = BPM_Account::decrypt($old, $uid);
                $new = BPM_Account::encrypt($plain, $uid);
                if (BPM_Account::decrypt($new, $uid) !== $plain) {
                    throw new RuntimeException(esc_html__('Die Profilmigration konnte nicht geprüft werden.', 'openmieterportal'));
                }update_user_meta($uid, 'bpm_account_profile', $new);
                $result['profiles']++;
            }
            $u = get_userdata($uid);
            if ($u) {
                $alias = __('Mietpartei ', 'openmieterportal') . $row->id;
                wp_update_user(['ID' => $uid,'display_name' => $alias,'first_name' => '','last_name' => '','nickname' => $alias]);
                if (class_exists('WPSC_Customer')) {
                    $c = WPSC_Customer::get_by_user_id($uid);
                    if ($c && $c->id) {
                            $c->name = $alias;
                            $c->save();
                    }
                }
            }
        }
        if (class_exists('WPSC_Ticket')) {
            foreach ($wpdb->get_col($wpdb->prepare('SELECT ticket_id FROM %i', BPM_Portal::table('ticket_links'))) as $tid) {
                $ticket = new WPSC_Ticket((int)$tid);
                if ($ticket->id) {
                    BPM_Tickets::link_ticket($ticket);
                }
            }
        }
        $result['files'] = BPM_Storage::migrate_files();
        $result['key_id'] = BPM_Crypto::key_id();
        update_option('bpm_security_migration_report', $result, false);
        BPM_Portal::event('security_encryption_migrated');
        return $result;
    }
    public static function rotate(): array
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            throw new RuntimeException(esc_html__('Geschützter Wartungszugang erforderlich.', 'openmieterportal'));
        }
        $ring = BPM_Crypto::ring();
        $id = bin2hex(random_bytes(8));
        $ring['keys'][$id] = base64_encode(random_bytes(32));
        $ring['active'] = $id;
        $path = realpath(BPM_KEY_FILE);
        $tmp = dirname($path) . '/.key-' . bin2hex(random_bytes(16));
        // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $f = fopen($tmp, 'xb');
        if (!$f) {
            throw new RuntimeException(esc_html__('Der neue Schlüssel konnte nicht vorbereitet werden.', 'openmieterportal'));
        }
        try {
            $json = json_encode($ring, JSON_THROW_ON_ERROR);
            // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            if (!chmod($tmp, 0600) || fwrite($f, $json) !== strlen($json)) {
                throw new RuntimeException(esc_html__('Der Schlüssel konnte nicht gespeichert werden.', 'openmieterportal'));
            }
        } catch (Throwable $e) {
            wp_delete_file($tmp);
            throw $e;
        } finally {
            // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($f);
        }
        // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        if (!rename($tmp, $path)) {
            wp_delete_file($tmp);
            throw new RuntimeException(esc_html__('Der Schlüsselwechsel konnte nicht abgeschlossen werden.', 'openmieterportal'));
        }
        return self::run(); // Previous keys are deliberately retained for older encrypted backups.
    }
    public static function boot(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('openmieterportal security-migrate', static function () {
                try {
                    WP_CLI::line(wp_json_encode(self::run()));
                } catch (Throwable $e) {
                    WP_CLI::error($e->getMessage());
                }
            });
            WP_CLI::add_command('openmieterportal security-rotate', static function () {
                try {
                    WP_CLI::line(wp_json_encode(self::rotate()));
                } catch (Throwable $e) {
                    WP_CLI::error($e->getMessage());
                }
            });
        }
    }
}
