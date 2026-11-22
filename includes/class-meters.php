<?php
defined('ABSPATH') || exit;

/** Stable apartment measuring points and replaceable physical devices. */
final class BPM_Meters {
    private static function manager(): void { if (!BPM_Portal::manager()) { throw new RuntimeException('Keine Berechtigung.'); } }
    public static function point(int $id, int $unit_id): object {
        global $wpdb;
        $p=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('meter_points').' WHERE id=%d AND unit_id=%d',$id,$unit_id));
        if (!$p) { throw new InvalidArgumentException('Diese Messstelle gehört nicht zur Wohnung.'); } return $p;
    }
    /** Idempotent additive conversion: one point per existing device, without guessing room/installation dates. */
    public static function migrate(): bool {
        global $wpdb;
        $lock='bpm_meter_points_migration_lock';
        if (!add_option($lock,time(),'','no')) {
            if ((int)get_option($lock)>time()-120) { return false; }
            delete_option($lock); if (!add_option($lock,time(),'','no')) { return false; }
        }
        $wpdb->query('START TRANSACTION');
        try {
            $snapshot = static function()use($wpdb){return [
                'devices'=>$wpdb->get_results('SELECT id,household_id,unit_id,number,label,unit,active,installed_on,removed_on FROM '.BPM_Portal::table('meters').' ORDER BY id',ARRAY_A),
                'readings'=>$wpdb->get_results('SELECT * FROM '.BPM_Portal::table('readings').' ORDER BY id',ARRAY_A)
            ];};
            $before=$snapshot();
            $meters=$wpdb->get_results('SELECT * FROM '.BPM_Portal::table('meters').' WHERE point_id IS NULL AND unit_id IS NOT NULL AND unit_id>0');
            foreach ($meters as $m) {
                $pid=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.BPM_Portal::table('meter_points').' WHERE legacy_meter_id=%d',$m->id));
                if (!$pid) { BPM_Portal::insert('meter_points',['unit_id'=>$m->unit_id,'label'=>$m->label,'unit'=>$m->unit,'legacy_meter_id'=>$m->id]);$pid=(int)$wpdb->insert_id; }
                if ($wpdb->update(BPM_Portal::table('meters'),['point_id'=>$pid],['id'=>$m->id])===false) { throw new RuntimeException('Die Messstellen konnten nicht übernommen werden.'); }
            }
            if ($wpdb->last_error) { throw new RuntimeException('Die Messstellen konnten nicht übernommen werden.'); }
            $after=$snapshot();
            if ($before!==$after) { throw new RuntimeException('Die Datenprüfung der Messstellen-Umstellung ist fehlgeschlagen.'); }
            $wpdb->query('COMMIT');
            if (!get_option('bpm_meter_points_migration_report')) { update_option('bpm_meter_points_migration_report',['devices'=>count($before['devices']),'readings'=>count($before['readings']),'unchanged'=>true,'at'=>BPM_Portal::now(),'sha256'=>hash('sha256',wp_json_encode($before))],false); }
            return true;
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
        finally { delete_option($lock); }
    }
    private static function text(array $d,string $key,int $max): string {
        $v=sanitize_text_field((string)($d[$key]??''));
        if ($v===''||mb_strlen($v)>$max) { throw new InvalidArgumentException('Bitte alle Pflichtfelder in der erlaubten Länge ausfüllen.'); } return $v;
    }
    private static function transaction(int $point_id,callable $work) {
        global $wpdb; $wpdb->query('START TRANSACTION');
        try {
            // Portable write lock: serialize changes to a single measuring point.
            if ($point_id && $wpdb->query($wpdb->prepare('UPDATE '.BPM_Portal::table('meter_points').' SET id=id WHERE id=%d',$point_id))===false) { throw new RuntimeException('Die Messstelle konnte nicht gesperrt werden.'); }
            $out=$work(); if($wpdb->last_error) { throw new RuntimeException('Die Angaben konnten nicht gespeichert werden.'); }
            $wpdb->query('COMMIT');return $out;
        } catch(Throwable $e) { $wpdb->query('ROLLBACK');throw $e; }
    }
    private static function point_values(array $data): array {
        $label=self::text($data,'point_label',160);$unit=(string)($data['unit']??'');
        if(!in_array($unit,['m³','kWh','Einheiten'],true)) { throw new InvalidArgumentException('Ungültige Einheit.'); }
        return ['label'=>$label,'unit'=>$unit];
    }
    public static function save_point(int $unit_id,int $id,array $data): int {
        self::manager();BPM_Portal::unit($unit_id);$values=self::point_values($data);
        if($id)self::point($id,$unit_id);
        return self::transaction($id,static function()use($unit_id,$id,$values){
            global $wpdb;
            if($id){
                $p=self::point($id,$unit_id);
                if($p->unit!==$values['unit']&&$wpdb->get_var($wpdb->prepare('SELECT r.id FROM '.BPM_Portal::table('readings').' r JOIN '.BPM_Portal::table('meters').' m ON m.id=r.meter_id WHERE m.point_id=%d LIMIT 1',$id))) { throw new InvalidArgumentException('Die Einheit einer Messstelle mit Ablesungen darf nicht geändert werden.'); }
                if($wpdb->update(BPM_Portal::table('meter_points'),$values,['id'=>$id])===false||$wpdb->update(BPM_Portal::table('meters'),$values,['point_id'=>$id])===false)throw new RuntimeException('Messstelle konnte nicht gespeichert werden.');
                $pid=$id;
            }else{BPM_Portal::insert('meter_points',array_merge($values,['unit_id'=>$unit_id]));$pid=(int)$wpdb->insert_id;}
            BPM_Portal::event('meter_point_saved',0,$pid);return $pid;
        });
    }
    private static function device_locked(object $p,array $data): int {
        global $wpdb;$id=absint($data['meter_id']??0);$old=null;
        if($id){$old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('meters').' WHERE id=%d AND point_id=%d AND unit_id=%d',$id,$p->id,$p->unit_id));if(!$old)throw new InvalidArgumentException('Dieses Gerät gehört nicht zu dieser Messstelle.');}
        $number=self::text($data,'number',100);
        $from=BPM_Domain::tenancy_date(trim((string)($data['installed_on']??'')));$until=BPM_Domain::tenancy_date(trim((string)($data['removed_on']??'')));
        if(!$id&&!$from)throw new InvalidArgumentException('Für ein neues Gerät bitte „Gültig ab“ angeben.');
        if($from&&$until&&$until<$from)throw new InvalidArgumentException('Das Gültigkeitsende liegt vor dem Beginn.');
        if($wpdb->get_var($wpdb->prepare('SELECT id FROM '.BPM_Portal::table('meters').' WHERE number=%s AND id<>%d',$number,$id)))throw new InvalidArgumentException('Diese Gerätenummer ist bereits vergeben.');
        if($id){$dates=$wpdb->get_row($wpdb->prepare('SELECT MIN(read_at) AS first_date,MAX(read_at) AS last_date FROM '.BPM_Portal::table('readings').' WHERE meter_id=%d',$id));if($dates->first_date&&(($from&&$from>$dates->first_date)||($until&&$until<$dates->last_date)))throw new InvalidArgumentException('Der Gültigkeitszeitraum muss die vorhandenen Ablesungen einschließen.');}
        // Date-only boundaries may touch on the actual replacement day; interiors must not overlap.
        foreach($wpdb->get_results($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('meters').' WHERE point_id=%d AND id<>%d',$p->id,$id))as $other){
            if(($from?:'0000-01-01')<($other->removed_on?:'9999-12-31')&&($other->installed_on?:'0000-01-01')<($until?:'9999-12-31'))throw new InvalidArgumentException('Die Gültigkeit überschneidet sich mit einem anderen Gerät dieser Messstelle. Bitte „Zählerwechsel erfassen“ verwenden.');
        }
        $v=['number'=>$number,'label'=>$p->label,'unit'=>$p->unit,'installed_on'=>$from,'removed_on'=>$until,'active'=>empty($data['active'])?0:1];
        if($id){if($wpdb->update(BPM_Portal::table('meters'),$v,['id'=>$id,'point_id'=>$p->id])===false)throw new RuntimeException('Gerät konnte nicht gespeichert werden.');}
        else{BPM_Portal::insert('meters',array_merge($v,['unit_id'=>$p->unit_id,'point_id'=>$p->id,'household_id'=>0]));$id=(int)$wpdb->insert_id;}
        BPM_Portal::event('meter_device_saved',0,$id);return $id;
    }
    public static function save_device(int $unit_id,int $point_id,array $data): int {
        self::manager();self::point($point_id,$unit_id);
        return self::transaction($point_id,static fn()=>self::device_locked(self::point($point_id,$unit_id),$data));
    }
    private static function exchange_reading(int $meter_id,int $unit_id,string $date,string $raw): void {
        global $wpdb;if(trim($raw)==='')return;$value=BPM_Domain::reading($raw);
        if($date>BPM_Portal::today())throw new InvalidArgumentException('Für einen künftigen Wechsel können noch keine tatsächlichen Wechselstände gespeichert werden.');
        $existing=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('readings').' WHERE meter_id=%d AND read_at=%s',$meter_id,$date));
        if($existing){if(count($existing)!==1||BPM_Domain::reading((string)$existing[0]->value)!==$value)throw new InvalidArgumentException('Für den Wechseltag ist bereits ein abweichender Stand gespeichert.');return;}
        $households=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.BPM_Portal::table('households').' WHERE unit_id=%d AND tenancy_start<=%s AND (tenancy_end IS NULL OR tenancy_end>=%s)',$unit_id,$date,$date));
        $hid=count($households)===1?(int)$households[0]:0;
        BPM_Portal::insert('readings',['household_id'=>$hid,'meter_id'=>$meter_id,'value'=>$value,'read_at'=>$date,'source'=>'verwaltung','status'=>'bestaetigt','photo_key'=>'','created_at'=>BPM_Portal::now()]);BPM_Portal::event('exchange_reading_created',$hid,(int)$wpdb->insert_id);
    }
    public static function exchange(int $unit_id,int $point_id,array $data): int {
        self::manager();self::point($point_id,$unit_id);
        return self::transaction($point_id,static function()use($unit_id,$point_id,$data){
            global $wpdb;$p=self::point($point_id,$unit_id);$old_id=absint($data['old_meter_id']??0);$old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('meters').' WHERE id=%d AND point_id=%d AND unit_id=%d',$old_id,$point_id,$unit_id));
            if(!$old||$old->removed_on)throw new InvalidArgumentException('Bitte ein Gerät ohne bereits erfasstes Gültigkeitsende auswählen.');
            $date=BPM_Domain::tenancy_date(trim((string)($data['change_date']??'')));if(!$date||($old->installed_on&&$date<$old->installed_on))throw new InvalidArgumentException('Bitte ein gültiges Wechseldatum angeben.');
            $last=$wpdb->get_var($wpdb->prepare('SELECT MAX(read_at) FROM '.BPM_Portal::table('readings').' WHERE meter_id=%d',$old_id));if($last&&$date<$last)throw new InvalidArgumentException('Nach dem gewählten Wechseldatum bestehen bereits Ablesungen des bisherigen Geräts.');
            if($wpdb->update(BPM_Portal::table('meters'),['removed_on'=>$date],['id'=>$old_id,'point_id'=>$point_id])===false)throw new RuntimeException('Der Wechsel konnte nicht gespeichert werden.');
            $new_id=self::device_locked($p,['number'=>$data['new_number']??'','installed_on'=>$date,'removed_on'=>'','active'=>1]);
            self::exchange_reading($old_id,$unit_id,$date,(string)($data['end_value']??''));self::exchange_reading($new_id,$unit_id,$date,(string)($data['start_value']??''));
            BPM_Portal::event('meter_device_replaced',0,$new_id);return $new_id;
        });
    }
    /** Compatibility with the previous apartment form and existing admin entry points. */
    public static function legacy_save(int $unit_id,array $data): int {
        self::manager();global $wpdb;BPM_Portal::unit($unit_id);$id=absint($data['meter_id']??0);
        if($id){$m=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('meters').' WHERE id=%d AND unit_id=%d',$id,$unit_id));if(!$m)throw new InvalidArgumentException('Dieser Zähler gehört nicht zur Wohnung.');if(!$m->point_id)self::migrate();$pid=(int)$wpdb->get_var($wpdb->prepare('SELECT point_id FROM '.BPM_Portal::table('meters').' WHERE id=%d',$id));
            // Legacy form edits are still subject to the same point and device guards.
            $p=self::point($pid,$unit_id);if(($data['unit']??$p->unit)!==$p->unit)throw new InvalidArgumentException('Bitte die Einheit an der Messstelle prüfen.');
            $label=self::text($data,'meter_label',160);
            return self::transaction($pid,static function()use($unit_id,$pid,$data,$label){global $wpdb;$p=self::point($pid,$unit_id);if($wpdb->update(BPM_Portal::table('meter_points'),['label'=>$label],['id'=>$pid])===false||$wpdb->update(BPM_Portal::table('meters'),['label'=>$label],['point_id'=>$pid])===false)throw new RuntimeException('Messstelle konnte nicht gespeichert werden.');return self::device_locked(self::point($pid,$unit_id),$data);});
        }
        $v=self::point_values(['point_label'=>$data['meter_label']??'','unit'=>$data['unit']??'']);
        return self::transaction(0,static function()use($unit_id,$v,$data){global $wpdb;BPM_Portal::insert('meter_points',array_merge($v,['unit_id'=>$unit_id]));$pid=(int)$wpdb->insert_id;return self::device_locked(self::point($pid,$unit_id),$data);});
    }
    public static function legacy_assign(int $unit_id,int $meter_id): void {
        self::manager();global $wpdb;BPM_Portal::unit($unit_id);
        self::transaction(0,static function()use($unit_id,$meter_id){global $wpdb;$changed=$wpdb->query($wpdb->prepare('UPDATE '.BPM_Portal::table('meters').' m SET unit_id=%d WHERE m.id=%d AND (m.unit_id IS NULL OR m.unit_id=0) AND m.household_id=0 AND NOT EXISTS (SELECT 1 FROM '.BPM_Portal::table('readings').' r WHERE r.meter_id=m.id)',$unit_id,$meter_id));if($changed!==1)throw new InvalidArgumentException('Dieses Gerät ist bereits zugeordnet oder hat Ablesungen.');$m=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('meters').' WHERE id=%d',$meter_id));BPM_Portal::insert('meter_points',['unit_id'=>$unit_id,'label'=>$m->label,'unit'=>$m->unit]);$pid=(int)$wpdb->insert_id;if($wpdb->update(BPM_Portal::table('meters'),['point_id'=>$pid],['id'=>$meter_id])===false)throw new RuntimeException('Zuordnung fehlgeschlagen.');BPM_Portal::event('unit_meter_assigned',0,$meter_id);});
    }
}
