<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require getenv('BPM_TEST_WP_LOAD') ?: '/tmp/belger-portal-runtime/wordpress/wp-load.php';
if (!class_exists(BPM_Admin::class)) { require_once __DIR__ . '/../includes/class-admin.php'; }
if (!class_exists(BPM_Units_Admin::class)) { require_once __DIR__ . '/../includes/class-units-admin.php'; }
if (!str_starts_with(home_url(),'http://127.0.0.1:')) { throw new RuntimeException('Disposable local fixture only'); }
$tag='UNITTEST-'.bin2hex(random_bytes(5));$checks=[];$bid=0;$uids=[];$hid=0;
function unit_check($value,$label){global $checks;if(!$value)throw new RuntimeException($label);$checks[]=$label;}
function unit_denied($call,$label){try{$call();}catch(InvalidArgumentException $e){unit_check(true,$label);return;}throw new RuntimeException($label);}
$admin=get_users(['role'=>'administrator','number'=>1])[0];wp_set_current_user($admin->ID);
try {
 BPM_Portal::insert('buildings',['reference'=>$tag,'label'=>'Unit admin test','address'=>'Teststraße 1']);$bid=(int)$wpdb->insert_id;
 foreach(['A','B'] as $name){BPM_Portal::insert('units',['building_id'=>$bid,'reference'=>$tag.'-'.$name,'label'=>'Wohnung '.$name]);$uids[]=(int)$wpdb->insert_id;}
 BPM_Portal::insert('households',['reference'=>$tag.'-contract','label'=>'Test household','unit_label'=>'Teststraße 1 · Wohnung A','unit_id'=>$uids[0],'status'=>'invited','created_at'=>BPM_Portal::now(),'tenancy_start'=>'2020-01-01']);$hid=(int)$wpdb->insert_id;
 BPM_Units_Admin::save_unit($uids[0],['unit_reference'=>$tag.'-A','unit_name'=>'Wohnung A korrigiert']);
 unit_check(BPM_Portal::unit($uids[0])->label==='Wohnung A korrigiert','Apartment label editable');
 unit_check($wpdb->get_var($wpdb->prepare('SELECT unit_label FROM '.BPM_Portal::table('households').' WHERE id=%d',$hid))==='Teststraße 1 · Wohnung A korrigiert','Tenancy display follows apartment label');
 unit_denied(fn()=>BPM_Units_Admin::save_unit($uids[0],['unit_reference'=>$tag.'-B','unit_name'=>'Duplicate']),'Duplicate apartment reference rejected');
 $values=['number'=>$tag.'-meter','meter_label'=>'Kaltwasser','unit'=>'m³','active'=>1,'installed_on'=>'2024-01-01','removed_on'=>''];
 $mid=BPM_Units_Admin::save_meter($uids[1],$values);
 $m=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('meters').' WHERE id=%d',$mid));
 unit_check((int)$m->unit_id===$uids[1]&&(int)$m->household_id===0,'Meter created directly for vacant apartment');
 unit_denied(fn()=>BPM_Units_Admin::save_meter($uids[0],$values),'Duplicate meter number rejected');
 unit_denied(fn()=>BPM_Units_Admin::save_meter($uids[0],array_merge($values,['meter_id'=>$mid])),'Cannot edit another apartment meter');
 $values['meter_id']=$mid;$values['meter_label']='Kaltwasser Küche';BPM_Units_Admin::save_meter($uids[1],$values);
 unit_check($wpdb->get_var($wpdb->prepare('SELECT label FROM '.BPM_Portal::table('meters').' WHERE id=%d',$mid))==='Kaltwasser Küche','Meter metadata editable');
 BPM_Portal::insert('readings',['household_id'=>$hid,'meter_id'=>$mid,'value'=>'123.456','read_at'=>'2025-12-31','source'=>'test','status'=>'bestaetigt','photo_key'=>'','created_at'=>BPM_Portal::now()]);
 unit_denied(fn()=>BPM_Units_Admin::save_meter($uids[1],array_merge($values,['unit'=>'kWh'])),'Historical readings protect measurement unit');
 unit_denied(fn()=>BPM_Units_Admin::save_meter($uids[1],array_merge($values,['removed_on'=>'2025-01-01'])),'Historical reading cannot fall after removal date');
 unit_denied(fn()=>BPM_Units_Admin::save_meter($uids[1],array_merge($values,['installed_on'=>'2026-01-01'])),'Historical reading cannot fall before installation date');
 unit_denied(fn()=>BPM_Units_Admin::assign_meter($uids[0],$mid),'Cannot move assigned device history into another apartment');
 unit_check($wpdb->get_var($wpdb->prepare('SELECT value FROM '.BPM_Portal::table('readings').' WHERE meter_id=%d',$mid))==='123.456','Existing reading preserved exactly');
 BPM_Portal::insert('meters',['household_id'=>0,'unit_id'=>null,'number'=>$tag.'-free','label'=>'Freier Zähler','unit'=>'m³','active'=>1]);$free=(int)$wpdb->insert_id;
 BPM_Units_Admin::assign_meter($uids[0],$free);
 unit_check((int)$wpdb->get_var($wpdb->prepare('SELECT unit_id FROM '.BPM_Portal::table('meters').' WHERE id=%d',$free))===$uids[0],'Previously unassigned meter assigned without tenant');
 $_GET=['unit_id'=>$uids[1]];ob_start();BPM_Units_Admin::render();$html=ob_get_clean();
 unit_check(str_contains($html,'device_save')&&str_contains($html,'Wohnung bearbeiten'),'Vacant apartment renders complete editor');
 wp_set_current_user(0);
 foreach([fn()=>BPM_Units_Admin::save_unit($uids[0],['unit_reference'=>'x','unit_name'=>'x']),fn()=>BPM_Units_Admin::save_meter($uids[0],$values),fn()=>BPM_Units_Admin::assign_meter($uids[0],$free)]as $call){try{$call();throw new LogicException('Unauthorized operation allowed');}catch(RuntimeException $e){unit_check(true,'Unauthenticated write denied');}}
 echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT),PHP_EOL;
} finally {
 wp_set_current_user($admin->ID);
 $mids=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.BPM_Portal::table('meters').' WHERE number LIKE %s',$wpdb->esc_like($tag).'%'));
 foreach($mids as $mid){$wpdb->delete(BPM_Portal::table('readings'),['meter_id'=>$mid]);$wpdb->delete(BPM_Portal::table('meters'),['id'=>$mid]);$wpdb->query($wpdb->prepare('DELETE FROM '.BPM_Portal::table('events')." WHERE object_id=%d AND kind IN ('unit_meter_saved','unit_meter_assigned','meter_device_saved','meter_device_replaced')",$mid));}
 foreach($uids as $uid){$wpdb->delete(BPM_Portal::table('meter_points'),['unit_id'=>$uid]);$wpdb->delete(BPM_Portal::table('units'),['id'=>$uid]);$wpdb->delete(BPM_Portal::table('events'),['object_id'=>$uid,'kind'=>'unit_updated']);}
 if($hid)$wpdb->delete(BPM_Portal::table('households'),['id'=>$hid]);if($bid)$wpdb->delete(BPM_Portal::table('buildings'),['id'=>$bid]);
}
