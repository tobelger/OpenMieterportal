<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require getenv('BPM_TEST_WP_LOAD') ?: '/tmp/belger-portal-runtime/wordpress/wp-load.php';
if(!str_starts_with(home_url(),'http://127.0.0.1:'))throw new RuntimeException('Local fixture required');
$endpoint=admin_url('admin-post.php');$original=get_option('bpm_consent_enabled');update_option('bpm_consent_enabled',1);
try{
 $cases=[['portal',BPM_Domain::CONSENT_VERSION,1,2],['portal','older-version',1,0],['widerrufen',BPM_Domain::CONSENT_VERSION,0,0],[null,null,0,0]];
 foreach($cases as [$choice,$version,$authority,$expected]){$preference=$choice?(object)['choice'=>$choice,'version'=>$version,'authority_confirmed'=>$authority,'created_at'=>'2026-11-22 10:00:00']:null;$needs_communication=!$preference||$choice!=='portal'||$version!==BPM_Domain::CONSENT_VERSION;ob_start();require __DIR__.'/../includes/view-communication.php';$html=ob_get_clean();$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);$count=$xp->query('//input[@type="checkbox" and @checked]')->length;if($count!==$expected)throw new RuntimeException('Consent checkbox mismatch');}
 echo "4 consent display checks passed\n";
}finally{update_option('bpm_consent_enabled',$original);}
