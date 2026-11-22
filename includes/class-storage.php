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
            if ($mime !== 'application/pdf' || file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') {
                throw new InvalidArgumentException('Bitte eine gültige PDF-Datei auswählen.');
            }
            // Exclusive creation: a published file is never overwritten by an upload.
            $output = @fopen($target, 'xb');
            if (!$output) { throw new RuntimeException('Die Datei konnte nicht sicher gespeichert werden.'); }
            $input = @fopen($tmp, 'rb');
            try {
                if (!$input || stream_copy_to_stream($input, $output) !== $size) {
                    throw new RuntimeException('Die Datei konnte nicht vollständig gespeichert werden.');
                }
            } catch (Throwable $e) { @unlink($target); throw $e; }
            finally { if (is_resource($input)) { fclose($input); } fclose($output); }
            $mime = 'application/pdf';
            $name = sanitize_file_name($file['name']);
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') { $name .= '.pdf'; }
        } else {
            if (!in_array($mime, ['image/jpeg', 'image/png'], true) || !extension_loaded('gd')) {
                throw new InvalidArgumentException('Bitte ein JPG- oder PNG-Foto auswählen. Das Bildmodul muss verfügbar sein.');
            }
            $info = @getimagesize($tmp);
            if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 12000000) {
                throw new InvalidArgumentException('Das Foto darf höchstens 12 Megapixel groß sein.');
            }
            $image = $mime === 'image/jpeg' ? @imagecreatefromjpeg($tmp) : @imagecreatefrompng($tmp);
            if (!$image) { throw new InvalidArgumentException('Das Foto ist nicht lesbar.'); }
            // Re-encoding strips EXIF/GPS and any appended payload. No original is retained.
            imagealphablending($image, true);
            $ok = imagejpeg($image, $target, 88);
            imagedestroy($image);
            if (!$ok) { throw new RuntimeException('Das Foto konnte nicht gespeichert werden.'); }
            $mime = 'image/jpeg';
            $name = 'zaehlerfoto.jpg';
        }
        if (!chmod($target, $pdf ? 0400 : 0600)) { @unlink($target); throw new RuntimeException('Die Dateirechte konnten nicht gesetzt werden.'); }
        return ['storage_key' => $key, 'filename' => $name, 'mime' => $mime, 'bytes' => filesize($target), 'sha256' => hash_file('sha256', $target)];
    }

    /** Return only a verified snapshot, so checking and sending use identical bytes. */
    public static function verified_document(string $key, string $sha256, int $bytes) {
        if (!preg_match('/^[a-f0-9]{64}$/D', $sha256) || $bytes < 1 || $bytes > 15 * 1024 * 1024) {
            throw new RuntimeException('Das Dokument konnte nicht auf Unverändertheit geprüft werden. Bitte wenden Sie sich an die Verwaltung.');
        }
        $source = @fopen(self::path($key), 'rb');
        $snapshot = fopen('php://temp/maxmemory:2097152', 'w+b');
        try {
            if (!$source || !$snapshot) { throw new RuntimeException('Das Dokument ist nicht verfügbar.'); }
            $copied = stream_copy_to_stream($source, $snapshot, $bytes + 1);
            rewind($snapshot);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $snapshot);
            if ($copied !== $bytes || !hash_equals($sha256, hash_final($hash))) {
                throw new RuntimeException('Die Integritätsprüfung dieses Dokuments ist fehlgeschlagen. Der Abruf wurde gesperrt. Bitte wenden Sie sich an die Verwaltung.');
            }
            rewind($snapshot);
            return $snapshot;
        } catch (Throwable $e) {
            if (is_resource($snapshot)) { fclose($snapshot); }
            throw $e;
        } finally { if (is_resource($source)) { fclose($source); } }
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
