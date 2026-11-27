<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require getenv('BPM_TEST_WP_LOAD') ?: '/tmp/belger-portal-runtime/wordpress/wp-load.php';
if(!str_starts_with(home_url(),'http://127.0.0.1:'))throw new RuntimeException('Local fixture required');
global $wpdb;$checks=0;$mails=[];$mail_ok=true;
add_filter('pre_wp_mail',static function($pre,$args)use(&$mails,&$mail_ok){$mails[]=$args;return $mail_ok;},PHP_INT_MAX,2);
function account_check($value,$label){global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
function account_reject(callable $fn){try{$fn();}catch(RuntimeException|InvalidArgumentException $e){account_check(true,'rejection');return;}throw new RuntimeException('Expected rejection');}
$tag=bin2hex(random_bytes(5));$uids=[];$hids=[];
try{
 foreach(['a','b']as $party){$uid=wp_insert_user(['user_login'=>'account-'.$tag.$party,'user_email'=>'account-'.$tag.$party.'@example.test','user_pass'=>'Local fixture test password 2026!','role'=>'bpm_tenant']);if(is_wp_error($uid))throw new Exception('User failed');$uids[]=$uid;
 BPM_Portal::insert('households',['reference'=>'TEST-ACCOUNT-'.$tag.$party,'label'=>'Test '.$party,'unit_label'=>'Musterstraße 1, 12345 Teststadt','user_id'=>$uid,'status'=>'active','created_at'=>BPM_Portal::now()]);$hids[]=(int)$wpdb->insert_id;}
 $h=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('households').' WHERE id=%d',$hids[0]));$other=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('households').' WHERE id=%d',$hids[1]));wp_set_current_user($uids[0]);
 $profile=['address'=>"Musterstraße 2\n12345 Teststadt",'phone'=>'+49 30 123456','holder'=>'Test Mietpartei','iban'=>'DE89 3704 0044 0532 0130 00','bic'=>'COBADEFFXXX','current_password'=>'Local fixture test password 2026!'];
 account_reject(fn()=>BPM_Account::save_profile($other,$profile));
 account_reject(fn()=>BPM_Account::save_profile($h,array_merge($profile,['iban'=>''])));
 account_reject(fn()=>BPM_Account::save_profile($h,array_merge($profile,['iban'=>'DE88370400440532013000'])));
 account_reject(fn()=>BPM_Account::save_profile($h,array_merge($profile,['holder'=>''])));
 BPM_Account::save_profile($h,$profile);$saved=BPM_Account::profile($h);
 account_check($saved['iban']==='DE89370400440532013000','IBAN normalized');account_check($saved['address']===$profile['address'],'Address saved');
 $encrypted=get_user_meta($uids[0],'bpm_account_profile',true);account_check(!str_contains($encrypted,'DE8937')&&!str_contains($encrypted,'Musterstraße'),'Encrypted at rest');
 account_reject(fn()=>BPM_Account::decrypt(substr($encrypted,0,-5).'xxxxx'));
 foreach(['immediate','daily','weekly']as $f){BPM_Account::save_settings($h,$f);account_check(BPM_Account::frequency($uids[0])===$f,'Frequency');}
 account_reject(fn()=>BPM_Account::save_settings($h,'never'));
 account_reject(fn()=>BPM_Account::request_email($h,'new-'.$tag.'@example.test','wrong'));
 $before=get_userdata($uids[0])->user_email;$new='new-'.$tag.'@example.test';BPM_Account::request_email($h,$new,'Local fixture test password 2026!');
 $mail=end($mails);preg_match('/email-bestaetigen=([a-f0-9]{64})/',$mail['message'],$match);$token=$match[1];
 account_check(get_userdata($uids[0])->user_email===$before,'Old email stays active');account_check(!str_contains(wp_json_encode(get_user_meta($uids[0],'bpm_pending_email',true)),$token),'Only token digest stored');
 wp_set_current_user($uids[1]);account_reject(fn()=>BPM_Account::confirm_email($other,$token));wp_set_current_user($uids[0]);
 $pending=get_user_meta($uids[0],'bpm_pending_email',true);$expired=$pending;$expired['expires']=time()-1;update_user_meta($uids[0],'bpm_pending_email',$expired);account_reject(fn()=>BPM_Account::confirm_email($h,$token));update_user_meta($uids[0],'bpm_pending_email',$pending);
 BPM_Account::confirm_email($h,$token);account_check(get_userdata($uids[0])->user_email===$new,'Confirmed email applied');account_check(!BPM_Account::pending($uids[0]),'Pending cleared');account_reject(fn()=>BPM_Account::confirm_email($h,$token));
 delete_user_meta($uids[0],'bpm_email_requested_at');$mail_ok=false;account_reject(fn()=>BPM_Account::request_email($h,'retry-'.$tag.'@example.test','Local fixture test password 2026!'));account_check(!BPM_Account::pending($uids[0]),'Failed mail rolled back');$mail_ok=true;
 BPM_Account::save_settings($h,'daily');$count=count($mails);BPM_Account::notify($h->id);BPM_Account::digest($h->id);account_check(count($mails)===$count,'Daily queued without immediate mail');
 $wpdb->query($wpdb->prepare('UPDATE '.BPM_Portal::table('notification_queue').' SET created_at=%s WHERE household_id=%d',gmdate('Y-m-d H:i:s',time()-86401),$h->id));BPM_Account::digest($h->id);account_check(count($mails)===$count+1,'Daily sends one summary');
 account_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.BPM_Portal::table('notification_queue').' WHERE household_id=%d',$h->id))===0,'Queue cleared');
 BPM_Account::save_settings($h,'weekly');$count=count($mails);BPM_Account::notify($h->id);$wpdb->query($wpdb->prepare('UPDATE '.BPM_Portal::table('notification_queue').' SET created_at=%s WHERE household_id=%d',gmdate('Y-m-d H:i:s',time()-86401),$h->id));BPM_Account::digest($h->id);account_check(count($mails)===$count,'Weekly waits longer');
 $wpdb->query($wpdb->prepare('UPDATE '.BPM_Portal::table('notification_queue').' SET created_at=%s WHERE household_id=%d',gmdate('Y-m-d H:i:s',time()-7*86400-1),$h->id));BPM_Account::digest($h->id);account_check(count($mails)===$count+1,'Weekly summary');
 BPM_Account::save_settings($h,'immediate');$mail_ok=false;BPM_Account::notify($h->id);account_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.BPM_Portal::table('notification_queue').' WHERE household_id=%d',$h->id))===1,'Failed notice retained');$mail_ok=true;BPM_Account::digest($h->id);account_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.BPM_Portal::table('notification_queue').' WHERE household_id=%d',$h->id))===0,'Failed notice retried');
 $html=BPM_Portal::render();
 account_check(str_contains($html,'Vertragsübersicht'),'Contract overview label');
 account_check(str_contains($html,'bpm-user-menu'),'Account menu rendered');
 account_check(str_contains($html,'id="bpm-account-iban" name="iban" maxlength="50" required'),'Required bank field');
 account_check(!str_contains($html,'Kontakt-E-Mail</dt>'),'Email removed from contract overview');
 file_put_contents('/tmp/bpm-account-fixture.html',$html);
 echo "$checks account security and notification checks passed\n";
}finally{foreach($hids as $hid){foreach(['households','notification_queue','events']as $table)$wpdb->delete(BPM_Portal::table($table),[$table==='households'?'id':'household_id'=>$hid]);}require_once ABSPATH.'wp-admin/includes/user.php';foreach($uids as $uid)wp_delete_user($uid);}
