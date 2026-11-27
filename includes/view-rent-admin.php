<?php
defined('ABSPATH') || exit;
$current_rent = BPM_Portal::current_rent((int) $h->id);
$rent_history = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . BPM_Portal::table('rent_terms') . ' WHERE household_id=%d ORDER BY effective_from DESC LIMIT 100', $h->id));
?>
<section class="bpm-card">
    <h2>Vertragliche Mietbeträge</h2>
    <?php if ($current_rent): $amounts = BPM_Rent_Terms::amounts($current_rent); ?>
        <p><strong>Aktuell seit <?= esc_html(mysql2date('d.m.Y', $current_rent->effective_from)) ?></strong></p>
        <dl class="bpm-data bpm-rent-data">
            <?php foreach ($amounts as $label => $cents): ?>
                <dt><?= esc_html($label) ?></dt><dd><?= esc_html(BPM_Portal::euros($cents)) ?></dd>
            <?php endforeach; ?>
            <dt>Monatlicher Gesamtbetrag</dt><dd><?= esc_html(BPM_Portal::euros(array_sum($amounts))) ?></dd>
        </dl>
    <?php endif; ?>
    <form method="post" action="<?= esc_url($endpoint) ?>">
        <?php BPM_Portal::form('rent'); ?>
        <input type="hidden" name="household_id" value="<?= (int) $h->id ?>">
        <label for="bpm-rent-effective">Gültig ab</label><input type="date" id="bpm-rent-effective" name="effective_from" required>
        <label for="bpm-rent-amount">Monatliche Nettokaltmiete (€)</label><input id="bpm-rent-amount" name="rent_amount" inputmode="decimal" required>
        <div class="bpm-columns">
            <?php foreach (BPM_Rent_Terms::COSTS as $key => $label): ?>
                <div><label for="bpm-rent-<?= esc_attr($key) ?>"><?= esc_html($label) ?> (€)</label>
                <input id="bpm-rent-<?= esc_attr($key) ?>" name="<?= esc_attr($key . '_amount') ?>" inputmode="decimal"></div>
            <?php endforeach; ?>
        </div>
        <label for="bpm-advance-amount">Nebenkostenvorauszahlung gesamt (€)</label>
        <input id="bpm-advance-amount" name="advance_amount" inputmode="decimal" aria-describedby="bpm-rent-cost-help">
        <p id="bpm-rent-cost-help" class="bpm-small">Bei einer Aufteilung bitte Betriebs- und Heizkosten angeben, bei Bedarf jeweils 0,00. Eine leere Garagenmiete behält den bisherigen Betrag bei. Zum Entfernen 0,00 eintragen.</p>
        <button type="submit">Mietbeträge speichern</button>
    </form>
    <?php if ($rent_history): ?>
        <h3>Bisherige und geplante Beträge</h3>
        <div class="bpm-table-scroll"><table>
            <thead><tr><th>Gültig ab</th><th>Monatliche Mietbestandteile</th><th>Gesamt</th></tr></thead>
            <tbody><?php foreach ($rent_history as $term): $amounts = BPM_Rent_Terms::amounts($term); ?>
                <tr>
                    <td><?= esc_html(mysql2date('d.m.Y', $term->effective_from)) ?><?= $term->effective_from > BPM_Portal::today() ? ' (zukünftig)' : '' ?></td>
                    <td><?php foreach ($amounts as $label => $cents): ?><div><?= esc_html($label . ': ' . BPM_Portal::euros($cents)) ?></div><?php endforeach; ?></td>
                    <td><?= esc_html(BPM_Portal::euros(array_sum($amounts))) ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
