<?php
defined('ABSPATH') || exit;
$current_rent = BPM_Portal::current_rent((int) $h->id);
$rent_history = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE household_id=%d ORDER BY effective_from DESC LIMIT 100', BPM_Portal::table('rent_terms'), $h->id));
?>
<section class="bpm-card">
    <h2><?php echo esc_html__('Vertragliche Mietbeträge', 'openmieterportal'); ?></h2>
    <?php if ($current_rent) :
        $amounts = BPM_Rent_Terms::amounts($current_rent); ?>
        <p><strong><?php echo esc_html__( 'Aktuell seit', 'openmieterportal' ); ?> <?php echo  esc_html(mysql2date('d.m.Y', $current_rent->effective_from)) ?></strong></p>
        <dl class="bpm-data bpm-rent-data">
            <?php foreach ($amounts as $label => $cents) : ?>
                <dt><?php echo  esc_html($label) ?></dt><dd><?php echo  esc_html(BPM_Portal::euros($cents)) ?></dd>
            <?php endforeach; ?>
            <dt><?php echo esc_html__('Monatlicher Gesamtbetrag', 'openmieterportal'); ?></dt><dd><?php echo  esc_html(BPM_Portal::euros(array_sum($amounts))) ?></dd>
        </dl>
    <?php endif; ?>
    <form method="post" action="<?php echo  esc_url($endpoint) ?>">
        <?php BPM_Portal::form('rent'); ?>
        <input type="hidden" name="household_id" value="<?php echo  (int) $h->id ?>">
        <label for="bpm-rent-effective"><?php echo esc_html__('Gültig ab', 'openmieterportal'); ?></label><input type="date" id="bpm-rent-effective" name="effective_from" required>
        <label for="bpm-rent-amount"><?php echo esc_html__('Monatliche Nettokaltmiete (€)', 'openmieterportal'); ?></label><input id="bpm-rent-amount" name="rent_amount" inputmode="decimal" required>
        <div class="bpm-columns">
            <?php foreach (BPM_Rent_Terms::COSTS as $key => $label) : ?>
                <div><label for="bpm-rent-<?php echo  esc_attr($key) ?>"><?php echo  esc_html($label) ?> (€)</label>
                <input id="bpm-rent-<?php echo  esc_attr($key) ?><?php echo esc_html__( '" name="', 'openmieterportal' ); ?><?php echo  esc_attr($key . '_amount') ?>" inputmode="decimal"></div>
            <?php endforeach; ?>
        </div>
        <label for="bpm-advance-amount"><?php echo esc_html__('Nebenkostenvorauszahlung gesamt (€)', 'openmieterportal'); ?></label>
        <input id="bpm-advance-amount" name="advance_amount" inputmode="decimal" aria-describedby="bpm-rent-cost-help">
        <p id="bpm-rent-cost-help" class="bpm-small"><?php echo esc_html__('Bei einer Aufteilung bitte Betriebs- und Heizkosten angeben, bei Bedarf jeweils 0,00. Eine leere Garagenmiete behält den bisherigen Betrag bei. Zum Entfernen 0,00 eintragen.', 'openmieterportal'); ?></p>
        <button type="submit"><?php echo esc_html__('Mietbeträge speichern', 'openmieterportal'); ?></button>
    </form>
    <?php if ($rent_history) : ?>
        <h3><?php echo esc_html__('Bisherige und geplante Beträge', 'openmieterportal'); ?></h3>
        <div class="bpm-table-scroll"><table>
            <thead><tr><th><?php echo esc_html__('Gültig ab', 'openmieterportal'); ?></th><th><?php echo esc_html__('Monatliche Mietbestandteile', 'openmieterportal'); ?></th><th><?php echo esc_html__('Gesamt', 'openmieterportal'); ?></th></tr></thead>
            <tbody><?php foreach ($rent_history as $term) :
                $amounts = BPM_Rent_Terms::amounts($term); ?>
                <tr>
                    <td><?php echo  esc_html(mysql2date('d.m.Y', $term->effective_from)) ?><?php echo  $term->effective_from > BPM_Portal::today() ? esc_html__(' (zukünftig)', 'openmieterportal') : '' ?></td>
                    <td><?php foreach ($amounts as $label => $cents) :
                        ?><div><?php echo  esc_html($label . ': ' . BPM_Portal::euros($cents)) ?></div><?php
                        endforeach; ?></td>
                    <td><?php echo  esc_html(BPM_Portal::euros(array_sum($amounts))) ?></td>
                </tr>
                   <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
