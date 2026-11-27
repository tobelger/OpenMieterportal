# Änderungen

## 0.7.4

- SupportCandy-Empfänger werden vor der nativen Empfängerprüfung gefiltert. Unterdrückte Test- und Sammelbenachrichtigungen erzeugen keine empfängerlosen SMTP-Aufträge.
- Regressionstest mit 26 Prüfungen gegen SupportCandys eigenen Validierungsablauf.

## 0.7.3

- Optionaler Hinweis auf die Nutzung des Portals statt Antworten auf Benachrichtigungsmails.
- Gemeinsame Mailfunktion für Aktivierung, Kontobestätigung und Benachrichtigungen. SMTP-Zugangsdaten und Absender bleiben außerhalb des Plugin-Codes in der Mailplugin-Konfiguration.

## 0.7.2

- Produktbezeichnung OpenMieterportal in der Dokumentation vereinheitlicht.
- Neuer Shortcode `[openmieterportal]`, einschließlich Styles und Schutz-Headern. Bestehende Installationen bleiben kompatibel.

## 0.7.1

- Kontomenü mit Personensymbol, Profil, Einstellungen und Abmelden.
- Vertragsübersicht von persönlichen Kontaktdaten getrennt.
- Sofortige Profilpflege, Pflichtprüfung von Kontoinhaber und IBAN; verschlüsselte Speicherung.
- E-Mail-Änderungen mit aktuellem Passwort und einmaliger Bestätigung, 24 Stunden gültig.
- Sofortige, tägliche und wöchentliche Benachrichtigungen; Wiederholungen fehlgeschlagener Sammelhinweise.
- SupportCandy misst nach dem Öffnen der Nachrichtenkarte die sichtbare Breite neu. Das verhindert eine fälschlich mobile Navigation beim ersten Aufruf.
- Kontomenü-Wechsel werden vor Divis Ankersteuerung verarbeitet.

## 0.6.4

- SHA-256- und Größenprüfung vor jedem PDF-Download.
- Verifizierte Momentaufnahme zur Auslieferung; direkte Schreibrechte für bereitgestellte PDFs entzogen.

## 0.6.3

- Registerkarten mit Tastaturbedienung und direkten Links.
- Browser-Zurück/Vorwärts berücksichtigt die tatsächlich besuchte Registerkarte.
- Kein automatischer Scrollsprung beim Wechseln.

## 0.6.0

- Wohnungen direkt bearbeitbar.
- Feste Messstellen und wechselnde Zählergeräte mit Gültigkeitszeiträumen getrennt.
