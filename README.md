# OpenMieterportal

Ein quelloffenes WordPress-Plugin für die Verwaltung von Mietverhältnissen. Ein gemeinsamer Zugang je Mietpartei verbindet Vertragsübersicht, geschützte Dokumente, Anliegen und Zählerstände in einer bestehenden WordPress-Installation.

Lizenz: **GPL-2.0-or-later**. Es sind keine kostenpflichtigen Erweiterungen erforderlich.

## Funktionen

- Gebäude, Wohnungen und Mietverhältnisse; ein Konto je Mietpartei.
- Einladung per Brief mit lokal erzeugtem QR-Code, einmaligem 16-Zeichen-Code und 30 Tagen Gültigkeit.
- Vertragsübersicht mit Mietzeit, Kündigung und zeitabhängigen Mietbeträgen.
- Messstellen mit austauschbaren Geräten und Gültigkeitszeiträumen; Ablesungen von Mietern, Verwaltung oder Messdienst.
- Private PDF-Ablage mit serverseitiger Zugriffskontrolle. Vor jedem PDF-Abruf werden SHA-256 und Dateigröße kontrolliert; ausgeliefert werden genau die geprüften Bytes.
- Bereitgestellte PDFs sind im Portal nicht bearbeitbar oder ersetzbar. Korrekturen werden als neue Dokumente bereitgestellt.
- Protokoll des ersten vollständigen PDF-Abrufs durch die Mietpartei.
- SupportCandy für Anliegen und Antworten, mit anonymen Gästen gesperrt und Bindung an den jeweiligen Portalzugang.
- Registerkarten und ein Kontomenü für Profil, Einstellungen und Abmelden.
- Verschlüsselt gespeicherte Anschrift, Telefonnummer und Bankverbindung. Kontoinhaber und gültige IBAN sind Pflicht beim Speichern der Profilangaben.
- E-Mail-Wechsel mit Passwortprüfung und einmaliger Bestätigung der neuen Adresse; die alte Adresse bleibt bis dahin aktiv.
- Passwortprüfung bei Änderungen der Bankverbindung; sofortiger allgemeiner Sicherheitshinweis ohne Bankdaten.
- Benachrichtigungen sofort, täglich oder wöchentlich. Bestätigungs- und Sicherheitsmails werden sofort versendet.
- Versionierte Kommunikationswahl und Zugang längstens 25 Monate nach Mietende.

## Abhängigkeiten

