<?php
// Pure-PHP checks do not load WordPress; mirror translation calls only.
if (!function_exists('__')) { function __($text, $domain = '') { return $text; } }
if (!function_exists('esc_html__')) { function esc_html__($text, $domain = '') { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr__')) { function esc_attr__($text, $domain = '') { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); } }

if (!function_exists('wp_delete_file')) { function wp_delete_file($file) { if (is_file($file)) { unlink($file); } } }

if (!function_exists('wp_is_writable')) { function wp_is_writable($path) { return is_writable($path); } }

if (!function_exists('wp_unslash')) { function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); } }
