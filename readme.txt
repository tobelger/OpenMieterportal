=== OpenMieterportal ===
Contributors: tobelger
Requires at least: 6.6
Requires PHP: 8.1
Requires Plugins: supportcandy
Stable tag: 0.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ein gemeinsamer Zugang je Mietpartei in WordPress, mit geschützten Dokumenten, Vertragsübersicht, Profil, Anliegen und Zählerverwaltung.

== Funktionen ==
* Gebäude und Wohnungen mit zugeordneten Mietverhältnissen.
* Ein gemeinsames Konto je Mietpartei. Einmalige Einladung mit 16 Zeichen, 30 Tage gültig, QR-Brief.
* Vertragsübersicht mit Mietzeiten, Mietbeträgen, Gültigkeit und Kündigung.
* Messstellen, wechselnde Zählergeräte mit Gültigkeitszeiträumen und Ablesungen.
* Geschützte PDF-Ablage, berechtigungsgeprüfte Downloads, SHA-256-Prüfung vor jeder PDF-Auslieferung.
* Keine Ersetzen-Funktion für bereitgestellte Dokumente; Korrekturen sind neue Dokumente.
* Erster vollständiger PDF-Abruf mit Zeitpunkt und Konto-ID.
* SupportCandy für Anliegen und Antworten. Keine kostenpflichtigen Add-ons erforderlich.
* Registerkarten und Kontomenü mit Profil, Einstellungen und Abmelden.
* Sofort gepflegte Anschrift, Telefonnummer und Bankverbindung; Kontoinhaber und gültige IBAN sind beim Speichern Pflicht.
* Verschlüsselte Profildaten. E-Mail-Wechsel erst nach Passwortprüfung und einmaliger, 24 Stunden gültiger Bestätigung.
* Benachrichtigungen sofort, als täglicher oder als wöchentlicher Sammelhinweis.
* Versionierte Kommunikationsvereinbarung; Zugang längstens 25 Monate nach Mietende.

== Abhängigkeiten ==
WordPress ab 6.6, PHP ab 8.1 mit fileinfo, mbstring, OpenSSL und GD (für Fotos), sowie eine von WordPress unterstützte MySQL-/MariaDB-Datenbank.
SupportCandy (kostenloses WordPress-Plugin, Slug supportcandy) wird separat installiert und aktualisiert. Getestete Integration: SupportCandy 3.5.6.
Die Hauptfunktionen benötigen weder Divi noch Timber, WPForms oder einen externen QR-Dienst.

== Installation ==
1. SupportCandy über die WordPress-Pluginverwaltung installieren und aktivieren.
2. OpenMieterportal als ZIP über Plugins > Plugin hinzufügen > Plugin hochladen installieren.
3. Einen privaten Speicherordner außerhalb aller öffentlich erreichbaren Webverzeichnisse anlegen (0700, Eigentümer PHP-Prozess).
4. In wp-config.php vor wp-settings.php konfigurieren:
   define('BPM_PRIVATE_DIR', '/absoluter/privater/pfad/portal-dokumente');
   Falls der öffentliche Hosting-Hauptordner über dem WordPress-Ordner liegt, zusätzlich BPM_PUBLIC_ROOT auf diesen Hauptordner setzen.
5. Plugin auf einer Testkopie aktivieren. Die Seite mit [openmieterportal] ist bei einer Neuinstallation zunächst privat.
6. Eine separate Aktivierungsseite mit demselben Shortcode anlegen; deren ID in der WordPress-Option bpm_activation_page_id hinterlegen. Permalink zum Beispiel /activate/.
7. Portal und Aktivierungsseite nach der Funktionsprüfung veröffentlichen, ins Menü aufnehmen und von Seiten-/CDN-Caches ausschließen.
8. SupportCandy für die produktive Integration konfigurieren und die Option bpm_supportcandy_enabled auf production setzen.
9. Einladungen, eigene/fremde Downloads, Mailzustellung und Wiederherstellung mit Testkonten prüfen.

Bestehende Installationen bleiben kompatibel. Bereits verwendete technische Bezeichner und Datenbanktabellen werden bei einem Update beibehalten.
Die derzeitige Briefvorlage enthält noch die ursprüngliche Verwalterkennzeichnung und muss vor Nutzung für andere Verwaltungen angepasst werden.

== Betrieb und Sicherheit ==
HTTPS verwenden. PHP-Limits: Upload mindestens 16M, POST mindestens 20M. PDFs maximal 15 MB, Fotos maximal 8 MB und 12 Megapixel.
Der Speicher hat absichtlich keinen öffentlichen Upload-Fallback. Dateien werden ausschließlich nach Berechtigungsprüfung ausgeliefert.
Die Integritätsprüfung verwendet den beim Upload gespeicherten Hash. Sie ersetzt keinen externen unveränderbaren Archivspeicher.
Profildaten werden mit AES-256-GCM verschlüsselt. Der Schlüssel wird aus dem WordPress-Auth-Salt abgeleitet. wp-config.php und Salts müssen zusammen mit den Daten gesichert werden; eine Salt-Rotation benötigt eine vorherige Migration der verschlüsselten Profile.
WP-Cron verarbeitet Sammelhinweise stündlich. Tägliche bzw. wöchentliche Hinweise werden frühestens 24 Stunden bzw. sieben Tage nach dem ersten vorgemerkten Ereignis versendet. Für zuverlässige Ausführung einen Hosting-Cron für WordPress einrichten. Fehlgeschlagene Hinweise bleiben zur Wiederholung vorgemerkt.
Kontobestätigungen und Sicherheitsmails werden unabhängig vom gewählten Rhythmus sofort gesendet.
Eine Bankangabe ist kein SEPA-Lastschriftmandat. Kommunikationsvereinbarungen, Aufbewahrungsfristen und Formvorgaben sind für den jeweiligen Einsatz zu prüfen.

== Changelog ==

= 0.8.1 =
* Lange Bezeichnungen der Mietbestandteile ohne Überlappung darstellen.

= 0.8.0 =
* Betriebskosten- und Heizkostenvorauszahlung sowie optionale Garagenmiete ausweisen.
= 0.7.4 =
* SupportCandy-Empfänger werden vor der nativen Empfängerprüfung gefiltert. Unterdrückte Test- und Sammelbenachrichtigungen erzeugen keine empfängerlosen SMTP-Aufträge mehr.

== Lizenz ==
GPL-2.0-or-later. qrcode-generator steht unter MIT; siehe THIRD-PARTY-NOTICES.txt.
