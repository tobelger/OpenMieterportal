<?php
define('ABSPATH', __DIR__ . '/');
function wp_date($format) { return date($format); }
require __DIR__ . '/../includes/class-domain.php';
$checks = 0;
function check($condition, $message) { global $checks; if (!$condition) { throw new Exception($message); } $checks++; }
function rejects($fn, $message) { try { $fn(); } catch (InvalidArgumentException $e) { check(true, $message); return; } throw new Exception($message); }
check(BPM_Domain::reading('123,456') === '123.456', 'German decimal format');
check(BPM_Domain::reading('0') === '0.000', 'Zero reading is valid');
foreach (['-1', '1e3', '1.234,56', '123.4567', 'NaN', '', '<script>'] as $invalid) { rejects(fn() => BPM_Domain::reading($invalid), 'Invalid reading rejected'); }
check(BPM_Domain::date('2024-02-29') === '2024-02-29', 'Leap date');
rejects(fn() => BPM_Domain::date('2025-02-29'), 'Impossible date rejected');
rejects(fn() => BPM_Domain::date('2099-01-01'), 'Future date rejected');
check(!BPM_Domain::allowed(0, 0), 'Unassigned content is never public');
check(!BPM_Domain::allowed(2, 1), 'Foreign household denied');
check(BPM_Domain::allowed(2, 2), 'Own household allowed');
check(BPM_Domain::allowed(2, 1, true), 'Administrator explicitly allowed');
check(!BPM_Domain::within('/srv/www-private', '/srv/www'), 'Sibling prefix not inside parent');
check(BPM_Domain::within('/srv/www/secret', '/srv/www'), 'Webroot child detected');
$token = BPM_Domain::token();
check(strlen($token) === 64 && ctype_xdigit($token), '256-bit invitation');
check(BPM_Domain::token_hash($token) !== $token, 'Only invitation digest stored');


$invite = BPM_Domain::invitation_token();
check(strlen($invite) === 16 && BPM_Domain::valid_invitation($invite), '16-character invitation');
check(BPM_Domain::normalize_invitation(strtolower($invite)) === $invite, 'Case insensitive invitation');
check(!BPM_Domain::valid_invitation(str_repeat('I', 16)), 'Ambiguous characters excluded');
check(BPM_Domain::valid_invitation(BPM_Domain::token()), 'Legacy invitation supported');



check(BPM_Domain::access_deadline('2026-12-31') === '2029-01-31', '25 calendar months');
check(BPM_Domain::access_deadline('2024-01-31') === '2026-02-28', 'Month end clamps safely');
check(BPM_Domain::access_deadline('2022-01-31') === '2024-02-29', 'Leap year month end');
check(BPM_Domain::access_deadline(null) === null, 'Open tenancy has no invented cutoff');
rejects(fn() => BPM_Domain::tenancy_date('2026-02-30'), 'Invalid tenancy date rejected');
rejects(fn() => BPM_Domain::tenancy_data(['tenancy_start'=>'2026-02-01','tenancy_end'=>'2026-01-01']), 'End before start rejected');
rejects(fn() => BPM_Domain::tenancy_data(['notice_received'=>'2026-01-01','termination_by'=>'mieter']), 'Termination needs end date');
check(BPM_Domain::tenancy_data(['tenancy_start'=>'2024-01-01','tenancy_end'=>'2026-12-31','notice_received'=>'2026-11-21','termination_by'=>'mieter'])['tenancy_end']==='2026-12-31', 'Complete termination accepted');


check(BPM_Domain::money_cents('750,25') === 75025, 'Euro comma exact cents');
check(BPM_Domain::money_cents('200.5') === 20050, 'Euro dot exact cents');
check(BPM_Domain::money_cents('0') === 0, 'Zero advance permitted');
rejects(fn() => BPM_Domain::money_cents('-1'), 'Negative rent rejected');
rejects(fn() => BPM_Domain::money_cents('1,234'), 'Excess precision rejected');
echo "$checks domain checks passed\n";
