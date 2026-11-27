<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require getenv('BPM_TEST_WP_LOAD') ?: '/tmp/belger-portal-runtime/wordpress/wp-load.php';
if (!class_exists(BPM_Admin::class)) { require_once __DIR__ . '/../includes/class-admin.php'; }
if (!class_exists(BPM_Units_Admin::class)) { require_once __DIR__ . '/../includes/class-units-admin.php'; }
if(!str_starts_with(home_url(),'http://127.0.0.1:'))throw new RuntimeException('Local fixture only');
$tag='POINTTEST-'.bin2hex(random_bytes(5));$checks=[];$uid=0;$other=0;$bid=0;$hid=0;$pids=[];
function pcheck($v,$label){global $checks;if(!$v)throw new RuntimeException($label);$checks[]=$label;}
function pdeny($fn,$label){try{$fn();}catch(InvalidArgumentException $e){pcheck(true,$label);return;}throw new RuntimeException($label);}
wp_set_current_user(get_users(['role'=>'administrator','number'=>1])[0]->ID);
try{
 BPM_Portal::insert('buildings',['reference'=>$tag,'label'=>'Point fixture','address'=>'Teststraße 1']);$bid=(int)$wpdb->insert_id;
 foreach(['A','B']as $suffix){BPM_Portal::insert('units',['building_id'=>$bid,'reference'=>$tag.$suffix,'label'=>$suffix]);if(!$uid)$uid=(int)$wpdb->insert_id;else$other=(int)$wpdb->insert_id;}
 BPM_Portal::insert('households',['unit_id'=>$uid,'reference'=>$tag.'H','label'=>'Test contract','unit_label'=>'Teststraße 1 · A','status'=>'invited','tenancy_start'=>'2020-01-01','created_at'=>BPM_Portal::now()]);$hid=(int)$wpdb->insert_id;
 BPM_Portal::insert('meters',['unit_id'=>$uid,'household_id'=>0,'number'=>$tag.'OLD','label'=>'Kaltwasser Küche','unit'=>'m³','active'=>1,'installed_on'=>null,'removed_on'=>null]);$old=(int)$wpdb->insert_id;
 BPM_Portal::insert('readings',['household_id'=>$hid,'meter_id'=>$old,'value'=>'123.456','read_at'=>'2025-12-31','source'=>'wiso','status'=>'bestaetigt','photo_key'=>'','created_at'=>BPM_Portal::now()]);$rid=(int)$wpdb->insert_id;
 $before=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('readings').' WHERE id=%d',$rid));
 pcheck(BPM_Meters::migrate(),'Additive migration succeeds');$pid=(int)$wpdb->get_var($wpdb->prepare('SELECT point_id FROM '.BPM_Portal::table('meters').' WHERE id=%d',$old));$pids[]=$pid;
 pcheck($pid>0&&BPM_Meters::point($pid,$uid)->label==='Kaltwasser Küche','Legacy device becomes stable measuring point');
 pcheck($before==$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('readings').' WHERE id=%d',$rid)),'Migration preserves every reading field and ID');
 pcheck($wpdb->get_var($wpdb->prepare('SELECT installed_on FROM '.BPM_Portal::table('meters').' WHERE id=%d',$old))===null,'Unknown installation date remains unknown');
 BPM_Meters::migrate();pcheck((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.BPM_Portal::table('meter_points').' WHERE legacy_meter_id=%d',$old))===1,'Repeated migration is idempotent');
 pdeny(fn()=>BPM_Meters::point($pid,$other),'Foreign apartment point denied');
 pdeny(fn()=>BPM_Meters::save_device($uid,$pid,['number'=>$tag.'OVERLAP','installed_on'=>'2026-01-01','active'=>1]),'Overlapping replacement denied outside exchange workflow');
 pdeny(fn()=>BPM_Meters::exchange($uid,$pid,['old_meter_id'=>$old,'new_number'=>$tag.'BADDATE','change_date'=>'2025-01-01']),'Change before last existing reading denied');
 pdeny(fn()=>BPM_Meters::exchange($uid,$pid,['old_meter_id'=>$old,'new_number'=>$tag.'BADVALUE','change_date'=>'2026-01-01','end_value'=>'invalid']),'Invalid end reading rolls back entire exchange');
 pcheck($wpdb->get_var($wpdb->prepare('SELECT removed_on FROM '.BPM_Portal::table('meters').' WHERE id=%d',$old))===null&&(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.BPM_Portal::table('meters').' WHERE point_id=%d',$pid))===1,'Rollback retains old period and no partial new device');
 $new=BPM_Meters::exchange($uid,$pid,['old_meter_id'=>$old,'new_number'=>$tag.'NEW','change_date'=>'2026-01-01','end_value'=>'130,500','start_value'=>'0,120']);
 $devices=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('meters').' WHERE point_id=%d ORDER BY id',$pid));
 pcheck(count($devices)===2&&$devices[0]->id==$old&&$devices[1]->id==$new,'Replacement keeps both physical devices');
 pcheck($devices[0]->removed_on==='2026-01-01'&&$devices[1]->installed_on==='2026-01-01','Periods meet on replacement date');
 pcheck($devices[0]->number===$tag.'OLD'&&$devices[1]->number===$tag.'NEW','Serial numbers belong to respective devices');
 pcheck(BPM_Domain::reading((string)$wpdb->get_var($wpdb->prepare('SELECT value FROM '.BPM_Portal::table('readings').' WHERE meter_id=%d AND read_at=%s',$old,'2026-01-01')))==='130.500','Old end reading recorded exactly');
 pcheck(BPM_Domain::reading((string)$wpdb->get_var($wpdb->prepare('SELECT value FROM '.BPM_Portal::table('readings').' WHERE meter_id=%d AND read_at=%s',$new,'2026-01-01')))==='0.120','New start reading recorded exactly');
 pcheck((int)$wpdb->get_var($wpdb->prepare('SELECT household_id FROM '.BPM_Portal::table('readings').' WHERE meter_id=%d',$new))===$hid,'Exchange readings assigned only to contract valid on replacement day');
 $h=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('households').' WHERE id=%d',$hid));$current=BPM_Portal::meters_for($h);pcheck(count($current)===1&&(int)$current[0]->id===$new,'Tenant current selection contains new device only');
 pdeny(fn()=>BPM_Portal::meter_for_reading($old,$h,'2026-01-02'),'Old device rejected after validity end');
 pcheck((int)BPM_Portal::meter_for_reading($old,$h,'2025-12-31')->id===$old,'Historic reading remains attributed to old device');
 pdeny(fn()=>BPM_Portal::meter_for_reading($new,$h,'2025-12-31'),'New device rejected before installation');
 pdeny(fn()=>BPM_Meters::save_point($uid,$pid,['point_label'=>'Küche','unit'=>'kWh']),'Readings protect measurement unit across all devices');
 pdeny(fn()=>BPM_Meters::save_device($other,$pid,['number'=>'NO','installed_on'=>'2026-01-01','active'=>1]),'Foreign apartment cannot change a device');
 BPM_Meters::save_point($uid,$pid,['point_label'=>'Kaltwasser Küche neu','unit'=>'m³']);pcheck((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.BPM_Portal::table('meters').' WHERE point_id=%d AND label=%s',$pid,'Kaltwasser Küche neu'))===2,'Point rename updates display labels without moving readings');
 $vacant=BPM_Meters::save_point($other,0,['point_label'=>'Wasser leerstehend','unit'=>'m³']);$pids[]=$vacant;
 $vacantOld=BPM_Meters::save_device($other,$vacant,['number'=>$tag.'VACANTOLD','installed_on'=>'2020-01-01','active'=>1]);
 $vacantNew=BPM_Meters::exchange($other,$vacant,['old_meter_id'=>$vacantOld,'new_number'=>$tag.'VACANTNEW','change_date'=>'2026-01-01','end_value'=>'50','start_value'=>'1']);
 pcheck((int)$wpdb->get_var($wpdb->prepare('SELECT household_id FROM '.BPM_Portal::table('readings').' WHERE meter_id=%d',$vacantNew))===0,'Vacancy readings stay management-only without an invented tenant');
 pdeny(fn()=>BPM_Meters::save_device($uid,$pid,['meter_id'=>$new,'number'=>$tag.'NEW','installed_on'=>'2026-02-01','active'=>1]),'Device period cannot exclude existing start reading');
 $boundary=BPM_Meters::exchange($uid,$pid,['old_meter_id'=>$new,'new_number'=>$tag.'BOUNDARY','change_date'=>BPM_Portal::today()]);
 $selection=BPM_Portal::meters_for($h);pcheck(count($selection)===1&&(int)$selection[0]->id===$boundary,'Replacement-day selector resolves touching periods to new device');
 $planned=BPM_Meters::exchange($uid,$pid,['old_meter_id'=>$boundary,'new_number'=>$tag.'PLANNED','change_date'=>'2027-01-01']);
 $selection=BPM_Portal::meters_for($h);pcheck(count($selection)===1&&(int)$selection[0]->id===$boundary,'Scheduled future replacement leaves current device visible');
 pcheck((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.BPM_Portal::table('readings').' WHERE meter_id=%d',$planned))===0,'Blank optional start reading does not invent a zero reading');
 pdeny(fn()=>BPM_Meters::exchange($uid,$pid,['old_meter_id'=>$planned,'new_number'=>$tag.'OLD','change_date'=>'2027-02-01']),'Duplicate new serial rolls back scheduled replacement');
 pcheck($wpdb->get_var($wpdb->prepare('SELECT removed_on FROM '.BPM_Portal::table('meters').' WHERE id=%d',$planned))===null,'Failed duplicate replacement keeps previous validity open');
 wp_set_current_user(0);try{BPM_Meters::save_point($uid,0,['point_label'=>'Denied','unit'=>'m³']);throw new LogicException('Missing permission guard');}catch(RuntimeException $e){pcheck(true,'Unauthorized point mutation denied');}
 echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT),PHP_EOL;
}finally{
 wp_set_current_user(get_users(['role'=>'administrator','number'=>1])[0]->ID);
 foreach($wpdb->get_col($wpdb->prepare('SELECT id FROM '.BPM_Portal::table('meters').' WHERE number LIKE %s',$wpdb->esc_like($tag).'%'))as $mid){foreach($wpdb->get_col($wpdb->prepare('SELECT id FROM '.BPM_Portal::table('readings').' WHERE meter_id=%d',$mid))as $read){$wpdb->delete(BPM_Portal::table('events'),['object_id'=>$read,'kind'=>'exchange_reading_created']);}$wpdb->delete(BPM_Portal::table('readings'),['meter_id'=>$mid]);$wpdb->delete(BPM_Portal::table('meters'),['id'=>$mid]);$wpdb->query($wpdb->prepare('DELETE FROM '.BPM_Portal::table('events')." WHERE object_id=%d AND kind IN ('meter_device_saved','meter_device_replaced')",$mid));}
 foreach($pids as $id){$wpdb->delete(BPM_Portal::table('meter_points'),['id'=>$id]);$wpdb->delete(BPM_Portal::table('events'),['object_id'=>$id,'kind'=>'meter_point_saved']);}
 if($hid)$wpdb->delete(BPM_Portal::table('households'),['id'=>$hid]);foreach([$uid,$other]as $id)if($id)$wpdb->delete(BPM_Portal::table('units'),['id'=>$id]);if($bid)$wpdb->delete(BPM_Portal::table('buildings'),['id'=>$bid]);
}
