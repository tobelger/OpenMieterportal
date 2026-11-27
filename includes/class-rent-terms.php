<?php
defined('ABSPATH') || exit;

final class BPM_Rent_Terms {
    public const COSTS = [
        'operating' => 'Betriebskostenvorauszahlung (BKV)',
        'heating' => 'Heizkostenvorauszahlung (HK)',
        'garage' => 'Garagenmiete',
    ];

    public static function amounts(object $term): array {
        $amounts = ['Nettokaltmiete' => (int) $term->rent_cents];
        if (isset($term->operating_cents, $term->heating_cents)) {
            $amounts[self::COSTS['operating']] = (int) $term->operating_cents;
            $amounts[self::COSTS['heating']] = (int) $term->heating_cents;
        } else {
            $amounts['Nebenkostenvorauszahlung'] = (int) $term->advance_cents;
        }
        if (!empty($term->garage_cents)) {
            $amounts[self::COSTS['garage']] = (int) $term->garage_cents;
        }
        return $amounts;
    }

    private static function form_amounts(array $data, ?object $existing): array {
        $rent = BPM_Domain::money_cents((string) ($data['rent_amount'] ?? ''));
        $advance = trim((string) ($data['advance_amount'] ?? ''));
        $operating = trim((string) ($data['operating_amount'] ?? ''));
        $heating = trim((string) ($data['heating_amount'] ?? ''));
        $garage = trim((string) ($data['garage_amount'] ?? ''));

        $amounts = ['rent_cents' => $rent];
        if ($garage !== '') {
            $amounts['garage_cents'] = BPM_Domain::money_cents($garage);
        }
        if ($operating === '' && $heating === '') {
            $cents = BPM_Domain::money_cents($advance);
            if (isset($existing->operating_cents, $existing->heating_cents) && $cents !== (int) $existing->advance_cents) {
                throw new InvalidArgumentException('Bitte Betriebskosten und Heizkosten getrennt angeben, um die vorhandene Aufteilung zu ändern.');
            }
            return $amounts + ['advance_cents' => $cents];
        }
        if ($operating === '' || $heating === '') {
            throw new InvalidArgumentException('Bitte Betriebskosten und Heizkosten angeben, bei Bedarf mit 0,00 Euro.');
        }
        $operating_cents = BPM_Domain::money_cents($operating);
        $heating_cents = BPM_Domain::money_cents($heating);
        $combined = $operating_cents + $heating_cents;
        if ($advance !== '' && BPM_Domain::money_cents($advance) !== $combined) {
            throw new InvalidArgumentException('Die Gesamtsumme der Vorauszahlung muss Betriebskosten und Heizkosten entsprechen.');
        }
        return $amounts + ['advance_cents' => $combined, 'operating_cents' => $operating_cents, 'heating_cents' => $heating_cents];
    }

    public static function save(object $household, array $input): void {
        global $wpdb;
        $effective = BPM_Domain::tenancy_date(trim((string) ($input['effective_from'] ?? '')));
        if (!$effective) {
            throw new InvalidArgumentException('Bitte das Gültigkeitsdatum angeben.');
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException('Die Speicherung konnte nicht gestartet werden.');
        }
        try {
            $table = BPM_Portal::table('rent_terms');
            $households = BPM_Portal::table('households');
            if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $households WHERE id=%d FOR UPDATE", $household->id))) {
                throw new InvalidArgumentException('Die Mietpartei wurde nicht gefunden.');
            }
            $existing = BPM_Data::row($wpdb->prepare("SELECT * FROM $table WHERE household_id=%d AND effective_from=%s FOR UPDATE", $household->id, $effective));
            $data = self::form_amounts($input, $existing) + [
                'household_id' => (int) $household->id,
                'effective_from' => $effective,
                'updated_at' => BPM_Portal::now(),
                'actor_id' => get_current_user_id(),
            ];
            if ($existing) {
                if (BPM_Data::update($table, $data, ['id' => $existing->id]) === false) {
                    throw new RuntimeException('Die Mietbeträge konnten nicht gespeichert werden.');
                }
                $id = (int) $existing->id;
            } else {
                BPM_Portal::insert('rent_terms', $data + ['created_at' => BPM_Portal::now()]);
                $id = (int) $wpdb->insert_id;
            }
            BPM_Portal::event('rent_terms_saved', (int) $household->id, $id);
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('Die Mietbeträge konnten nicht gespeichert werden.');
            }
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

}
