<?php
/**
 * Plugin Name: OpenMieterportal
 * Description: Ein Zugang je Mietpartei: Einladungen, Dokumente, Nachrichten und Zählerstandsmeldungen.
 * Version: 0.8.1
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: supportcandy
 * Author: Belger Immobilienverwaltung
 * License: GPL-2.0-or-later
 * Text Domain: belger-mieterportal
 */
defined('ABSPATH') || exit;
define('BPM_VERSION', '0.8.1');
define('BPM_FILE', __FILE__);
require_once __DIR__ . '/includes/class-domain.php';
require_once __DIR__ . '/includes/class-rent-terms.php';
require_once __DIR__ . '/includes/class-storage.php';
require_once __DIR__ . '/includes/class-portal.php';
require_once __DIR__ . '/includes/class-account.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-meters.php';
require_once __DIR__ . '/includes/class-units-admin.php';
require_once __DIR__ . '/includes/class-tickets.php';
register_activation_hook(__FILE__, ['BPM_Portal', 'activate']);
register_deactivation_hook(__FILE__, ['BPM_Portal', 'deactivate']);
add_action('plugins_loaded', static function () {
    BPM_Portal::boot();
    BPM_Account::boot();
    BPM_Tickets::boot();
    if (is_admin()) {
        BPM_Admin::boot();
        BPM_Units_Admin::boot();
    }
});
