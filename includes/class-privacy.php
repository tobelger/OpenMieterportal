<?php

/** Privacy guide content. This never changes a published policy automatically. */

defined('ABSPATH') || exit;
final class BPM_Privacy
{
    public static function boot(): void
    {
        add_action('admin_init', [self::class, 'suggest']);
    }
    public static function suggest(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        $paragraphs = [
            __('Das Mieterportal verarbeitet Vertrags- und Kontaktdaten, Bankangaben, Mietbeträge, Dokumente, Zählerstände, Anliegen, Kommunikationsentscheidungen und Abruf-/Sicherheitsereignisse. Eine Mietpartei verwendet einen gemeinsamen Zugang. Die Verwaltung und berechtigte Mitglieder dieser Mietpartei können die jeweils zugeordneten Inhalte abrufen.', 'openmieterportal'),
            __('Benachrichtigungen und Kontobestätigungen werden über den eingerichteten WordPress-Maildienst versendet. Dessen Anbieter erhält Empfängeradresse und Mailinhalt. QR-Codes werden lokal erzeugt. Das Portal enthält keine eigene Telemetrie und nutzt keinen externen QR-Dienst.', 'openmieterportal'),
            __('Nach gesonderter Einrichtung und Migration werden ausgewählte eigene Datenfelder und private Portaldateien verschlüsselt gespeichert. Native WordPress-/SupportCandy-E-Mail-Adressen, Tickettexte und -anhänge sowie bestimmte Metadaten sind davon nicht vollständig erfasst. Mailprotokolle und Sicherungen benötigen eigene Zugriffskontrollen und Aufbewahrungsregeln.', 'openmieterportal'),
            __('Der erste vollständig ausgegebene Dokumentabruf wird mit Zeitpunkt und Benutzerkonto gespeichert. Dies ist eine technische Abrufdokumentation. Eine automatische Zugriffssperre spätestens 25 Monate nach dem erfassten Mietende ist keine automatische Löschung aufbewahrungspflichtiger Unterlagen. Deaktivieren oder Entfernen des Plugins löscht die vorhandenen Datensätze und privaten Dateien nicht.', 'openmieterportal'),
            __('Ergänzen Sie für Ihren Betrieb insbesondere Verantwortliche, konkrete Empfänger und Dienstleister, Zwecke, Rechtsgrundlagen, Aufbewahrungsfristen, Betroffenenrechte und Kontaktwege. Diese technische Beschreibung ist eine Formulierungshilfe und keine vollständige Datenschutzerklärung.', 'openmieterportal'),
        ];
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $html .= '<p>' . esc_html($paragraph) . '</p>';
        }
        wp_add_privacy_policy_content('OpenMieterportal', $html);
    }
}
