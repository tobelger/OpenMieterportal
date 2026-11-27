<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/bootstrap-standalone.php';
$base = sys_get_temp_dir() . '/bpm-storage-test-' . bin2hex(random_bytes(6));
mkdir($base, 0700);
mkdir($base . '/public', 0700);
mkdir($base . '/private', 0700);
define('ABSPATH', $base . '/public/');
define('BPM_PRIVATE_DIR', $base . '/private');
$_SERVER['DOCUMENT_ROOT'] = $base . '/public';
require __DIR__ . '/../includes/class-domain.php';
require __DIR__ . '/../includes/class-storage.php';
$checks = 0;
function expect_failure(callable $fn): void { global $checks; try { $fn(); } catch (RuntimeException $e) { $checks++; return; } throw new Exception('Expected protected-storage rejection'); }
try {
    if (BPM_Storage::root() !== realpath($base . '/private')) { throw new Exception('External storage accepted'); }
    $checks++;
    expect_failure(fn() => BPM_Storage::path('../../etc/passwd'));
    $key = str_repeat('a', 48);
    file_put_contents($base . '/private/' . $key, 'synthetic');
    if (file_get_contents(BPM_Storage::path($key)) !== 'synthetic') { throw new Exception('Own storage read'); }
    $checks++;
    unlink($base . '/private/' . $key);
    file_put_contents($base . '/outside', 'synthetic outside');
    symlink($base . '/outside', $base . '/private/' . $key);
    expect_failure(fn() => BPM_Storage::path($key));
    unlink($base . '/private/' . $key);
    rmdir($base . '/private');
    symlink($base . '/public', $base . '/private');
    expect_failure(fn() => BPM_Storage::root());
    unlink($base . '/private');
    echo "$checks protected-storage checks passed\n";
} finally {
    @unlink($base . '/outside');
    @unlink($base . '/private/' . str_repeat('a', 48));
    if (is_link($base . '/private')) { unlink($base . '/private'); } else { @rmdir($base . '/private'); }
    @rmdir($base . '/public');
    @rmdir($base);
}
