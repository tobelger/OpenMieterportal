<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require getenv('BPM_TEST_WP_LOAD') ?: '/tmp/belger-portal-runtime/wordpress/wp-load.php';
if (!str_starts_with(home_url(), 'http://127.0.0.1:') || !class_exists(BPM_Rent_Terms::class)) {
    throw new RuntimeException('Isolierte WordPress-Testinstallation erforderlich.');
}

$checks = 0;
function rent_check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function rent_reject(callable $action, string $error_type): void {
    try { $action(); }
    catch (Throwable $error) {
        rent_check($error instanceof $error_type, $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $error_type);
}

$admin_id = (int) get_users(['role' => 'administrator', 'number' => 1])[0]->ID;
wp_set_current_user($admin_id);
$tag = 'RENTTEST-' . bin2hex(random_bytes(4));
$ids = [];
try {
    foreach (['A', 'B'] as $suffix) {
        BPM_Portal::insert('households', [
            'reference' => $tag . $suffix, 'label' => 'Testmietpartei', 'unit_label' => 'Testwohnung',
            'status' => 'invited', 'tenancy_start' => '2024-07-01', 'created_at' => BPM_Portal::now(),
        ]);
        $id = (int) $wpdb->insert_id;
        $ids[] = $id;
        BPM_Rent_Terms::save((object) ['id' => $id], [
            'effective_from' => '2025-01-01', 'rent_amount' => '1012,50', 'advance_amount' => '220,00',
        ]);
    }
    [$a, $b] = $ids;
    $legacy = BPM_Portal::current_rent($a);
    rent_check(BPM_Rent_Terms::amounts($legacy) === ['Nettokaltmiete' => 101250, 'Nebenkostenvorauszahlung' => 22000], 'Legacy total');
    $split = ['effective_from' => '2025-01-01', 'rent_amount' => '1012,50',
        'advance_amount' => '220,00', 'operating_amount' => '160,00', 'heating_amount' => '60,00',
        'garage_amount' => '25,00'];
    BPM_Rent_Terms::save((object) ['id' => $a], $split);
    $current = BPM_Portal::current_rent($a);
    rent_check($current->id === $legacy->id && array_sum(BPM_Rent_Terms::amounts($current)) === 125750, 'Detailed total and ID retained');
    rent_check((int) $current->advance_cents === 22000 && (int) $current->operating_cents === 16000 && (int) $current->heating_cents === 6000, 'Components and conserved aggregate');
    rent_check(!isset(BPM_Portal::current_rent($b)->operating_cents), 'Other contract retains legacy terms');
    $h = (object) ['id' => $a];
    ob_start();
    require __DIR__ . '/../includes/view-rent-tenant.php';
    $html = ob_get_clean();
    rent_check(str_contains($html, 'Betriebskostenvorauszahlung (BKV)') && str_contains($html, 'Heizkostenvorauszahlung (HK)'), 'Tenant sees both advances');
    rent_check(str_contains($html, 'Garagenmiete') && str_contains($html, '1.257,50 €'), 'Tenant sees garage and same total as WISO');

    $different = array_replace($split, ['advance_amount' => '221']);
    rent_reject(fn() => BPM_Rent_Terms::save((object) ['id' => $a], $different), InvalidArgumentException::class);
    $partial = array_replace($split, ['heating_amount' => '']);
    rent_reject(fn() => BPM_Rent_Terms::save((object) ['id' => $a], $partial), InvalidArgumentException::class);
    $changed_aggregate = ['effective_from' => '2025-01-01', 'rent_amount' => '1012,50', 'advance_amount' => '219'];
    rent_reject(fn() => BPM_Rent_Terms::save((object) ['id' => $a], $changed_aggregate), InvalidArgumentException::class);
    rent_check((int) BPM_Portal::current_rent($a)->advance_cents === 22000, 'Invalid update rolled back');

    BPM_Rent_Terms::save((object) ['id' => $a], array_replace($split, ['garage_amount' => '']));
    rent_check((int) BPM_Portal::current_rent($a)->garage_cents === 2500, 'Empty garage preserves existing value');
    BPM_Rent_Terms::save((object) ['id' => $a], array_replace($split, ['garage_amount' => '0']));
    rent_check(!isset(BPM_Rent_Terms::amounts(BPM_Portal::current_rent($a))['Garagenmiete']), 'Zero removes optional garage display');

    BPM_Rent_Terms::save((object) ['id' => $a], ['effective_from' => '2025-01-01', 'rent_amount' => '1012,50', 'advance_amount' => '220']);
    rent_check((int) BPM_Portal::current_rent($a)->heating_cents === 6000, 'Legacy caller preserves split');
    BPM_Rent_Terms::save((object) ['id' => $a], array_replace($split, [
        'effective_from' => '2030-01-01', 'advance_amount' => '', 'operating_amount' => '0', 'heating_amount' => '65',
    ]));
    $future = BPM_Portal::next_rent($a);
    rent_check((int) $future->advance_cents === 6500 && (int) $future->operating_cents === 0, 'New future terms derive aggregate and allow zero');
    rent_check((int) BPM_Portal::current_rent($a)->advance_cents === 22000, 'Future terms preserve current terms');
    echo $checks . " WISO rent checks passed\n";
} finally {
    wp_set_current_user($admin_id);
    foreach ($ids as $id) {
        foreach (['rent_terms', 'events'] as $table) {
            $wpdb->delete(BPM_Portal::table($table), ['household_id' => $id]);
        }
        $wpdb->delete(BPM_Portal::table('households'), ['id' => $id]);
    }
}
