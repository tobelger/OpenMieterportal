<?php
defined('ABSPATH') || exit;

/** Only portal-owned fields are sealed; IDs, indexes and workflow dates stay queryable. */
final class BPM_Data {
    public const FIELDS=[
        'buildings'=>['label','address'], 'units'=>['label'],
        'households'=>['label','unit_label','expected_email'],
        'rent_terms'=>['rent_cents','advance_cents','operating_cents','heating_cents','garage_cents'],
        'documents'=>['title','filename'], 'readings'=>['value'],
        'threads'=>['subject'], 'messages'=>['body'],
    ];
    private static function name(string $table): string { global $wpdb;return str_starts_with($table,$wpdb->prefix.'bpm_')?substr($table,strlen($wpdb->prefix.'bpm_')):''; }
    private static function primary(string $sql): string { global $wpdb;preg_match('/\bFROM\s+`?('.preg_quote($wpdb->prefix.'bpm_','/').'[a-z_]+)`?/i',$sql,$m);return isset($m[1])?$m[1]:''; }
    public static function row($sql,$output=OBJECT,$y=0) {global $wpdb;$row=$wpdb->get_row($sql,$output,$y);if(is_object($row))return self::hydrate(self::primary($sql),$row);if(is_array($row)&&$output===ARRAY_A)return (array)self::hydrate(self::primary($sql),(object)$row);return $row;}
    public static function rows($sql,$output=OBJECT,?string $table=null): array {global $wpdb;$rows=$wpdb->get_results($sql,$output);if(!is_array($rows))return [];foreach($rows as &$row){if(is_object($row))$row=self::hydrate($table??self::primary($sql),$row);elseif(is_array($row)&&$output===ARRAY_A)$row=(array)self::hydrate($table??self::primary($sql),(object)$row);}return $rows;}
    public static function hydrate(string $table,object $row): object {
        global $wpdb;$name=self::name($table);
        if(!isset(self::FIELDS[$name])||empty($row->id))return $row;
        $box=$row->protected_data??$wpdb->get_var($wpdb->prepare("SELECT protected_data FROM $table WHERE id=%d",$row->id));
        if(!$box && get_option('bpm_data_encryption_required',false))throw new RuntimeException('Ein unverschlüsselter Datensatz wurde blockiert.');
        if($box){$data=json_decode(BPM_Crypto::open($box,'row/'.$name.'/'.$row->id),true,32,JSON_THROW_ON_ERROR);foreach(self::FIELDS[$name] as $field)if(property_exists($row,$field)&&array_key_exists($field,$data))$row->$field=$data[$field];}
        unset($row->protected_data);
        if($name==='units'&&!empty($row->building_id)){
            $b=self::row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('buildings').' WHERE id=%d',$row->building_id));
            if($b){if(property_exists($row,'building_label'))$row->building_label=$b->label;if(property_exists($row,'address'))$row->address=$b->address;}
        }
        if($name==='households'&&!empty($row->unit_id)&&(property_exists($row,'building_label')||property_exists($row,'building_address'))){
            $u=BPM_Portal::unit((int)$row->unit_id);if(property_exists($row,'building_label'))$row->building_label=$u->building_label;if(property_exists($row,'building_address'))$row->building_address=$u->address;
        }
        return $row;
    }
    private static function payload(string $name,array $row,array $changes): array {
        $data=[];if(!empty($row['protected_data']))$data=json_decode(BPM_Crypto::open($row['protected_data'],'row/'.$name.'/'.$row['id']),true,32,JSON_THROW_ON_ERROR);
        foreach(self::FIELDS[$name] as $field){if(array_key_exists($field,$changes))$data[$field]=$changes[$field];elseif(!array_key_exists($field,$data)&&array_key_exists($field,$row))$data[$field]=$row[$field];}
        return $data;
    }
    private static function placeholders(string $name,array $data): array {
        foreach(self::FIELDS[$name] as $field)if(array_key_exists($field,$data))$data[$field]=($data[$field]===null?null:($name==='rent_terms'||($name==='readings'&&$field==='value')?0:''));
        return $data;
    }
    public static function insert(string $table,array $data,$formats=null) {
        global $wpdb;$name=self::name($table);
        if(!isset(self::FIELDS[$name]))return $wpdb->insert($table,$data,$formats);
        if(!BPM_Crypto::configured()&&!get_option('bpm_data_encryption_required',false))return $wpdb->insert($table,$data,$formats);
        BPM_Crypto::ring();$result=$wpdb->insert($table,self::placeholders($name,$data));if($result===false)return false;$id=(int)$wpdb->insert_id;
        try{$payload=self::payload($name,[],$data);$box=BPM_Crypto::seal(json_encode($payload,JSON_THROW_ON_ERROR),'row/'.$name.'/'.$id);if($wpdb->update($table,['protected_data'=>$box],['id'=>$id])===false)throw new RuntimeException('Die geschützten Daten konnten nicht gespeichert werden.');}
        catch(Throwable $e){$wpdb->delete($table,['id'=>$id]);throw $e;}
        $wpdb->insert_id=$id;return $result;
    }
    public static function update(string $table,array $data,array $where,$formats=null,$where_formats=null) {
        global $wpdb;$name=self::name($table);
        if(!isset(self::FIELDS[$name])||!array_intersect(array_keys($data),self::FIELDS[$name]))return $wpdb->update($table,$data,$where,$formats,$where_formats);
        $clauses=[];$args=[];
        foreach($where as $field=>$value){if(!preg_match('/^[a-z_]+$/D',$field))throw new RuntimeException('Ungültiges Datenfeld.');if($value===null)$clauses[]="`$field` IS NULL";else{$clauses[]="`$field`=%s";$args[]=$value;}}
        if(!$clauses)throw new RuntimeException('Eine gezielte Zuordnung ist erforderlich.');
        $sql="SELECT * FROM $table WHERE ".implode(' AND ',$clauses);$rows=$wpdb->get_results($args?$wpdb->prepare($sql,...$args):$sql);$changed=0;
        foreach($rows as $row){
            if(BPM_Crypto::configured()||!empty($row->protected_data)||get_option('bpm_data_encryption_required',false)){
                $payload=self::payload($name,(array)$row,$data);$values=self::placeholders($name,$data);$values['protected_data']=BPM_Crypto::seal(json_encode($payload,JSON_THROW_ON_ERROR),'row/'.$name.'/'.$row->id);
            }else{$values=$data;}
            $result=$wpdb->update($table,$values,['id'=>$row->id]);if($result===false)return false;$changed+=$result;
        }
        return $changed;
    }
    public static function install(): void {global $wpdb;foreach(self::FIELDS as $name=>$fields){$table=BPM_Portal::table($name);if(!$wpdb->get_var("SHOW COLUMNS FROM $table LIKE 'protected_data'")){$ok=$wpdb->query("ALTER TABLE $table ADD protected_data longtext NULL");if($ok===false)throw new RuntimeException('Die Sicherheitsmigration konnte nicht vorbereitet werden.');}}}
    public static function migrate(): array {
        global $wpdb;BPM_Crypto::ring();$counts=[];
        foreach(self::FIELDS as $name=>$fields){$table=BPM_Portal::table($name);$count=0;foreach($wpdb->get_results("SELECT * FROM $table") as $row){$clear=self::hydrate($table,clone $row);$changes=[];foreach($fields as $field)$changes[$field]=$clear->$field; if(self::update($table,$changes,['id'=>$row->id])===false)throw new RuntimeException('Die Sicherheitsmigration wurde angehalten.');$check=self::row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$row->id));foreach($fields as $field)if((string)$check->$field!==(string)$clear->$field)throw new RuntimeException('Die Sicherheitsprüfung der Migration ist fehlgeschlagen.');$count++;}$counts[$name]=$count;}
        update_option('bpm_data_encryption_required',true,false);return $counts;
    }
    public static function search_households(string $search): array {
        global $wpdb;$rows=self::rows('SELECT h.*,b.label AS building_label,b.address AS building_address FROM '.BPM_Portal::table('households').' h LEFT JOIN '.BPM_Portal::table('units').' u ON u.id=h.unit_id LEFT JOIN '.BPM_Portal::table('buildings').' b ON b.id=u.building_id');$ids=[];
        foreach($rows as $h){$h->unit_reference=!empty($h->unit_id)?(BPM_Portal::unit((int)$h->unit_id)->reference??''):'';foreach(['unit_reference','label','reference','unit_label','building_label','building_address'] as $field)if(mb_stripos((string)($h->$field??''),$search)!==false){$ids[]=(int)$h->id;break;}}
        return $ids;
    }
}
