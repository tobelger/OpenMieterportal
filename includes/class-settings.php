<?php

defined('ABSPATH') || exit;
final class BPM_Settings
{
    public static function all(): array
    {
        $defaults = ['name' => get_bloginfo('name'),'logo' => '','address' => '','city' => '','phone' => '','email' => get_option('admin_email'),'website' => home_url('/'),'consent_text' => BPM_Domain::CONSENT_TEXT];
        return array_merge($defaults, (array)get_option('bpm_branding', []));
    }
    public static function value(string $key): string
    {
        return (string)(self::all()[$key] ?? '');
    }
    public static function consent_text(): string
    {
        return self::value('consent_text');
    }
    public static function consent_version(): string
    {
        return self::consent_text() === BPM_Domain::CONSENT_TEXT ? BPM_Domain::CONSENT_VERSION : 'custom-' . substr(hash('sha256', self::consent_text()), 0, 20);
    }
    public static function save(array $input): void
    {
        if (!BPM_Portal::manager()) {
            throw new RuntimeException(esc_html__('Keine Berechtigung.', 'openmieterportal'));
        }$data = [];
        foreach (['name','address','city','phone','email','website','logo','consent_text'] as $key) {
            $raw = (string)($input[$key] ?? '');
            if (mb_strlen($raw) > ($key === 'consent_text' ? 10000 : 500)) {
                throw new InvalidArgumentException(esc_html__('Eine Einstellung ist zu lang.', 'openmieterportal'));
            }$data[$key] = in_array($key, ['address','consent_text'], true) ? sanitize_textarea_field($raw) : (in_array($key, ['website','logo'], true) ? esc_url_raw($raw) : sanitize_text_field($raw));
        }
        if ($data['name'] === '' || $data['consent_text'] === '' || ($data['email'] !== '' && !is_email($data['email']))) {
            throw new InvalidArgumentException(esc_html__('Bitte Name, Vereinbarung und eine gültige Kontaktadresse angeben.', 'openmieterportal'));
        }
        update_option('bpm_branding', $data, false);
        BPM_Portal::event('portal_settings_updated');
    }
    public static function render(): void
    {
        if (!BPM_Portal::manager()) {
            return;
        }echo '<section class="bpm-card"><h2>' . esc_html__('Sicherheitsstatus', 'openmieterportal') . ('</h2><p>' . esc_html__('Getrennter Datenschlüssel:', 'openmieterportal') . ' ') . (BPM_Crypto::configured() ? esc_html__('eingerichtet', 'openmieterportal') : esc_html__('noch einzurichten', 'openmieterportal')) . ('<br>' . esc_html__('Verschlüsselte Tabellen:', 'openmieterportal') . ' ') . (get_option('bpm_data_encryption_required', false) ? esc_html__('aktiv', 'openmieterportal') : esc_html__('Migration ausstehend', 'openmieterportal')) . ('<br>' . esc_html__('Verschlüsselte Dokumente:', 'openmieterportal') . ' ') . (get_option('bpm_files_encryption_required', false) ? esc_html__('aktiv', 'openmieterportal') : esc_html__('Migration ausstehend', 'openmieterportal')) . ('<br>' . esc_html__('Zwei-Faktor-Pflicht für die Verwaltung:', 'openmieterportal') . ' ') . (get_option('bpm_manager_2fa_required', false) ? esc_html__('aktiv', 'openmieterportal') : esc_html__('noch einzurichten', 'openmieterportal')) . ('</p><p>' . esc_html__('E-Mail-Adressen in WordPress und SupportCandy sowie native Tickettexte bleiben von dieser Feldverschlüsselung ausgenommen. Daten- und Schlüsselbackups getrennt verwahren.', 'openmieterportal') . '</p></section>');
        $values = self::all();
        echo '<section class="bpm-card"><h2>' . esc_html__('Verwaltung, Briefkopf und Vereinbarung', 'openmieterportal') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        BPM_Portal::form('branding');
        foreach (['name' => __('Verwaltungsname', 'openmieterportal'),'logo' => __('Logo-URL', 'openmieterportal'),'address' => __('Absenderanschrift', 'openmieterportal'),'city' => __('Ort für Briefe', 'openmieterportal'),'phone' => __('Telefon', 'openmieterportal'),'email' => __('Kontakt-E-Mail', 'openmieterportal'),'website' => __('Website', 'openmieterportal'),'consent_text' => __('Kommunikationsvereinbarung', 'openmieterportal')] as $key => $label) {
            echo '<label>' . esc_html($label);
            if (in_array($key, ['address','consent_text'], true)) {
                echo '<textarea name="' . esc_attr($key) . '" rows="' . ($key === 'consent_text' ? '8' : '3') . '">' . esc_textarea($values[$key]) . '</textarea>';
            } else {
                echo '<input name="' . esc_attr($key) . '" maxlength="500" value="' . esc_attr($values[$key]) . '">';
            }
            echo '</label>';
        }
        echo '<p>' . esc_html__('Ein geänderter Vereinbarungstext benötigt eine neue Zustimmung. Bestehende Erklärungen behalten ihren ursprünglichen Wortlaut.', 'openmieterportal') . '</p><button type="submit">' . esc_html__('Einstellungen speichern', 'openmieterportal') . '</button></form></section>';
    }
}
