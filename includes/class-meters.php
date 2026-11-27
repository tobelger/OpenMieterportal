<?php

defined('ABSPATH') || exit;

/** Stable apartment measuring points and replaceable physical devices. */
final class BPM_Meters
{
    private static function manager(): void
    {
        if (!BPM_Portal::manager()) {
            throw new RuntimeException(esc_html__('Keine Berechtigung.', 'openmieterportal'));
        }
    }
    public static function point(int $id, int $unit_id): object
    {
        global $wpdb;
        $p = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE id=%d AND unit_id=%d', BPM_Portal::table('meter_points'), $id, $unit_id));
        if (!$p) {
            throw new InvalidArgumentException(esc_html__('Diese Messstelle gehört nicht zur Wohnung.', 'openmieterportal'));
        } return $p;
    }
    /** Idempotent additive conversion: one point per existing device, without guessing room/installation dates. */
    public static function migrate(): bool
    {
        global $wpdb;
        $lock = 'bpm_meter_points_migration_lock';
        if (!add_option($lock, time(), '', 'no')) {
            if ((int)get_option($lock) > time() - 120) {
                return false;
            }
            delete_option($lock);
            if (!add_option($lock, time(), '', 'no')) {
                return false;
            }
        }
        $wpdb->query('START TRANSACTION');
        try {
            $snapshot = static function () use ($wpdb) {
                return [
                'devices' => BPM_Data::rows($wpdb->prepare('SELECT id,household_id,unit_id,number,label,unit,active,installed_on,removed_on FROM %i ORDER BY id', BPM_Portal::table('meters')), ARRAY_A),
                'readings' => BPM_Data::rows($wpdb->prepare('SELECT * FROM %i ORDER BY id', BPM_Portal::table('readings')), ARRAY_A)
                ];
            };
            $before = $snapshot();
            $meters = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE point_id IS NULL AND unit_id IS NOT NULL AND unit_id>0', BPM_Portal::table('meters')));
            foreach ($meters as $m) {
                $pid = (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE legacy_meter_id=%d', BPM_Portal::table('meter_points'), $m->id));
                if (!$pid) {
                    BPM_Portal::insert('meter_points', ['unit_id' => $m->unit_id,'label' => $m->label,'unit' => $m->unit,'legacy_meter_id' => $m->id]);
                    $pid = (int)$wpdb->insert_id;
                }
                if (BPM_Data::update(BPM_Portal::table('meters'), ['point_id' => $pid], ['id' => $m->id]) === false) {
                    throw new RuntimeException(esc_html__('Die Messstellen konnten nicht übernommen werden.', 'openmieterportal'));
                }
            }
            if ($wpdb->last_error) {
                throw new RuntimeException(esc_html__('Die Messstellen konnten nicht übernommen werden.', 'openmieterportal'));
            }
            $after = $snapshot();
            if ($before !== $after) {
                throw new RuntimeException(esc_html__('Die Datenprüfung der Messstellen-Umstellung ist fehlgeschlagen.', 'openmieterportal'));
            }
            $wpdb->query('COMMIT');
            if (!get_option('bpm_meter_points_migration_report')) {
                update_option('bpm_meter_points_migration_report', ['devices' => count($before['devices']),'readings' => count($before['readings']),'unchanged' => true,'at' => BPM_Portal::now(),'sha256' => hash('sha256', wp_json_encode($before))], false);
            }
            return true;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        } finally {
            delete_option($lock);
        }
    }
    private static function text(array $d, string $key, int $max): string
    {
        $v = sanitize_text_field((string)($d[$key] ?? ''));
        if ($v === '' || mb_strlen($v) > $max) {
            throw new InvalidArgumentException(esc_html__('Bitte alle Pflichtfelder in der erlaubten Länge ausfüllen.', 'openmieterportal'));
        } return $v;
    }
    private static function transaction(int $point_id, callable $work)
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            // Portable write lock: serialize changes to a single measuring point.
            if ($point_id && $wpdb->query($wpdb->prepare('UPDATE %i SET id=id WHERE id=%d', BPM_Portal::table('meter_points'), $point_id)) === false) {
                throw new RuntimeException(esc_html__('Die Messstelle konnte nicht gesperrt werden.', 'openmieterportal'));
            }
            $out = $work();
            if ($wpdb->last_error) {
                throw new RuntimeException(esc_html__('Die Angaben konnten nicht gespeichert werden.', 'openmieterportal'));
            }
            $wpdb->query('COMMIT');
            return $out;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
    private static function point_values(array $data): array
    {
        $label = self::text($data, 'point_label', 160);
        $unit = (string)($data['unit'] ?? '');
        if (!in_array($unit, ['m³','kWh',__('Einheiten', 'openmieterportal')], true)) {
            throw new InvalidArgumentException(esc_html__('Ungültige Einheit.', 'openmieterportal'));
        }
        return ['label' => $label,'unit' => $unit];
    }
    public static function save_point(int $unit_id, int $id, array $data): int
    {
        self::manager();
        BPM_Portal::unit($unit_id);
        $values = self::point_values($data);
        if ($id) {
            self::point($id, $unit_id);
        }
        return self::transaction($id, static function () use ($unit_id, $id, $values) {
            global $wpdb;
            if ($id) {
                $p = self::point($id, $unit_id);
                if ($p->unit !== $values['unit'] && $wpdb->get_var($wpdb->prepare('SELECT r.id FROM %i r JOIN %i m ON m.id=r.meter_id WHERE m.point_id=%d LIMIT 1', BPM_Portal::table('readings'), BPM_Portal::table('meters'), $id))) {
                    throw new InvalidArgumentException(esc_html__('Die Einheit einer Messstelle mit Ablesungen darf nicht geändert werden.', 'openmieterportal'));
                }
                if (BPM_Data::update(BPM_Portal::table('meter_points'), $values, ['id' => $id]) === false || BPM_Data::update(BPM_Portal::table('meters'), $values, ['point_id' => $id]) === false) {
                    throw new RuntimeException(esc_html__('Messstelle konnte nicht gespeichert werden.', 'openmieterportal'));
                }
                $pid = $id;
            } else {
                BPM_Portal::insert('meter_points', array_merge($values, ['unit_id' => $unit_id]));
                $pid = (int)$wpdb->insert_id;
            }
            BPM_Portal::event('meter_point_saved', 0, $pid);
            return $pid;
        });
    }
    private static function device_locked(object $p, array $data): int
    {
        global $wpdb;
        $id = absint($data['meter_id'] ?? 0);
        $old = null;
        if ($id) {
            $old = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE id=%d AND point_id=%d AND unit_id=%d', BPM_Portal::table('meters'), $id, $p->id, $p->unit_id));
            if (!$old) {
                throw new InvalidArgumentException(esc_html__('Dieses Gerät gehört nicht zu dieser Messstelle.', 'openmieterportal'));
            }
        }
        $number = self::text($data, 'number', 100);
        $from = BPM_Domain::tenancy_date(trim((string)($data['installed_on'] ?? '')));
        $until = BPM_Domain::tenancy_date(trim((string)($data['removed_on'] ?? '')));
        if (!$id && !$from) {
            throw new InvalidArgumentException(esc_html__('Für ein neues Gerät bitte „Gültig ab“ angeben.', 'openmieterportal'));
        }
        if ($from && $until && $until < $from) {
            throw new InvalidArgumentException(esc_html__('Das Gültigkeitsende liegt vor dem Beginn.', 'openmieterportal'));
        }
        if ($wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE number=%s AND id<>%d', BPM_Portal::table('meters'), $number, $id))) {
            throw new InvalidArgumentException(esc_html__('Diese Gerätenummer ist bereits vergeben.', 'openmieterportal'));
        }
        if ($id) {
            $dates = BPM_Data::row($wpdb->prepare('SELECT MIN(read_at) AS first_date,MAX(read_at) AS last_date FROM %i WHERE meter_id=%d', BPM_Portal::table('readings'), $id));
            if ($dates->first_date && (($from && $from > $dates->first_date) || ($until && $until < $dates->last_date))) {
                throw new InvalidArgumentException(esc_html__('Der Gültigkeitszeitraum muss die vorhandenen Ablesungen einschließen.', 'openmieterportal'));
            }
        }
        // Date-only boundaries may touch on the actual replacement day; interiors must not overlap.
        foreach (BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE point_id=%d AND id<>%d', BPM_Portal::table('meters'), $p->id, $id)) as $other) {
            if (($from ?: '0000-01-01') < ($other->removed_on ?: '9999-12-31') && ($other->installed_on ?: '0000-01-01') < ($until ?: '9999-12-31')) {
                throw new InvalidArgumentException(esc_html__('Die Gültigkeit überschneidet sich mit einem anderen Gerät dieser Messstelle. Bitte „Zählerwechsel erfassen“ verwenden.', 'openmieterportal'));
            }
        }
        $v = ['number' => $number,'label' => $p->label,'unit' => $p->unit,'installed_on' => $from,'removed_on' => $until,'active' => empty($data['active']) ? 0 : 1];
        if ($id) {
            if (BPM_Data::update(BPM_Portal::table('meters'), $v, ['id' => $id,'point_id' => $p->id]) === false) {
                throw new RuntimeException(esc_html__('Gerät konnte nicht gespeichert werden.', 'openmieterportal'));
            }
        } else {
            BPM_Portal::insert('meters', array_merge($v, ['unit_id' => $p->unit_id,'point_id' => $p->id,'household_id' => 0]));
            $id = (int)$wpdb->insert_id;
        }
        BPM_Portal::event('meter_device_saved', 0, $id);
        return $id;
    }
    public static function save_device(int $unit_id, int $point_id, array $data): int
    {
        self::manager();
        self::point($point_id, $unit_id);
        return self::transaction($point_id, static fn()=>self::device_locked(self::point($point_id, $unit_id), $data));
    }
    private static function exchange_reading(int $meter_id, int $unit_id, string $date, string $raw): void
    {
        global $wpdb;
        if (trim($raw) === '') {
            return;
        }$value = BPM_Domain::reading($raw);
        if ($date > BPM_Portal::today()) {
            throw new InvalidArgumentException(esc_html__('Für einen künftigen Wechsel können noch keine tatsächlichen Wechselstände gespeichert werden.', 'openmieterportal'));
        }
        $existing = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE meter_id=%d AND read_at=%s', BPM_Portal::table('readings'), $meter_id, $date));
        if ($existing) {
            if (count($existing) !== 1 || BPM_Domain::reading((string)$existing[0]->value) !== $value) {
                throw new InvalidArgumentException(esc_html__('Für den Wechseltag ist bereits ein abweichender Stand gespeichert.', 'openmieterportal'));
            }return;
        }
        $households = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE unit_id=%d AND tenancy_start<=%s AND (tenancy_end IS NULL OR tenancy_end>=%s)', BPM_Portal::table('households'), $unit_id, $date, $date));
        $hid = count($households) === 1 ? (int)$households[0] : 0;
        BPM_Portal::insert('readings', ['household_id' => $hid,'meter_id' => $meter_id,'value' => $value,'read_at' => $date,'source' => 'verwaltung','status' => 'bestaetigt','photo_key' => '','created_at' => BPM_Portal::now()]);
        BPM_Portal::event('exchange_reading_created', $hid, (int)$wpdb->insert_id);
    }
    public static function exchange(int $unit_id, int $point_id, array $data): int
    {
        self::manager();
        self::point($point_id, $unit_id);
        return self::transaction($point_id, static function () use ($unit_id, $point_id, $data) {
            global $wpdb;
            $p = self::point($point_id, $unit_id);
            $old_id = absint($data['old_meter_id'] ?? 0);
            $old = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE id=%d AND point_id=%d AND unit_id=%d', BPM_Portal::table('meters'), $old_id, $point_id, $unit_id));
            if (!$old || $old->removed_on) {
                throw new InvalidArgumentException(esc_html__('Bitte ein Gerät ohne bereits erfasstes Gültigkeitsende auswählen.', 'openmieterportal'));
            }
            $date = BPM_Domain::tenancy_date(trim((string)($data['change_date'] ?? '')));
            if (!$date || ($old->installed_on && $date < $old->installed_on)) {
                throw new InvalidArgumentException(esc_html__('Bitte ein gültiges Wechseldatum angeben.', 'openmieterportal'));
            }
            $last = $wpdb->get_var($wpdb->prepare('SELECT MAX(read_at) FROM %i WHERE meter_id=%d', BPM_Portal::table('readings'), $old_id));
            if ($last && $date < $last) {
                throw new InvalidArgumentException(esc_html__('Nach dem gewählten Wechseldatum bestehen bereits Ablesungen des bisherigen Geräts.', 'openmieterportal'));
            }
            if (BPM_Data::update(BPM_Portal::table('meters'), ['removed_on' => $date], ['id' => $old_id,'point_id' => $point_id]) === false) {
                throw new RuntimeException(esc_html__('Der Wechsel konnte nicht gespeichert werden.', 'openmieterportal'));
            }
            $new_id = self::device_locked($p, ['number' => $data['new_number'] ?? '','installed_on' => $date,'removed_on' => '','active' => 1]);
            self::exchange_reading($old_id, $unit_id, $date, (string)($data['end_value'] ?? ''));
            self::exchange_reading($new_id, $unit_id, $date, (string)($data['start_value'] ?? ''));
            BPM_Portal::event('meter_device_replaced', 0, $new_id);
            return $new_id;
        });
    }
    /** Compatibility with the previous apartment form and existing admin entry points. */
    public static function legacy_save(int $unit_id, array $data): int
    {
        self::manager();
        global $wpdb;
        BPM_Portal::unit($unit_id);
        $id = absint($data['meter_id'] ?? 0);
        if ($id) {
            $m = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE id=%d AND unit_id=%d', BPM_Portal::table('meters'), $id, $unit_id));
            if (!$m) {
                throw new InvalidArgumentException(esc_html__('Dieser Zähler gehört nicht zur Wohnung.', 'openmieterportal'));
            }if (!$m->point_id) {
                self::migrate();
            }$pid = (int)$wpdb->get_var($wpdb->prepare('SELECT point_id FROM %i WHERE id=%d', BPM_Portal::table('meters'), $id));
            // Legacy form edits are still subject to the same point and device guards.
            $p = self::point($pid, $unit_id);
            if (($data['unit'] ?? $p->unit) !== $p->unit) {
                throw new InvalidArgumentException(esc_html__('Bitte die Einheit an der Messstelle prüfen.', 'openmieterportal'));
            }
            $label = self::text($data, 'meter_label', 160);
            return self::transaction($pid, static function () use ($unit_id, $pid, $data, $label) {
                global $wpdb;
                $p = self::point($pid, $unit_id);
                if (BPM_Data::update(BPM_Portal::table('meter_points'), ['label' => $label], ['id' => $pid]) === false || BPM_Data::update(BPM_Portal::table('meters'), ['label' => $label], ['point_id' => $pid]) === false) {
                    throw new RuntimeException(esc_html__('Messstelle konnte nicht gespeichert werden.', 'openmieterportal'));
                }return self::device_locked(self::point($pid, $unit_id), $data);
            });
        }
        $v = self::point_values(['point_label' => $data['meter_label'] ?? '','unit' => $data['unit'] ?? '']);
        return self::transaction(0, static function () use ($unit_id, $v, $data) {
            global $wpdb;
            BPM_Portal::insert('meter_points', array_merge($v, ['unit_id' => $unit_id]));
            $pid = (int)$wpdb->insert_id;
            return self::device_locked(self::point($pid, $unit_id), $data);
        });
    }
    public static function legacy_assign(int $unit_id, int $meter_id): void
    {
        self::manager();
        global $wpdb;
        BPM_Portal::unit($unit_id);
        self::transaction(0, static function () use ($unit_id, $meter_id) {
            global $wpdb;
            $changed = $wpdb->query($wpdb->prepare('UPDATE %i m SET unit_id=%d WHERE m.id=%d AND (m.unit_id IS NULL OR m.unit_id=0) AND m.household_id=0 AND NOT EXISTS (SELECT 1 FROM %i r WHERE r.meter_id=m.id)', BPM_Portal::table('meters'), $unit_id, $meter_id, BPM_Portal::table('readings')));
            if ($changed !== 1) {
                throw new InvalidArgumentException(esc_html__('Dieses Gerät ist bereits zugeordnet oder hat Ablesungen.', 'openmieterportal'));
            }$m = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', BPM_Portal::table('meters'), $meter_id));
            BPM_Portal::insert('meter_points', ['unit_id' => $unit_id,'label' => $m->label,'unit' => $m->unit]);
            $pid = (int)$wpdb->insert_id;
            if (BPM_Data::update(BPM_Portal::table('meters'), ['point_id' => $pid], ['id' => $meter_id]) === false) {
                throw new RuntimeException(esc_html__('Zuordnung fehlgeschlagen.', 'openmieterportal'));
            }BPM_Portal::event('unit_meter_assigned', 0, $meter_id);
        });
    }
}
