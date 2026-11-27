<?php
defined('ABSPATH') || exit;

/** No attachment records or public upload URLs are created. */
final class BPM_Storage {
    public static function root(): string {
        if (!defined('BPM_PRIVATE_DIR') || !is_string(BPM_PRIVATE_DIR)) {
            throw new RuntimeException('Der geschützte Dateispeicher wurde noch nicht eingerichtet.');
        }
        $root = realpath(BPM_PRIVATE_DIR);
        if (!$root || !is_dir($root) || !is_writable($root)) {
            throw new RuntimeException('Der geschützte Dateispeicher ist nicht verfügbar.');
        }
        $public_roots = [realpath(ABSPATH)];
        if (!empty($_SERVER['DOCUMENT_ROOT'])) { $public_roots[] = realpath($_SERVER['DOCUMENT_ROOT']); }
        if (defined('BPM_PUBLIC_ROOT')) { $public_roots[] = realpath(BPM_PUBLIC_ROOT); }
        foreach (array_filter($public_roots) as $public) {
            if (BPM_Domain::within($root, $public)) {
                throw new RuntimeException('Der Dateispeicher muss außerhalb des öffentlich erreichbaren Webverzeichnisses liegen.');
            }
        }
        return $root;
    }

    public static function upload(array $file, bool $pdf): array {
        $root = self::root();
        if(get_option('bpm_files_encryption_required',false))BPM_Crypto::ring();
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new InvalidArgumentException('Die Datei konnte nicht hochgeladen werden.');
        }
        $tmp = $file['tmp_name'];
        $size = filesize($tmp);
        if ($size === false || $size < 1 || $size > ($pdf ? 15 : 8) * 1024 * 1024) {
            throw new InvalidArgumentException('Die Datei ist leer oder zu groß (PDF: 15 MB, Foto: 8 MB).');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $key = bin2hex(random_bytes(24));
        $target = $root . '/' . $key;
        if ($pdf) {
            if ($mime !== 'application/pdf' || file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') { throw new InvalidArgumentException('Bitte eine gültige PDF-Datei auswählen.'); }
            $plain=file_get_contents($tmp);$name=sanitize_file_name($file['name']);
            if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf')$name.='.pdf';
        } else {
            if(!in_array($mime,['image/jpeg','image/png'],true)||!extension_loaded('gd'))throw new InvalidArgumentException('Bitte ein JPG- oder PNG-Foto auswählen.');
            $info=@getimagesize($tmp);if(!$info||$info[0]<1||$info[1]<1||$info[0]*$info[1]>12000000)throw new InvalidArgumentException('Das Foto darf höchstens 12 Megapixel groß sein.');
            $image=$mime==='image/jpeg'?@imagecreatefromjpeg($tmp):@imagecreatefrompng($tmp);if(!$image)throw new InvalidArgumentException('Das Foto ist nicht lesbar.');
            imagealphablending($image,true);ob_start();try{$ok=imagejpeg($image,null,88);$plain=ob_get_contents();}finally{ob_end_clean();imagedestroy($image);}
            if(!$ok)throw new RuntimeException('Das Foto konnte nicht gespeichert werden.');$mime='image/jpeg';$name='zaehlerfoto.jpg';
        }
        if(!is_string($plain)||$plain==='')throw new RuntimeException('Die Datei konnte nicht vollständig gelesen werden.');
        $bytes=strlen($plain);$hash=hash('sha256',$plain);
        $stored=BPM_Crypto::configured()?BPM_Crypto::seal($plain,'file/'.$key):$plain;
        $output=@fopen($target,'xb');if(!$output)throw new RuntimeException('Die Datei konnte nicht sicher gespeichert werden.');
        try{if(!chmod($target,$pdf?0400:0600)||fwrite($output,$stored)!==strlen($stored))throw new RuntimeException('Die geschützte Datei konnte nicht vollständig gespeichert werden.');}
        catch(Throwable $e){@unlink($target);throw $e;}finally{fclose($output);}
        return ['storage_key'=>$key,'filename'=>$name,'mime'=>$mime,'bytes'=>$bytes,'sha256'=>$hash];
    }
    public static function plaintext(string $key,bool $migration=false): string {
        $path=self::path($key);$size=filesize($path);
        if($size===false||$size<1||$size>32*1024*1024)throw new RuntimeException('Die Datei hat eine ungültige Größe.');
        $stored=file_get_contents($path);
        if(BPM_Crypto::sealed($stored))return BPM_Crypto::open($stored,'file/'.$key);
        if(!$migration&&get_option('bpm_files_encryption_required',false))throw new RuntimeException('Eine unverschlüsselte Datei wurde blockiert.');
        return $stored;
    }
    /** Plaintext snapshots stay in memory and are returned only after access checks. */
    public static function verified_document(string $key,string $sha256,int $bytes) {
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha256)||$bytes<1||$bytes>15*1024*1024)throw new RuntimeException('Das Dokument konnte nicht geprüft werden.');
        $plain=self::plaintext($key);
        if(strlen($plain)!==$bytes||!hash_equals($sha256,hash('sha256',$plain)))throw new RuntimeException('Die Integritätsprüfung dieses Dokuments ist fehlgeschlagen.');
        $snapshot=fopen('php://memory','w+b');if(!$snapshot||fwrite($snapshot,$plain)!==$bytes){if(is_resource($snapshot))fclose($snapshot);throw new RuntimeException('Das Dokument konnte nicht bereitgestellt werden.');}rewind($snapshot);return $snapshot;
    }
    public static function migrate_files(): int {
        global $wpdb;BPM_Crypto::ring();$root=self::root();$count=0;
        foreach(glob($root.'/*') as $path){$key=basename($path);if(!preg_match('/^[a-f0-9]{48}$/D',$key)||!is_file($path))continue;
            $plain=self::plaintext($key,true);$doc=BPM_Data::row($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('documents').' WHERE storage_key=%s',$key));
            if($doc&&(strlen($plain)!==(int)$doc->bytes||!hash_equals($doc->sha256,hash('sha256',$plain))))throw new RuntimeException('Eine Bestandsdatei hat die Integritätsprüfung nicht bestanden.');
            $stored=BPM_Crypto::seal($plain,'file/'.$key);if(!hash_equals($plain,BPM_Crypto::open($stored,'file/'.$key)))throw new RuntimeException('Die Dateimigration konnte nicht verifiziert werden.');
            $tmp=$root.'/.migration-'.bin2hex(random_bytes(16));$out=fopen($tmp,'xb');if(!$out)throw new RuntimeException('Die Dateimigration konnte nicht vorbereitet werden.');
            try{if(!chmod($tmp,$doc?0400:0600)||fwrite($out,$stored)!==strlen($stored))throw new RuntimeException('Die Dateimigration konnte nicht gespeichert werden.');}catch(Throwable $e){@unlink($tmp);throw $e;}finally{fclose($out);}
            if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Die Dateimigration konnte nicht abgeschlossen werden.');}$count++;
        }
        update_option('bpm_files_encryption_required',true,false);return $count;
    }

    public static function path(string $key): string {
        if (!preg_match('/^[a-f0-9]{48}$/D', $key)) { throw new RuntimeException('Ungültiger Dateischlüssel.'); }
        $root = self::root();
        $path = realpath($root . '/' . $key);
        if (!$path || is_link($root . '/' . $key) || !BPM_Domain::within($path, $root) || !is_file($path)) {
            throw new RuntimeException('Die Datei ist nicht verfügbar.');
        }
        return $path;
    }
}
