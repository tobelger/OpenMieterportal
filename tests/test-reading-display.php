<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/bootstrap-standalone.php';
define('ABSPATH', __DIR__);
require __DIR__ . '/../includes/class-domain.php';
$cases = ['0'=>'0,000', '0.03'=>'0,030', '98.006'=>'98,006', '1234.567'=>'1.234,567', '9999999999.999'=>'9.999.999.999,999'];
foreach ($cases as $raw=>$expected) { if (BPM_Domain::reading_display((string)$raw)!==$expected) { throw new RuntimeException('Zahlenformat falsch'); } }
echo count($cases) . " reading display checks passed\n";
