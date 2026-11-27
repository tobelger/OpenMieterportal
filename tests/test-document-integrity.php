<?php
$base=sys_get_temp_dir().'/bpm-integrity-'.bin2hex(random_bytes(6));
mkdir($base,0700);mkdir($base.'/public',0700);mkdir($base.'/private',0700);
define('ABSPATH',$base.'/public/');define('BPM_PRIVATE_DIR',$base.'/private');
require __DIR__.'/../includes/class-domain.php';
require __DIR__.'/../includes/class-crypto.php';
function get_option($key,$default=false){return $default;}
require __DIR__.'/../includes/class-storage.php';
$key=str_repeat('b',48);$path=$base.'/private/'.$key;$original="%PDF-1.4\nOriginal test document\n";$hash=hash('sha256',$original);$checks=0;
function reject(callable $fn):void{global $checks;try{$fn();}catch(RuntimeException $e){$checks++;return;}throw new Exception('Altered document was accepted');}
try{
 file_put_contents($path,$original);$handle=BPM_Storage::verified_document($key,$hash,strlen($original));
 if(stream_get_contents($handle)!==$original)throw new Exception('Original mismatch');fclose($handle);$checks++;
 foreach([str_replace('Original','Modified',$original),$original.'x',substr($original,0,-1)]as $changed){file_put_contents($path,$changed);reject(fn()=>BPM_Storage::verified_document($key,$hash,strlen($original)));}
 file_put_contents($path,$original);reject(fn()=>BPM_Storage::verified_document($key,'',strlen($original)));
 reject(fn()=>BPM_Storage::verified_document($key,$hash,0));
 $handle=BPM_Storage::verified_document($key,$hash,strlen($original));file_put_contents($path,'changed after verification');
 if(stream_get_contents($handle)!==$original)throw new Exception('Snapshot changed after check');fclose($handle);$checks++;
 unlink($path);reject(fn()=>BPM_Storage::verified_document($key,$hash,strlen($original)));
 echo "$checks document integrity checks passed\n";
}finally{@unlink($path);rmdir($base.'/private');rmdir($base.'/public');rmdir($base);}
