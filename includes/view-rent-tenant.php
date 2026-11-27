<?php
defined('ABSPATH') || exit;
$profile_rent = BPM_Portal::current_rent((int) $h->id);
$future_rent = BPM_Portal::next_rent((int) $h->id);
if ($profile_rent):
    $amounts = BPM_Rent_Terms::amounts($profile_rent);
    foreach ($amounts as $label => $cents): ?>
        <dt><?= esc_html($label) ?></dt><dd><?= esc_html(BPM_Portal::euros($cents)) ?> monatlich</dd>
    <?php endforeach; ?>
    <dt>Gültig seit</dt><dd><?= esc_html(mysql2date('d.m.Y', $profile_rent->effective_from)) ?></dd>
    <dt>Monatlicher Gesamtbetrag</dt><dd><?= esc_html(BPM_Portal::euros(array_sum($amounts))) ?></dd>
<?php else: ?>
    <dt>Mietbeträge</dt><dd>Noch kein aktuell gültiger Stand hinterlegt</dd>
<?php endif;
if ($future_rent): ?>
    <dt>Geplante Beträge ab <?= esc_html(mysql2date('d.m.Y', $future_rent->effective_from)) ?></dt>
    <dd><?php foreach (BPM_Rent_Terms::amounts($future_rent) as $label => $cents): ?>
        <div><?= esc_html($label . ': ' . BPM_Portal::euros($cents)) ?></div>
    <?php endforeach; ?></dd>
<?php endif; ?>
