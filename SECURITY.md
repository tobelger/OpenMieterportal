# Sicherheitsbetrieb und Meldungen

Keine Zugangsdaten, Dokumente, Bankdaten oder echten Mieterdaten in öffentliche Issues schreiben. Fehler vertraulich über GitHubs private Sicherheitsmeldung melden, sofern aktiviert; andernfalls zunächst ohne Angriffsdaten um einen vertraulichen Kontaktweg bitten. Diese Prüfungen sind kein unabhängiges Sicherheitsgutachten.

## Zugriff und Grenzen

Alle Portaldownloads benötigen ein angemeldetes, zugangsberechtigtes Konto, einen passenden Nonce und die serverseitige Datensatzzuteilung. Private Dateien haben keinen öffentlichen Upload-Fallback. Vor PDF-Auslieferung werden die entschlüsselten Bytes gegen SHA-256 und Länge geprüft. Bereitgestellte PDFs sind im Portal nicht bearbeitbar oder ersetzbar; Korrekturen sind neue Dokumente. Ein Administrator mit Server-/Datenbankzugriff kann diese Sicherungen grundsätzlich verändern.

Die optionale Verschlüsselung schützt bei einem isolierten Datenbank-/Dateiabzug ohne Schlüssel. Sie schützt nicht vor einem vollständig übernommenen WordPress/PHP-/Hostingkonto, das den Schlüssel lesen und die Anwendung ausführen kann. Ein schreibender Datenbankangreifer kann außerdem Benutzerrechte oder WordPress-Konfiguration manipulieren.

Nach der expliziten Migration verschlüsselt das Plugin:

- eigene Gebäudeanschriften/-bezeichnungen, Wohnungsbezeichnungen, Namen und erwartete Kontaktadresse der Mietparteien;
- Mietbeträge, Dokumenttitel/-dateinamen, Zählerstandswerte sowie eigene alte Nachrichtentexte/-betreffzeilen;
- Kontoprofile mit Anschrift, Telefon und Bankangaben;
- PDFs und Fotos im eigenen privaten Dokumentenspeicher.

**Nicht vollständig verschlüsselt:** native WordPress- und SupportCandy-E-Mail-Adressen, native SupportCandy-Ticketbetreffzeilen/-texte/-anhänge, Vertragsreferenzen, Geräte-/Messstellendaten, IDs, Zuordnungen, Zeitpunkte und Ablauf-/Einwilligungsmetadaten. SupportCandy bleibt unverändert. WordPress-/SMTP-Protokolle, fremde Plugins, alte Backups und externe Brief-PDFs benötigen eigene Schutz- und Aufbewahrungsregeln. Bei der Migration werden verknüpfte WordPress-/SupportCandy-Anzeigenamen pseudonymisiert; die fachliche Namensanzeige im Portal bleibt erhalten.

## Schlüssel einrichten und Bestand migrieren

1. Aktuelle Datenbank, private Dateien, Plugin und Konfiguration sichern; Sicherung verschlüsseln. Wiederherstellung prüfen. Schreibzugriffe während der Migration durch einen Wartungsmodus verhindern.
2. Einen privaten Schlüsselordner außerhalb **aller tatsächlich öffentlich erreichbaren Webverzeichnisse** anlegen (`0700`). Wenn der Hoster nur einen übergeordneten Webspace zulässt, zusätzlich dessen HTTP-Zugriff auf diesen Ordner serverseitig vollständig sperren und mit harmlosen Testdateien über sämtliche Domain-/Hosting-Aliasse prüfen. `BPM_PUBLIC_ROOT` darf nicht auf einen bloß angenommenen Pfad zeigen.
3. Eine JSON-Schlüsseldatei (`0600`, keine symbolische Verknüpfung) erzeugen. Schema: `{"active":"16 hex characters","keys":{"same key id":"base64 encoded random 32 byte key"},"legacy_auth_salt":"previous wp_salt(auth)"}`. ID und Schlüssel mit `random_bytes()` erzeugen. `legacy_auth_salt` ist nur für die Übernahme alter v1-Kontoprofile erforderlich und muss vor einer Saltänderung gesichert werden. Keine Schlüssel in Git, WordPress-Optionen, Logs oder Screenshots.
4. Vor `wp-settings.php` in der privaten Serverkonfiguration `define('BPM_KEY_FILE', '/absolute/private/path/keys.json');` setzen. Der Schlüsselring ist unabhängig von WordPress-Salts. Die Anwendung lehnt bekannte öffentliche Pfade und für Gruppe/Andere lesbare Schlüssel ab.
5. Mit WP-CLI ausführen: `wp openmieterportal security-migrate`. Die Migration ergänzt `protected_data`, verschlüsselt den Bestand und prüft die entschlüsselten Werte. Private Dateien werden einzeln über temporäre verschlüsselte Dateien atomar ersetzt. Bei Fehlern Wartungsmodus beibehalten und Ursache prüfen oder vollständige Sicherung zurückspielen. Bereits migrierte und noch alte Daten können während einer unterbrochenen Migration nebeneinander gelesen werden; erfolgreicher Abschluss setzt den verschlüsselten Pflichtmodus.
6. Eigene und fremde Downloads mit getrennten Testkonten, Suche, Kontoprofil und Zählerstände prüfen. Erst danach Wartungsmodus beenden. Die Migration ist nur über WP-CLI verfügbar und darf nicht in normalen Seitenanfragen laufen.

