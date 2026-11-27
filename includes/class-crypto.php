<?php

defined('ABSPATH') || exit;

/** Versioned authenticated encryption. The key ring never lives in WordPress options. */
final class BPM_Crypto
{
    public static function configured(): bool
    {
        return defined('BPM_KEY_FILE') && is_string(BPM_KEY_FILE) && BPM_KEY_FILE !== '';
    }
    public static function ring(): array
    {
        if (!self::configured()) {
            throw new RuntimeException(esc_html__('Der separate Verschlüsselungsschlüssel ist nicht eingerichtet.', 'openmieterportal'));
        }
        $path = realpath(BPM_KEY_FILE);
        if (!$path || is_link(BPM_KEY_FILE) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException(esc_html__('Der Verschlüsselungsschlüssel ist nicht verfügbar.', 'openmieterportal'));
        }
        // Server configuration, canonicalized by realpath and checked against the private path; not an HTML/text field.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        foreach (array_filter([realpath(ABSPATH),(!empty($_SERVER['DOCUMENT_ROOT']) ? realpath(wp_unslash($_SERVER['DOCUMENT_ROOT'])) : false),defined('BPM_PUBLIC_ROOT') ? realpath(BPM_PUBLIC_ROOT) : false]) as $public) {
            if (BPM_Domain::within($path, $public)) {
                throw new RuntimeException(esc_html__('Die Schlüsseldatei muss außerhalb der öffentlichen Webverzeichnisse liegen.', 'openmieterportal'));
            }
        }
        if ((fileperms($path) & 0077) !== 0) {
            throw new RuntimeException(esc_html__('Die Schlüsseldatei darf nur für ihren Eigentümer lesbar sein.', 'openmieterportal'));
        }
        $ring = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        $active = $ring['active'] ?? '';
        if (!preg_match('/^[a-f0-9]{16}$/D', $active) || !isset($ring['keys'][$active])) {
            throw new RuntimeException(esc_html__('Die Schlüsseldatei ist ungültig.', 'openmieterportal'));
        }
        foreach ($ring['keys'] as $id => $encoded) {
            if (!preg_match('/^[a-f0-9]{16}$/D', $id) || strlen(base64_decode($encoded, true) ?: '') !== 32) {
                throw new RuntimeException(esc_html__('Die Schlüsseldatei ist ungültig.', 'openmieterportal'));
            }
        }
        return $ring;
    }
    public static function key_id(): string
    {
        return self::ring()['active'];
    }
    public static function sealed(string $value): bool
    {
        return str_starts_with($value, 'OMP2.');
    }
    public static function seal(string $plain, string $scope): string
    {
        $ring = self::ring();
        $id = $ring['active'];
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', base64_decode($ring['keys'][$id], true), OPENSSL_RAW_DATA, $iv, $tag, 'OpenMieterportal/v2/' . $scope, 16);
        if ($cipher === false) {
            throw new RuntimeException(esc_html__('Die Verschlüsselung ist fehlgeschlagen.', 'openmieterportal'));
        }
        return 'OMP2.' . base64_encode(json_encode(['k' => $id,'n' => base64_encode($iv),'t' => base64_encode($tag),'c' => base64_encode($cipher)], JSON_THROW_ON_ERROR));
    }
    public static function open(string $value, string $scope): string
    {
        if (!self::sealed($value)) {
            throw new RuntimeException(esc_html__('Ein verschlüsselter Datensatz wurde erwartet.', 'openmieterportal'));
        }
        $raw = base64_decode(substr($value, 5), true);
        $box = $raw === false ? null : json_decode($raw, true);
        $ring = self::ring();
        $id = $box['k'] ?? '';
        if (!isset($ring['keys'][$id])) {
            throw new RuntimeException(esc_html__('Der benötigte Datenschlüssel ist nicht verfügbar.', 'openmieterportal'));
        }
        $iv = base64_decode($box['n'] ?? '', true);
        $tag = base64_decode($box['t'] ?? '', true);
        $cipher = base64_decode($box['c'] ?? '', true);
        if ($iv === false || strlen($iv) !== 12 || $tag === false || strlen($tag) !== 16 || $cipher === false) {
            throw new RuntimeException(esc_html__('Der verschlüsselte Datensatz ist ungültig.', 'openmieterportal'));
        }
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', base64_decode($ring['keys'][$id], true), OPENSSL_RAW_DATA, $iv, $tag, 'OpenMieterportal/v2/' . $scope);
        if ($plain === false) {
            throw new RuntimeException(esc_html__('Die Echtheitsprüfung der verschlüsselten Daten ist fehlgeschlagen.', 'openmieterportal'));
        }
        return $plain;
    }
    public static function legacy_auth_salt(): string
    {
        return self::configured() ? (self::ring()['legacy_auth_salt'] ?? wp_salt('auth')) : wp_salt('auth');
    }
}
