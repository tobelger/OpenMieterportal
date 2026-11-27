<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Disposable localhost fixture; all synthetic rows are rolled back.
require getenv('BPM_TEST_WP_LOAD') ?: '/tmp/belger-portal-runtime/wordpress/wp-load.php';
if (!class_exists(BPM_Admin::class)) { require_once __DIR__ . '/../includes/class-admin.php'; }
if (!class_exists(BPM_Units_Admin::class)) { require_once __DIR__ . '/../includes/class-units-admin.php'; }
if (!str_starts_with(home_url(), 'http://127.0.0.1:')) { throw new RuntimeException('Local fixture required.'); }
$admin=get_users(['role'=>'administrator','number'=>1])[0];wp_set_current_user($admin->ID);
global $wpdb;$wpdb->query('BEGIN');$checks=0;
function check_list($value,$label){global $checks;if(!$value)throw new RuntimeException($label);$checks++;echo "OK $label\n";}
function list_view($query){$_GET=$query;ob_start();BPM_Admin::render();$html=ob_get_clean();$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);return [$html,$xp->query('//ul[@class="bpm-households"]/li')->length];}
try{
 $tag='PAG-'.bin2hex(random_bytes(5));BPM_Portal::insert('buildings',['reference'=>$tag,'label'=>$tag,'address'=>'Teststraße']);$bid=(int)$wpdb->insert_id;
 BPM_Portal::insert('units',['reference'=>$tag,'building_id'=>$bid,'label'=>'EG']);$uid=(int)$wpdb->insert_id;
 for($i=1;$i<=27;$i++){BPM_Portal::insert('households',['reference'=>$tag.'-'.$i,'label'=>sprintf('Mietpartei %02d',$i),'unit_label'=>'Teststraße · EG','unit_id'=>$uid,'status'=>'invited','created_at'=>BPM_Portal::now()]);}
 [$html,$n]=list_view(['bpm_building'=>$bid]);check_list($n===10 && str_contains($html,'1–10 von 27'),'First page loads ten contracts');
 [$html,$n]=list_view(['bpm_building'=>$bid,'bpm_paged'=>2]);check_list($n===10 && str_contains($html,'11–20 von 27'),'Second page loads next ten contracts');check_list(str_contains(html_entity_decode($html),'bpm_building='.$bid.'&bpm_paged=3'),'Pagination preserves building filter');
 [$html,$n]=list_view(['bpm_building'=>$bid,'bpm_paged'=>3]);check_list($n===7 && str_contains($html,'21–27 von 27'),'Last page loads remaining seven');
 [$html,$n]=list_view(['bpm_building'=>$bid,'bpm_paged'=>999999]);check_list($n===7 && str_contains($html,'Seite 3 von 3'),'Oversized page clamps to existing last page');
 [$html,$n]=list_view(['bpm_building'=>$bid,'bpm_search'=>'Mietpartei 02']);check_list($n===1,'Free text narrows results');
 [$html,$n]=list_view(['bpm_building'=>$bid,'bpm_search'=>'%']);check_list($n===0,'Search wildcard is treated literally');
 [$html,$n]=list_view(['bpm_building'=>$bid,'bpm_status'=>'active']);check_list($n===0,'Status combines with building filter');
 [$html,$n]=list_view(['bpm_building'=>$bid,'bpm_unit'=>$uid,'bpm_search'=>$tag]);check_list($n===10,'Apartment and contract reference filters combine');
 echo "$checks pagination checks passed\n";
}finally{$wpdb->query('ROLLBACK');}