AES-256-GCM verwendet zufällige 96-Bit-Nonces und 128-Bit-Tags. Der authentifizierte Kontext bindet Tabellenname/ID, Benutzer-ID oder Dateischlüssel. Manipulationen, vertauschte Datensätze und fehlende Schlüssel werden abgewiesen. Klartext-PDFs werden nach Berechtigungsprüfung im Arbeitsspeicher bereitgestellt.

## Wechsel und Wiederherstellung

`wp openmieterportal security-rotate` ergänzt einen neuen zufälligen Schlüssel und verschlüsselt den Bestand neu. Alte Schlüssel bleiben für alte Sicherungen erhalten. Nicht während normaler Schreibzugriffe ausführen. Nach jedem Wechsel den vollständigen Schlüsselring erneut separat sichern.

Daten-/Dateisicherung und Schlüsselring getrennt und offline verwahren; Zugriff beschränken. Ohne passenden Schlüssel sind verschlüsselte Daten nicht wiederherstellbar. Eine Sicherung muss auch erforderliche WordPress-/SMTP-Konfiguration enthalten. Bei Rückkehr zu einer alten Pluginversion die zusammengehörige Datenbank, Dateien und Konfiguration zurückspielen: Nur alte Plugin-Dateien einzuspielen entschlüsselt den Bestand nicht. Nach Restore mit Testkonten prüfen, bevor Benachrichtigungen/Cron wieder freigegeben werden. Niemals eine produktive Datenbank in eine ungeschützte Testinstallation kopieren.

## Anmeldung und Änderungen

Anmeldung, Aktivierung, schreibende Portalaktionen und Downloads werden zusätzlich zu vorhandenen WordPress-Schutzplugins begrenzt. Das begrenzt automatisierte Angriffe; es ist keine Zusage, Angriffe anhand ihres KI-Einsatzes zu erkennen. Sitzungen sind begrenzt. Bankänderungen benötigen das aktuelle Passwort und lösen sofort einen allgemeinen Sicherheitshinweis ohne Bankdaten aus. E-Mail-Wechsel benötigt Passwort und Bestätigung der neuen Adresse.

Optional das kostenlose offizielle Plugin [Two Factor](https://wordpress.org/plugins/two-factor/) installieren und aktivieren, valide Verwaltungsadressen und Mailzustellung prüfen und `wp option update bpm_manager_2fa_required 1` setzen. Dann ist mindestens dessen E-Mail-Einmalcode für `bpm_manage`-Konten vorgeschrieben. Authenticator/Backupcodes können im Standard-WordPress-Profil eingerichtet werden. Ein geprüfter Wiederherstellungszugang muss bestehen. Der öffentliche Kontoname oder eine versteckte Login-URL ist kein Sicherheitsfaktor.

Regelmäßige Updates, getestete verschlüsselte Backups, sparsame Protokollierung und Prüfung der Drittplugins bleiben notwendig.
