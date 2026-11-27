<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Disposable local fixture only; never run this test against production.
require getenv('BPM_TEST_WP_LOAD') ?: '/tmp/belger-portal-runtime/wordpress/wp-load.php';
if (!class_exists(BPM_Admin::class)) { require_once __DIR__ . '/../includes/class-admin.php'; }
if (!class_exists(BPM_Units_Admin::class)) { require_once __DIR__ . '/../includes/class-units-admin.php'; }
if (!str_starts_with(home_url(), 'http://127.0.0.1:')) { throw new RuntimeException('Local fixture required.'); }
$checks=0;
function ok($v,$label){global $checks;if(!$v){throw new Exception($label);}echo "OK $label\n";$checks++;}
function denied($f,$label){try{$f();}catch(InvalidArgumentException $e){ok(true,$label);return;}throw new Exception($label);}
global $wpdb;
$wpdb->query('BEGIN');
try {
 $tag=bin2hex(random_bytes(6));
 BPM_Portal::insert('households',['reference'=>'TEST-'.$tag,'label'=>'Migration fixture','unit_label'=>'Testgebäude '.$tag.' · EG links','status'=>'invited','created_at'=>BPM_Portal::now(),'tenancy_start'=>'2020-01-01']);$hid=(int)$wpdb->insert_id;
 BPM_Portal::insert('meters',['household_id'=>$hid,'number'=>'TEST-'.$tag,'label'=>'Kaltwasser','unit'=>'m³','active'=>1]);$mid=(int)$wpdb->insert_id;
 BPM_Portal::migrate_units();
 $h=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('households').' WHERE id=%d',$hid));
 $m=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('meters').' WHERE id=%d',$mid));
 ok($h->unit_id>0 && $m->unit_id===$h->unit_id,'Legacy contract and meter migrated to same apartment');
 $count=$wpdb->get_var('SELECT COUNT(*) FROM '.BPM_Portal::table('units'));BPM_Portal::migrate_units();ok($count===$wpdb->get_var('SELECT COUNT(*) FROM '.BPM_Portal::table('units')),'Migration repeated without duplicate apartments');
 ok(count(BPM_Portal::meters_for($h))===1,'Current tenant sees apartment meter');
 ok(BPM_Portal::meter_for_reading($mid,$h,'2020-01-01')->id===$m->id,'Reading on lease start accepted');
 denied(fn()=>BPM_Portal::meter_for_reading($mid,$h,'2019-12-31'),'Reading before lease rejected');
 $wrong=clone $h;$wrong->unit_id=(int)$h->unit_id+10000;denied(fn()=>BPM_Portal::meter_for_reading($mid,$wrong,'2026-01-01'),'Other apartment denied');
 $old=clone $h;$old->tenancy_end='2025-12-31';ok(BPM_Portal::meters_for($old)===[],'Former tenant has no current meter view');
 denied(fn()=>BPM_Portal::meter_for_reading($mid,$old,'2026-01-01'),'Old contract cannot submit next tenant period');
 $new=clone $h;$new->id+=10000;$new->tenancy_start='2026-01-01';ok(count(BPM_Portal::meters_for($new))===1,'Successor inherits apartment meter without reassignment');
 denied(fn()=>BPM_Portal::meter_for_reading($mid,$new,'2025-12-31'),'Successor cannot submit predecessor period');
 BPM_Portal::insert('documents',['household_id'=>$hid,'title'=>'Private','category'=>'vertrag','storage_key'=>str_repeat('a',48),'filename'=>'x.pdf','mime'=>'application/pdf','bytes'=>0,'sha256'=>str_repeat('b',64),'created_at'=>BPM_Portal::now()]);$did=(int)$wpdb->insert_id;
 try{BPM_Portal::owned('documents',$did,$new->id);throw new Exception('Document leaked');}catch(RuntimeException $e){ok(true,'Successor denied predecessor document despite same apartment');}
 $wpdb->update(BPM_Portal::table('meters'),['installed_on'=>'2024-01-01','removed_on'=>'2025-01-01'],['id'=>$mid]);
 denied(fn()=>BPM_Portal::meter_for_reading($mid,$h,'2023-12-31'),'Before device installation denied');
 denied(fn()=>BPM_Portal::meter_for_reading($mid,$h,'2025-01-02'),'After device removal denied');
 ok(count(BPM_Portal::meters_for($h))===0,'Removed meter hidden from current selection');
 echo "$checks checks passed\n";
} finally {$wpdb->query('ROLLBACK');}
