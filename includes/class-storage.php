<?php

defined('ABSPATH') || exit;

/** No attachment records or public upload URLs are created. */
final class BPM_Storage
{
    public static function root(): string
    {
        if (!defined('BPM_PRIVATE_DIR') || !is_string(BPM_PRIVATE_DIR)) {
            throw new RuntimeException(esc_html__('Der geschützte Dateispeicher wurde noch nicht eingerichtet.', 'openmieterportal'));
        }
        $root = realpath(BPM_PRIVATE_DIR);
        if (!$root || !is_dir($root) || !wp_is_writable($root)) {
            throw new RuntimeException(esc_html__('Der geschützte Dateispeicher ist nicht verfügbar.', 'openmieterportal'));
        }
        $public_roots = [realpath(ABSPATH)];
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            // Server configuration, canonicalized by realpath and checked against the private path; not an HTML/text field.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $public_roots[] = realpath(wp_unslash($_SERVER['DOCUMENT_ROOT']));
        }
        if (defined('BPM_PUBLIC_ROOT')) {
            $public_roots[] = realpath(BPM_PUBLIC_ROOT);
        }
        foreach (array_filter($public_roots) as $public) {
            if (BPM_Domain::within($root, $public)) {
                throw new RuntimeException(esc_html__('Der Dateispeicher muss außerhalb des öffentlich erreichbaren Webverzeichnisses liegen.', 'openmieterportal'));
            }
        }
        return $root;
    }

    public static function upload(array $file, bool $pdf): array
    {
        $root = self::root();
        if (get_option('bpm_files_encryption_required', false)) {
            BPM_Crypto::ring();
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new InvalidArgumentException(esc_html__('Die Datei konnte nicht hochgeladen werden.', 'openmieterportal'));
        }
        $tmp = $file['tmp_name'];
        $size = filesize($tmp);
        if ($size === false || $size < 1 || $size > ($pdf ? 15 : 8) * 1024 * 1024) {
            throw new InvalidArgumentException(esc_html__('Die Datei ist leer oder zu groß (PDF: 15 MB, Foto: 8 MB).', 'openmieterportal'));
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $key = bin2hex(random_bytes(24));
        $target = $root . '/' . $key;
        if ($pdf) {
            if ($mime !== 'application/pdf' || file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') {
                throw new InvalidArgumentException(esc_html__('Bitte eine gültige PDF-Datei auswählen.', 'openmieterportal'));
            }
            $plain = file_get_contents($tmp);
            $name = sanitize_file_name($file['name']);
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
                $name .= '.pdf';
            }
        } else {
            if (!in_array($mime, ['image/jpeg','image/png'], true) || !extension_loaded('gd')) {
                throw new InvalidArgumentException(esc_html__('Bitte ein JPG- oder PNG-Foto auswählen.', 'openmieterportal'));
            }
            $info = @getimagesize($tmp);
            if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 12000000) {
                throw new InvalidArgumentException(esc_html__('Das Foto darf höchstens 12 Megapixel groß sein.', 'openmieterportal'));
            }
            $image = $mime === 'image/jpeg' ? @imagecreatefromjpeg($tmp) : @imagecreatefrompng($tmp);
            if (!$image) {
                throw new InvalidArgumentException(esc_html__('Das Foto ist nicht lesbar.', 'openmieterportal'));
            }
            imagealphablending($image, true);
            ob_start();
            try {
                $ok = imagejpeg($image, null, 88);
                $plain = ob_get_contents();
            } finally {
                ob_end_clean();
                imagedestroy($image);
            }
            if (!$ok) {
                throw new RuntimeException(esc_html__('Das Foto konnte nicht gespeichert werden.', 'openmieterportal'));
            }$mime = 'image/jpeg';
            $name = 'zaehlerfoto.jpg';
        }
        if (!is_string($plain) || $plain === '') {
            throw new RuntimeException(esc_html__('Die Datei konnte nicht vollständig gelesen werden.', 'openmieterportal'));
        }
        $bytes = strlen($plain);
        $hash = hash('sha256', $plain);
        $stored = BPM_Crypto::configured() ? BPM_Crypto::seal($plain, 'file/' . $key) : $plain;
        // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $output = @fopen($target, 'xb');
        if (!$output) {
            throw new RuntimeException(esc_html__('Die Datei konnte nicht sicher gespeichert werden.', 'openmieterportal'));
        }
        try {
            // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            if (!chmod($target, $pdf ? 0400 : 0600) || fwrite($output, $stored) !== strlen($stored)) {
                throw new RuntimeException(esc_html__('Die geschützte Datei konnte nicht vollständig gespeichert werden.', 'openmieterportal'));
            }
        } catch (Throwable $e) {
            wp_delete_file($target);
            throw $e;
        } finally {
            // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($output);
        }
        return ['storage_key' => $key,'filename' => $name,'mime' => $mime,'bytes' => $bytes,'sha256' => $hash];
    }
    public static function plaintext(string $key, bool $migration = false): string
    {
        $path = self::path($key);
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > 32 * 1024 * 1024) {
            throw new RuntimeException(esc_html__('Die Datei hat eine ungültige Größe.', 'openmieterportal'));
        }
        $stored = file_get_contents($path);
        if (BPM_Crypto::sealed($stored)) {
            return BPM_Crypto::open($stored, 'file/' . $key);
        }
        if (!$migration && get_option('bpm_files_encryption_required', false)) {
            throw new RuntimeException(esc_html__('Eine unverschlüsselte Datei wurde blockiert.', 'openmieterportal'));
        }
        return $stored;
    }
    /** Plaintext snapshots stay in memory and are returned only after access checks. */
    public static function verified_document(string $key, string $sha256, int $bytes)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $sha256) || $bytes < 1 || $bytes > 15 * 1024 * 1024) {
            throw new RuntimeException(esc_html__('Das Dokument konnte nicht geprüft werden.', 'openmieterportal'));
        }
        $plain = self::plaintext($key);
        if (strlen($plain) !== $bytes || !hash_equals($sha256, hash('sha256', $plain))) {
            throw new RuntimeException(esc_html__('Die Integritätsprüfung dieses Dokuments ist fehlgeschlagen.', 'openmieterportal'));
        }
        // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $snapshot = fopen('php://memory', 'w+b');
        // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        if (!$snapshot || fwrite($snapshot, $plain) !== $bytes) {
            if (is_resource($snapshot)) {
                // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($snapshot);
            }throw new RuntimeException(esc_html__('Das Dokument konnte nicht bereitgestellt werden.', 'openmieterportal'));
        }rewind($snapshot);
        return $snapshot;
    }
    public static function migrate_files(): int
    {
        global $wpdb;
        BPM_Crypto::ring();
        $root = self::root();
        $count = 0;
        foreach (glob($root . '/*') as $path) {
            $key = basename($path);
            if (!preg_match('/^[a-f0-9]{48}$/D', $key) || !is_file($path)) {
                continue;
            }
            $plain = self::plaintext($key, true);
            $doc = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE storage_key=%s', BPM_Portal::table('documents'), $key));
            if ($doc && (strlen($plain) !== (int)$doc->bytes || !hash_equals($doc->sha256, hash('sha256', $plain)))) {
                throw new RuntimeException(esc_html__('Eine Bestandsdatei hat die Integritätsprüfung nicht bestanden.', 'openmieterportal'));
            }
            $stored = BPM_Crypto::seal($plain, 'file/' . $key);
            if (!hash_equals($plain, BPM_Crypto::open($stored, 'file/' . $key))) {
                throw new RuntimeException(esc_html__('Die Dateimigration konnte nicht verifiziert werden.', 'openmieterportal'));
            }
            $tmp = $root . '/.migration-' . bin2hex(random_bytes(16));
            // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            $out = fopen($tmp, 'xb');
            if (!$out) {
                throw new RuntimeException(esc_html__('Die Dateimigration konnte nicht vorbereitet werden.', 'openmieterportal'));
            }
            try {
                // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
                if (!chmod($tmp, $doc ? 0400 : 0600) || fwrite($out, $stored) !== strlen($stored)) {
                    throw new RuntimeException(esc_html__('Die Dateimigration konnte nicht gespeichert werden.', 'openmieterportal'));
                }
            } catch (Throwable $e) {
                wp_delete_file($tmp);
                throw $e;
            } finally {
                // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($out);
            }
            // Private local storage requires exclusive creation, restrictive modes and atomic replacement; streams stay in memory.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            if (!rename($tmp, $path)) {
                wp_delete_file($tmp);
                throw new RuntimeException(esc_html__('Die Dateimigration konnte nicht abgeschlossen werden.', 'openmieterportal'));
            }$count++;
        }
        update_option('bpm_files_encryption_required', true, false);
        return $count;
    }

    public static function path(string $key): string
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $key)) {
            throw new RuntimeException(esc_html__('Ungültiger Dateischlüssel.', 'openmieterportal'));
        }
        $root = self::root();
        $path = realpath($root . '/' . $key);
        if (!$path || is_link($root . '/' . $key) || !BPM_Domain::within($path, $root) || !is_file($path)) {
            throw new RuntimeException(esc_html__('Die Datei ist nicht verfügbar.', 'openmieterportal'));
        }
        return $path;
    }
}
