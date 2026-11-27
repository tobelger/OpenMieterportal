<?php
// Isolated regression test. No WordPress database, SMTP connection or real users.
// Pass SupportCandy's official includes/class-wpsc-email-notifications.php as argv[1].
define('ABSPATH', __DIR__);
$filters=[];$households=[];$frequencies=[];$notices=[];
function add_action(...$args) {}
function add_shortcode(...$args) {}
function add_filter($name,$fn,$priority=10,$accepted=1){global $filters;$filters[$name][$priority][]=[$fn,$accepted];}
function apply_filters($name,$value,...$args){global $filters;$items=$filters[$name]??[];ksort($items);foreach($items as $callbacks)foreach($callbacks as [$fn,$n])$value=$fn(...array_slice([$value,...$args],0,$n));return $value;}
function __return_empty_array(){return [];}
function is_email($email){return filter_var($email,FILTER_VALIDATE_EMAIL);}
function get_option($key,$default=false){return $key==='wpsc-en-general'?['from-name'=>'Test','from-email'=>'sender@example.test','reply-to'=>'']:$default;}
function get_userdata($uid){return new WP_User($uid);}
function esc_url($url){return $url;}
class WP_User{public $user_email='tenant@example.test';public function __construct(public $ID){}}
class BPM_Portal{
 static function household($uid){global $households;return $households[$uid]??null;}
 static function can_access($h){return $h->active;}
 static function url(){return 'https://example.test/portal/';}
}
class BPM_Account{
 static function frequency($uid){global $frequencies;return $frequencies[$uid]??'immediate';}
 static function notify($hid){global $notices;$notices[]=$hid;}
}
class WPSC_Translations{static function get($key,$default){return $default;}}
class WPSC_Macros{static function replace($body,...$args){return $body;}}
class WPSC_Ticket_Conditions{static function is_valid(...$args){return true;}}
require $argv[2] ?? dirname(__DIR__).'/includes/class-tickets.php';
require $argv[1];
BPM_Tickets::boot();
class ProbeNotification extends WPSC_Email_Notifications{
 public $raw_to=[];public $raw_cc=[];public $raw_bcc=[];
 public function set_subject(){$this->subject='Test';}
 public function set_to_addresses(){$this->to=apply_filters('wpsc_en_to_addresses',$this->raw_to,$this);}
 public function set_cc_addresses(){$this->cc=apply_filters('wpsc_en_cc_addresses',$this->raw_cc,$this);}
 public function set_bcc_addresses(){$this->bcc=apply_filters('wpsc_en_bcc_addresses',$this->raw_bcc,$this);}
}
$checks=0;
function verify($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function probe($reference,$active,$frequency,$to,$expected,$expected_notices,$cc=[]){
 global $households,$frequencies,$notices;
 $households=[1=>(object)['id'=>7,'reference'=>$reference,'active'=>$active]];$frequencies=[1=>$frequency];$notices=[];
 $en=new ProbeNotification();$en->ticket=(object)['customer'=>(object)['user'=>new WP_User(1)],'misc'=>[]];$en->template_key=1;
 $en->template=['is_enable'=>1,'body'=>['text'=>'Generic notice'],'conditions'=>[]];$en->raw_to=$to;$en->raw_cc=$cc;
 $valid=$en->is_valid();verify($valid===$expected,'SupportCandy native validation: '.$reference.' '.$frequency);
 verify(count($notices)===$expected_notices,'Digest queued correctly');
 if($valid){$before=$en->to;$en=apply_filters('wpsc_en_before_sending',$en);verify($en->to===$before&&count($en->to)>0,'Late filter preserves validated recipients');verify($en->cc===[]&&$en->bcc===[],'No CC/BCC disclosure');}
 return $en;
}
probe('DEMO-A',true,'immediate',['tenant@example.test'],false,0,['manager@example.test']);
probe('REAL',false,'immediate',['tenant@example.test'],false,0);
probe('REAL',true,'daily',['tenant@example.test'],false,1);
probe('REAL',true,'weekly',['TENANT@example.test'],false,1);
probe('REAL',true,'immediate',['tenant@example.test'],true,0);
$en=probe('REAL',true,'daily',['tenant@example.test','manager@example.test'],true,1);verify($en->to===['manager@example.test'],'Keep manager while tenant is deferred');
$en=probe('REAL',false,'immediate',['tenant@example.test','manager@example.test'],true,0);verify($en->to===['manager@example.test'],'Keep manager for expired tenant');
probe('REAL',true,'immediate',['','not-an-address'],false,0);
probe('REAL',true,'immediate',[],false,0,['manager@example.test']);
echo "$checks ticket notification checks passed; no mail sent.\n";
