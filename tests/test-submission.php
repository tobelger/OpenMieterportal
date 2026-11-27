<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Disposable localhost WordPress only. No emails leave the process.
require getenv('BPM_TEST_WP_LOAD') ?: '/tmp/belger-portal-runtime/wordpress/wp-load.php';
if (!str_starts_with(home_url(), 'http://127.0.0.1:')) {
    throw new RuntimeException('Disposable localhost fixture required.');
}
add_filter('pre_wp_mail', static fn() => true, PHP_INT_MAX);
$checks = 0;
function submission_check($ok, $label) {
    global $checks;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks++;
}
// Re-activation must not change menus, widgets or theme configuration.
$theme_key = 'theme_mods_' . get_option('stylesheet');
$keys = ['sidebars_widgets', 'widget_text', 'nav_menu_options', $theme_key];
$before = [];
foreach ($keys as $key) {
    $before[$key] = get_option($key);
}
$page = (int)get_option('bpm_page_id');
BPM_Portal::activate();
foreach ($keys as $key) {
    submission_check(get_option($key) === $before[$key], 'Theme/menu/widget state preserved');
}
submission_check((int)get_option('bpm_page_id') === $page, 'Existing portal page preserved');
submission_check(has_shortcode(get_post_field('post_content', $page), 'openmieterportal'), 'Canonical shortcode works');
submission_check(has_action('admin_init', [BPM_Privacy::class, 'suggest']) !== false, 'Native privacy guide integration registered');
global $wpdb;
$wpdb->query('START TRANSACTION');
$post = $_POST;
$request = $_REQUEST;
$die_filter = static fn() => static function () { throw new RuntimeException('Submission nonce rejected'); };
$redirect_filter = static function () { throw new RuntimeException('Submission redirect captured'); };
$translation_filter = static function ($translated, $text, $domain) {
    return $domain === 'openmieterportal' && $text === 'Ihr Mieterportal' ? '<img src=x onerror="alert(1)">' : $translated;
};
try {
    $tag = bin2hex(random_bytes(6));
    $uid = wp_insert_user(['user_login'=>'submission-'.$tag,'user_email'=>'submission-'.$tag.'@example.test','user_pass'=>'Synthetic fixture password 2026!','role'=>'bpm_tenant']);
    if (is_wp_error($uid)) {
        throw new RuntimeException('Fixture user creation failed');
    }
    BPM_Portal::insert('households', ['reference'=>'DEMO-SUB-'.$tag,'label'=>'<img src=x onerror="alert(2)">','unit_label'=>'Synthetic apartment','user_id'=>$uid,'status'=>'active','created_at'=>BPM_Portal::now()]);
    wp_set_current_user($uid);
    $h = BPM_Portal::household($uid);
    $old_frequency = BPM_Account::frequency($uid);
    $_POST = ['frequency'=>'daily'];
    $_REQUEST = ['_wpnonce'=>'invalid'];
    add_filter('wp_die_handler', $die_filter, PHP_INT_MAX);
    try {
        BPM_Account::action('account_settings', $h);
        throw new RuntimeException('Invalid nonce was accepted');
    } catch (RuntimeException $e) {
        submission_check($e->getMessage() === 'Submission nonce rejected', 'Invalid nonce blocked before settings mutation');
    }
    remove_filter('wp_die_handler', $die_filter, PHP_INT_MAX);
    submission_check(BPM_Account::frequency($uid) === $old_frequency, 'Rejected request left settings unchanged');
    $_REQUEST['_wpnonce'] = wp_create_nonce('bpm_account_settings');
    add_filter('wp_redirect', $redirect_filter, PHP_INT_MAX);
    try {
        BPM_Account::action('account_settings', $h);
        throw new RuntimeException('Expected account redirect');
    } catch (RuntimeException $e) {
        submission_check($e->getMessage() === 'Submission redirect captured' && BPM_Account::frequency($uid) === 'daily', 'Valid nonce permits own settings change');
    }
    remove_filter('wp_redirect', $redirect_filter, PHP_INT_MAX);
    add_filter('gettext', $translation_filter, PHP_INT_MAX, 3);
    $html = BPM_Portal::render();
    submission_check(!str_contains($html, '<img src=x onerror='), 'Stored tenant label and translation cannot inject markup');
    submission_check(str_contains($html, '&lt;img'), 'Unsafe text is visible as escaped text');
    remove_filter('gettext', $translation_filter, PHP_INT_MAX);
    echo "$checks submission setup and request-boundary checks passed; no mail sent.\n";
} finally {
    remove_filter('wp_die_handler', $die_filter, PHP_INT_MAX);
    remove_filter('wp_redirect', $redirect_filter, PHP_INT_MAX);
    remove_filter('gettext', $translation_filter, PHP_INT_MAX);
    $_POST = $post;
    $_REQUEST = $request;
    wp_set_current_user(0);
    $wpdb->query('ROLLBACK');
    wp_cache_flush();
}
