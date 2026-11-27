<?php
defined('ABSPATH') || exit;
$profile_rent = BPM_Portal::current_rent((int) $h->id);
$future_rent = BPM_Portal::next_rent((int) $h->id);
if ($profile_rent) :
    $amounts = BPM_Rent_Terms::amounts($profile_rent);
    foreach ($amounts as $label => $cents) : ?>
        <dt><?php echo  esc_html($label) ?></dt><dd><?php echo  esc_html(BPM_Portal::euros($cents)) ?> <?php echo esc_html__( 'monatlich', 'openmieterportal' ); ?></dd>
    <?php endforeach; ?>
    <dt><?php echo esc_html__('Gültig seit', 'openmieterportal'); ?></dt><dd><?php echo  esc_html(mysql2date('d.m.Y', $profile_rent->effective_from)) ?></dd>
    <dt><?php echo esc_html__('Monatlicher Gesamtbetrag', 'openmieterportal'); ?></dt><dd><?php echo  esc_html(BPM_Portal::euros(array_sum($amounts))) ?></dd>
<?php else : ?>
    <dt><?php echo esc_html__('Mietbeträge', 'openmieterportal'); ?></dt><dd><?php echo esc_html__('Noch kein aktuell gültiger Stand hinterlegt', 'openmieterportal'); ?></dd>
<?php endif;
if ($future_rent) : ?>
    <dt><?php echo esc_html__( 'Geplante Beträge ab', 'openmieterportal' ); ?> <?php echo  esc_html(mysql2date('d.m.Y', $future_rent->effective_from)) ?></dt>
    <dd><?php foreach (BPM_Rent_Terms::amounts($future_rent) as $label => $cents) : ?>
        <div><?php echo  esc_html($label . ': ' . BPM_Portal::euros($cents)) ?></div>
        <?php endforeach; ?></dd>
<?php endif; ?>
