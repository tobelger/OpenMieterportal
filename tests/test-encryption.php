<?php
$root='/tmp/bpm-encryption-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/files',0700);
define('BPM_KEY_FILE',$root.'/keys.json');define('BPM_PRIVATE_DIR',$root.'/files');
$id=bin2hex(random_bytes(8));file_put_contents(BPM_KEY_FILE,json_encode(['active'=>$id,'keys'=>[$id=>base64_encode(random_bytes(32))]]));chmod(BPM_KEY_FILE,0600);
require getenv('BPM_TEST_WP_LOAD') ?: '/tmp/belger-portal-runtime/wordpress/wp-load.php';
if(!str_starts_with(home_url(),'http://127.0.0.1:'))throw new RuntimeException('Local fixture only');
add_filter('pre_wp_mail',static fn()=>true,PHP_INT_MAX);global $wpdb;$checks=0;$uid=0;
function secure_check($yes,$label){global $checks;if(!$yes)throw new RuntimeException($label);$checks++;}
function secure_reject(callable $fn){try{$fn();}catch(RuntimeException|InvalidArgumentException $e){secure_check(true,'rejection');return;}throw new RuntimeException('Expected rejection');}
$wpdb->query('START TRANSACTION');
try{
 $plain='Sensitive test data';$box=BPM_Crypto::seal($plain,'row/test/1');secure_check(!str_contains($box,$plain),'ciphertext');secure_check(BPM_Crypto::open($box,'row/test/1')===$plain,'roundtrip');secure_check($box!==BPM_Crypto::seal($plain,'row/test/1'),'random nonce');secure_reject(fn()=>BPM_Crypto::open($box,'row/test/2'));
 $raw=json_decode(base64_decode(substr($box,5)),true);$raw['c']=base64_encode('modified');secure_reject(fn()=>BPM_Crypto::open('OMP2.'.base64_encode(json_encode($raw)),'row/test/1'));
 $tag=bin2hex(random_bytes(5));
 BPM_Portal::insert('buildings',['reference'=>'SEC-'.$tag,'label'=>'Secret building','address'=>'Secret street 11']);$bid=(int)$wpdb->insert_id;
 BPM_Portal::insert('units',['reference'=>'SEC-U-'.$tag,'building_id'=>$bid,'label'=>'Secret unit']);$unit=(int)$wpdb->insert_id;
 $uid=wp_insert_user(['user_login'=>'secure-'.$tag,'user_pass'=>'Secure fixture password!2026','user_email'=>'secure-'.$tag.'@example.test','role'=>'bpm_tenant']);if(is_wp_error($uid))throw new RuntimeException('User failed');
 BPM_Portal::insert('households',['reference'=>'DEMO-SEC-'.$tag,'label'=>'Secret tenant','unit_label'=>'Secret street 11 · Secret unit','unit_id'=>$unit,'user_id'=>$uid,'expected_email'=>'secure@example.test','status'=>'active','created_at'=>BPM_Portal::now(),'tenancy_start'=>'2024-01-01']);$hid=(int)$wpdb->insert_id;
 $h=BPM_Portal::household($uid);secure_check($h->label==='Secret tenant','household hydrated');secure_check(BPM_Portal::unit($unit)->address==='Secret street 11','joined address hydrated');secure_check(in_array($hid,BPM_Data::search_households('Secret tenant'),true),'encrypted search');
 $array=BPM_Data::rows($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('households').' WHERE id=%d',$hid),ARRAY_A);secure_check($array[0]['label']==='Secret tenant','associative rows hydrated');
 $raw=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('households').' WHERE id=%d',$hid));secure_check($raw->label===''&&$raw->unit_label===''&&$raw->expected_email===''&&!str_contains($raw->protected_data,'Secret tenant'),'no plaintext PII');
 BPM_Data::update(BPM_Portal::table('households'),['label'=>'Changed tenant'],['id'=>$hid]);secure_check(BPM_Portal::household($uid)->expected_email==='secure@example.test','partial update preserves encrypted fields');
 BPM_Portal::insert('rent_terms',['household_id'=>$hid,'rent_cents'=>75000,'advance_cents'=>18000,'operating_cents'=>10000,'heating_cents'=>8000,'garage_cents'=>4000,'effective_from'=>'2025-01-01','created_at'=>BPM_Portal::now(),'updated_at'=>BPM_Portal::now(),'actor_id'=>$uid]);$rent=BPM_Portal::current_rent($hid);secure_check($rent->rent_cents===75000&&$rent->garage_cents===4000,'rent hydration');$raw=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('rent_terms').' WHERE household_id=%d',$hid));secure_check((int)$raw->rent_cents===0&&!str_contains($raw->protected_data,'75000'),'rent not in cleartext');
 wp_set_current_user($uid);$profile=['address'=>'Private contact','phone'=>'123456','holder'=>'Secret holder','iban'=>'DE89370400440532013000','bic'=>'','current_password'=>'wrong'];secure_reject(fn()=>BPM_Account::save_profile($h,$profile));$profile['current_password']='Secure fixture password!2026';BPM_Account::save_profile($h,$profile);secure_check(BPM_Account::profile($h)['iban']===$profile['iban'],'profile encrypted');$encoded=get_user_meta($uid,'bpm_account_profile',true);secure_check(BPM_Crypto::sealed($encoded),'new profile format');secure_reject(fn()=>BPM_Account::decrypt($encoded,$uid+1));
 $key=bin2hex(random_bytes(24));$pdf="%PDF-1.4\nSynthetic secure document\n";file_put_contents(BPM_PRIVATE_DIR.'/'.$key,$pdf);chmod(BPM_PRIVATE_DIR.'/'.$key,0400);
 BPM_Portal::insert('documents',['household_id'=>$hid,'title'=>'Private document','category'=>'sonstiges','storage_key'=>$key,'filename'=>'private.pdf','mime'=>'application/pdf','bytes'=>strlen($pdf),'sha256'=>hash('sha256',$pdf),'created_at'=>BPM_Portal::now()]);
 secure_check(BPM_Storage::migrate_files()===1,'file migrated');secure_check(!str_contains(file_get_contents(BPM_PRIVATE_DIR.'/'.$key),'%PDF'),'encrypted file');$stream=BPM_Storage::verified_document($key,hash('sha256',$pdf),strlen($pdf));secure_check(stream_get_contents($stream)===$pdf,'verified decrypted download');fclose($stream);
 $ring=json_decode(file_get_contents(BPM_KEY_FILE),true);$new=bin2hex(random_bytes(8));$ring['keys'][$new]=base64_encode(random_bytes(32));$ring['active']=$new;file_put_contents(BPM_KEY_FILE,json_encode($ring));secure_check(BPM_Crypto::open($box,'row/test/1')===$plain,'old key retained after rotation');
 rename(BPM_KEY_FILE,BPM_KEY_FILE.'.offline');secure_reject(fn()=>BPM_Storage::plaintext($key));rename(BPM_KEY_FILE.'.offline',BPM_KEY_FILE);
 BPM_Data::migrate();secure_check(BPM_Portal::household($uid)->label==='Changed tenant','migration preserves values');
 $_SERVER['REMOTE_ADDR']='192.0.2.'.random_int(1,254);for($i=0;$i<30;$i++)$auth=wp_authenticate('secure-'.$tag,'Secure fixture password!2026');secure_check($auth instanceof WP_User,'valid login before limit');$auth=wp_authenticate('secure-'.$tag,'Secure fixture password!2026');secure_check(is_wp_error($auth)&&$auth->get_error_code()==='bpm_login_limit','valid password cannot bypass login limit');
 echo "$checks encryption and security checks passed; no mail sent.\n";
}finally{$wpdb->query('ROLLBACK');wp_cache_flush();foreach(glob(BPM_PRIVATE_DIR.'/*') as $file)unlink($file);rmdir(BPM_PRIVATE_DIR);unlink(BPM_KEY_FILE);rmdir($root);}
