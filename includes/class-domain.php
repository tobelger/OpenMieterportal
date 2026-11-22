<?php
defined('ABSPATH') || exit;

final class BPM_Domain {
    public const CONSENT_VERSION = '2026-11-21-fassung-3';
    public const CONSENT_TEXT = 'Ich vereinbare mit der Verwaltung, dass gewöhnliche Mitteilungen sowie Dokumente zu meinem Mietverhältnis, insbesondere Betriebskostenabrechnungen und sonstige in Textform zulässige Schreiben, künftig ausschließlich elektronisch über das Mieterportal bereitgestellt werden. Für die hiervon erfassten Unterlagen genügt die elektronische Übermittlung; eine zusätzliche Übersendung als Papierbrief erfolgt nicht. Über neu bereitgestellte Dokumente erhalte ich eine Benachrichtigung an die hinterlegte E-Mail-Adresse. Ich halte diese Adresse aktuell, prüfe Benachrichtigungen und rufe die angekündigten Unterlagen zeitnah ab. Die Dokumente kann ich herunterladen und dauerhaft speichern. Gesetzlich vorgeschriebene Formen, besondere Zustellungsanforderungen und abweichende zwingende Vereinbarungen bleiben unberührt; das Portal ersetzt insbesondere keine formgerechte Kündigung. Das bloße Einstellen oder eine Benachrichtigung gilt nicht automatisch als rechtlicher Zugang. Der erste vom Server vollständig ausgegebene Dokumentabruf wird mit Datum, Uhrzeit und zugeordnetem Benutzerkonto protokolliert und der Mietpartei sowie der Verwaltung angezeigt. Die Mietpartei bestimmt den ihr zugeordneten Portalzugang als elektronische Empfangseinrichtung für die von dieser Vereinbarung erfassten Dokumente. Ein Dokument geht der Mietpartei rechtlich zu, wenn es tatsächlich so in ihren Empfangsbereich gelangt ist, dass sie unter gewöhnlichen Umständen davon Kenntnis nehmen kann; es muss vollständig abrufbar und dauerhaft speicherbar sein. Der protokollierte Abruf dokumentiert die Nutzung dieses Empfangswegs. Die gesetzlichen Voraussetzungen des Zugangs und die gesetzliche Beweislast bleiben maßgeblich. Bei Störungen, ausbleibender Benachrichtigung oder fehlender Erreichbarkeit bleibt eine andere geeignete Übermittlung möglich. Meine Zustimmung ist freiwillig, gilt nur bei entsprechender Vertretungsberechtigung für die Mietpartei und kann für die Zukunft im Portal oder gegenüber der Verwaltung widerrufen werden. Ohne Zustimmung oder nach Widerruf bleiben andere geeignete Kommunikationswege bestehen.';

    public static function money_cents(string $raw): int {
        $raw = trim($raw);
        if (!preg_match('/^[0-9]{1,7}(?:[.,][0-9]{1,2})?$/D', $raw)) {
            throw new InvalidArgumentException('Bitte einen positiven Eurobetrag mit höchstens zwei Nachkommastellen angeben (z. B. 750,00).');
        }
        $parts = preg_split('/[.,]/', $raw);
        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }
    public static function tenancy_date(string $raw): ?string {
        if ($raw === '') { return null; }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('Europe/Berlin'));
        if (!$d || $d->format('Y-m-d') !== $raw || $raw < '1900-01-01' || $raw > '2199-12-31') {
            throw new InvalidArgumentException('Bitte ein gültiges Datum angeben.');
        }
        return $raw;
    }
    public static function access_deadline(?string $end): ?string {
        if (!$end) { return null; }
        self::tenancy_date($end);
        $d = new DateTimeImmutable($end, new DateTimeZone('Europe/Berlin'));
        $first = $d->modify('first day of this month')->modify('+25 months');
        $day = min((int) $d->format('d'), (int) $first->format('t'));
        return $first->setDate((int) $first->format('Y'), (int) $first->format('m'), $day)->format('Y-m-d');
    }
    public static function tenancy_data(array $raw): array {
        $data = [];
        foreach (['tenancy_start', 'tenancy_end', 'notice_received'] as $key) {
            $data[$key] = self::tenancy_date(trim((string) ($raw[$key] ?? '')));
        }
        $data['termination_by'] = (string) ($raw['termination_by'] ?? '');
        if (!in_array($data['termination_by'], ['', 'mieter', 'vermieter', 'aufhebung'], true)) {
            throw new InvalidArgumentException('Bitte eine gültige Beendigungsart auswählen.');
        }
        if ($data['tenancy_start'] && $data['tenancy_end'] && $data['tenancy_end'] < $data['tenancy_start']) {
            throw new InvalidArgumentException('Das Mietende darf nicht vor dem Mietbeginn liegen.');
        }
        if (($data['notice_received'] || $data['termination_by']) && (!$data['notice_received'] || !$data['termination_by'] || !$data['tenancy_end'])) {
            throw new InvalidArgumentException('Für eine Kündigung bitte Eingangsdatum, Beendigungsart und Mietende angeben.');
        }
        return $data;
    }
    public static function invitation_token(): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $bytes = random_bytes(16);
        $code = '';
        for ($i = 0; $i < 16; $i++) { $code .= $alphabet[ord($bytes[$i]) & 31]; }
        return $code;
    }
    public static function normalize_invitation(string $code): string {
        $code = trim($code);
        return strlen($code) === 64 ? strtolower($code) : strtoupper($code);
    }
    public static function valid_invitation(string $code): bool {
        return (bool) preg_match('/^(?:[A-HJ-NP-Z2-9]{16}|[a-f0-9]{64})$/D', $code);
    }
    public static function token(): string { return bin2hex(random_bytes(32)); }
    public static function token_hash(string $token): string { return hash('sha256', $token); }

    public static function reading(string $raw): string {
        $raw = str_replace(',', '.', trim($raw));
        if (!preg_match('/^\d{1,10}(\.\d{1,3})?$/D', $raw)) {
            throw new InvalidArgumentException('Bitte einen positiven Zählerstand mit höchstens drei Nachkommastellen eingeben.');
        }
        return number_format((float) $raw, 3, '.', '');
    }

    public static function reading_display(string $value): string {
        return number_format((float) $value, 3, ',', '.');
    }

    public static function date(string $raw): string {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('Europe/Berlin'));
        if (!$date || $date->format('Y-m-d') !== $raw || $raw > wp_date('Y-m-d') || $raw < '2000-01-01') {
            throw new InvalidArgumentException('Bitte ein gültiges tatsächliches Ablesedatum angeben, das nicht in der Zukunft liegt.');
        }
        return $raw;
    }

    public static function within(string $path, string $parent): bool {
        $parent = rtrim(str_replace('\\', '/', $parent), '/') . '/';
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        return strncmp($path, $parent, strlen($parent)) === 0;
    }

    public static function allowed(int $owner, int $household, bool $manager = false): bool {
        return $manager || ($owner > 0 && $owner === $household);
    }
}