| Bestandteil | Anforderung |
| --- | --- |
| WordPress | Ab 6.6, mit MySQL/MariaDB in einer von WordPress unterstützten Version |
| PHP | Ab 8.1; `fileinfo`, `mbstring`, `openssl`, `gd` für Fotos |
| [SupportCandy](https://wordpress.org/plugins/supportcandy/) | Separat installieren und aktivieren; kostenlose Basisversion, getestete Integration 3.5.6 |
| QR-Code-Erzeugung | `qrcode-generator` 1.4.4, MIT-Lizenz, lokal enthalten |
| E-Mail | Funktionierender WordPress-Mailversand; SMTP über einen passenden Mailserver empfohlen |
| Aufgaben | WP-Cron; für zuverlässige Sammelhinweise regelmäßig durch einen Hosting-Cron ausführen lassen |

**Divi, Timber, WPForms und Contact Form 7 sind keine Abhängigkeiten des Portalplugins.** Sie können Bestandteile der umgebenden Website sein. SupportCandy wird nicht mitgeliefert oder verändert und erhält seine Updates über WordPress.

## Installation und Einrichtung

Das Installationspaket wird mit `python3 tools/build-package.py /path/to/OpenMieterportal.zip` gebaut. Es enthält den neutralen Ordner `openmieterportal`, aber keine Tests, CI-Dateien oder Werkzeuge. Der technische PHP-Einstieg bleibt aus Kompatibilitätsgründen erhalten. Bestehende Installationen im alten Ordner dürfen den Ordner bei einem manuellen Update beibehalten; nicht beide Kopien gleichzeitig aktivieren. Für WordPress.org-Updates ist der kanonische Ordner zu verwenden.

Die Oberfläche verwendet deutsche Ausgangstexte mit WordPress-Übersetzungsfunktionen; der POT-Katalog liegt unter `languages/`. Der WordPress-Datenschutzassistent erhält eine technische Formulierungshilfe, ohne eine veröffentlichte Erklärung zu verändern.

1. SupportCandy aus dem WordPress-Pluginverzeichnis installieren und aktivieren.
2. Plugin-ZIP über **Plugins > Plugin hinzufügen > Plugin hochladen** installieren. Bei einem Update den bestehenden technischen Pluginordner beibehalten.
3. Geschützten Speicher außerhalb sämtlicher öffentlich erreichbarer Verzeichnisse anlegen. Empfohlen: Verzeichnis `0700`, Eigentümer der PHP-Prozess.
4. In `wp-config.php`, vor `wp-settings.php`, konfigurieren:

```php
define('BPM_PRIVATE_DIR', '/absoluter/privater/pfad/portal-dokumente');
// Falls das Hosting ein öffentliches Hauptverzeichnis oberhalb von WordPress hat:
define('BPM_PUBLIC_ROOT', '/absoluter/oeffentlicher/hosting-hauptordner');
```

5. Auf einer Testkopie aktivieren. Eine neue Portalseite mit `[openmieterportal]` wird zunächst privat angelegt.
6. Eine eigene Aktivierungsseite mit demselben Shortcode anlegen und ihre ID in `bpm_activation_page_id` hinterlegen, beispielsweise über WP-CLI:

```sh
wp option update bpm_activation_page_id 123
wp option update bpm_supportcandy_enabled production
```

`123` ist durch die tatsächliche Seiten-ID zu ersetzen. Die bestehende Portal-ID steht in `bpm_page_id`. SupportCandy-Kategorien und Mailbenachrichtigungen separat einrichten.

7. Portal und Aktivierungsseite nach der Prüfung veröffentlichen. Portal, Aktivierung und Downloadendpunkte von Seiten-/CDN-Caches ausnehmen. HTTPS einsetzen.
8. Vor Versand von Einladungen Registrierung, eigene und fremde Dokumentabrufe, Mailzustellung und Wiederherstellung mit erfundenen Testkonten prüfen.

### Briefvorlage und technische Kompatibilität

Verwaltungsname, Anschrift, Logo, Kontaktdaten und Kommunikationsvereinbarung werden in der Portalverwaltung eingestellt. Das Plugin verändert keine Theme-Menüs, Widgets oder Divi-Seitenvorlagen. Die Veröffentlichung enthält keine Mieterdaten oder Zugangsdaten.

OpenMieterportal verwendet den Shortcode `[openmieterportal]`. Bestehende Installationen und bereits eingerichtete Seiten bleiben bei einem Update kompatibel; vorhandene Datenbanktabellen werden beibehalten.

## Betrieb

- PDFs: höchstens 15 MB. Fotos: JPG/PNG, höchstens 8 MB und 12 Megapixel; Bilder werden neu codiert, EXIF/GPS werden nicht übernommen.
- PHP-Konfiguration: `upload_max_filesize` mindestens 16M, `post_max_size` mindestens 20M. Eine gepflegte PHP-Version verwenden.
- Die optionale Sicherheitsmigration verschlüsselt eigene sensible Tabellenfelder, Profile und Portaldateien mit AES-256-GCM und einem separaten Schlüsselring. Datensatz-/Benutzer-/Dateikontext ist authentifiziert. Einrichtung, Grenzen, Schlüsselwechsel und Wiederherstellung: [SECURITY.md](SECURITY.md). Ohne Einrichtung des separaten Schlüssels bleiben alte Installationen im bisherigen Speichermodus; ein Update allein verschlüsselt den Bestand nicht.
- Sammelhinweise werden stündlich geprüft und frühestens nach 24 Stunden bzw. sieben Tagen seit dem ersten vorgemerkten Ereignis gesendet. WP-Cron kann bei geringer Besuchsfrequenz verspätet laufen. Fehlgeschlagene Hinweise bleiben für einen erneuten Versuch vorgemerkt.
- Die Integritätsprüfung erkennt Änderungen gegenüber der gespeicherten Prüfsumme. Sie ist kein unabhängiger unveränderbarer Archivspeicher und keine allgemeine Zusage rechtlicher Konformität.
- Eine hinterlegte Bankverbindung ist kein SEPA-Lastschriftmandat. Die Vorlage der Kommunikationsvereinbarung ist für den jeweiligen Einsatz zu prüfen.
- Es gibt keine automatische Übernahme von Portaländerungen in WISO oder andere Buchhaltungssysteme.

## Entwicklung und Tests

GitHub Actions führt Syntax-, Konto-, Verschlüsselungs-, Dateiintegritäts- und Ticket-Empfängertests mit einer frischen lokalen WordPress-/MySQL-Instanz aus. Der Workflow hat nur Leserechte und keine Produktionszugänge oder automatische Bereitstellung.

Selbstständige Prüfungen ohne WordPress:

```sh
php tests/test-domain.php
php tests/test-reading-display.php
php tests/test-storage.php
php tests/test-document-integrity.php
```

Der Account-Integrationstest benötigt eine **ausschließlich lokale, wegwerfbare WordPress-Testinstallation**. Den Pfad zur `wp-load.php` über `BPM_TEST_WP_LOAD` angeben. Der Test erzeugt und entfernt ausschließlich eigene Testkonten, fängt alle E-Mails ab und lehnt nicht lokale Installationen ab.

```sh
BPM_TEST_WP_LOAD=/path/to/local/wordpress/wp-load.php php tests/test-account.php
```

Der Verschlüsselungstest benötigt ebenfalls die isolierte lokale WordPress-Datenbank, erzeugt nur synthetische Daten und fängt E-Mails ab:

```sh
BPM_TEST_WP_LOAD=/path/to/local/wordpress/wp-load.php php tests/test-encryption.php
```

Weitere Tests der konkreten Hosting- und Theme-Integration bleiben umgebungsspezifisch. Bei Änderungen insbesondere die Trennung der Mietparteien, E-Mail-Bestätigung, Dateischutz und SupportCandy-Zugriffe erneut prüfen.

## Lizenz und Drittsoftware

GPL-2.0-or-later, siehe [LICENSE](LICENSE). Für `qrcode-generator` gilt die MIT-Lizenz, siehe [THIRD-PARTY-NOTICES.txt](THIRD-PARTY-NOTICES.txt). WordPress und SupportCandy werden separat bezogen und besitzen eigene Lizenzen und Updatewege.
