<?php

defined('ABSPATH') || exit;

/** Apartment and meter administration, independent of a tenancy/account. */
final class BPM_Units_Admin
{
    public static function boot(): void
    {
        add_action('admin_menu', static function () {
            add_submenu_page('belger-mieterportal', __('Wohnungen und Zähler', 'openmieterportal'), __('Wohnungen', 'openmieterportal'), 'bpm_manage', 'bpm-units', [self::class, 'render']);
        }, 15);
        add_action('admin_enqueue_scripts', static function ($hook) {
            if (str_ends_with($hook, '_page_bpm-units')) {
                wp_enqueue_style('bpm-admin', plugins_url('assets/portal.css', BPM_FILE), [], BPM_VERSION);
            }
        });
    }
    public static function url(int $unit = 0): string
    {
        return add_query_arg(array_filter(['page' => 'bpm-units', 'unit_id' => $unit]), admin_url('admin.php'));
    }
    private static function require_manager(): void
    {
        if (!BPM_Portal::manager()) {
            throw new RuntimeException(esc_html__('Keine Berechtigung.', 'openmieterportal'));
        }
    }
    private static function text(array $data, string $key, int $max): string
    {
        $text = sanitize_text_field((string)($data[$key] ?? ''));
        if ($text === '' || mb_strlen($text) > $max) {
            throw new InvalidArgumentException(esc_html__('Bitte alle Pflichtfelder in der erlaubten Länge ausfüllen.', 'openmieterportal'));
        }
        return $text;
    }
    public static function save_unit(int $id, array $data): void
    {
        self::require_manager();
        global $wpdb;
        $u = BPM_Portal::unit($id);
        $label = self::text($data, 'unit_name', 190);
        $reference = self::text($data, 'unit_reference', 100);
        if (mb_strlen($u->address . ' · ' . $label) > 190) {
            throw new InvalidArgumentException(esc_html__('Die Wohnungsbezeichnung ist zusammen mit der Anschrift zu lang.', 'openmieterportal'));
        }
        if ($wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE reference=%s AND id<>%d', BPM_Portal::table('units'), $reference, $id))) {
            throw new InvalidArgumentException(esc_html__('Diese Wohnungsnummer ist bereits vergeben.', 'openmieterportal'));
        }
        $wpdb->query('START TRANSACTION');
        try {
            if (
                BPM_Data::update(BPM_Portal::table('units'), ['label' => $label,'reference' => $reference], ['id' => $id]) === false ||
                BPM_Data::update(BPM_Portal::table('households'), ['unit_label' => $u->address . ' · ' . $label], ['unit_id' => $id]) === false
            ) {
                throw new RuntimeException(esc_html__('Die Wohnung konnte nicht gespeichert werden.', 'openmieterportal'));
            }
            BPM_Portal::event('unit_updated', 0, $id);
            if ($wpdb->last_error) {
                throw new RuntimeException(esc_html__('Die Wohnung konnte nicht gespeichert werden.', 'openmieterportal'));
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
    public static function save_meter(int $unit_id, array $data): int
    {
        return BPM_Meters::legacy_save($unit_id, $data);
    }
    public static function assign_meter(int $unit_id, int $meter_id): void
    {
        BPM_Meters::legacy_assign($unit_id, $meter_id);
    }
    public static function action(string $operation): void
    {
        self::require_manager();
        check_admin_referer('bpm_' . $operation);
        $id = absint($_POST['unit_id'] ?? 0);
        $data = wp_unslash($_POST);
        if ($operation === 'unit_update') {
            self::save_unit($id, $data);
        } elseif ($operation === 'unit_meter_save') {
            self::save_meter($id, $data);
        } elseif ($operation === 'unit_meter_assign') {
            self::assign_meter($id, absint($data['meter_id'] ?? 0));
        } elseif ($operation === 'point_save') {
            BPM_Meters::save_point($id, absint($data['point_id'] ?? 0), $data);
        } elseif ($operation === 'device_save') {
            BPM_Meters::save_device($id, absint($data['point_id'] ?? 0), $data);
        } elseif ($operation === 'device_exchange') {
            BPM_Meters::exchange($id, absint($data['point_id'] ?? 0), $data);
        } else {
            throw new InvalidArgumentException(esc_html__('Unbekannte Aktion.', 'openmieterportal'));
        }
        wp_safe_redirect(add_query_arg('bpm_done', 1, self::url($id)));
        exit;
    }
    public static function render(): void
    {
        if (!BPM_Portal::manager()) {
            wp_die(esc_html__('Keine Berechtigung.', 'openmieterportal'));
        }
        global $wpdb;
        $id = absint($_GET['unit_id'] ?? 0);
        ?><div class="wrap bpm-admin bpm-portal"><h1><?php echo esc_html__('Wohnungen und Zähler', 'openmieterportal'); ?></h1><?php if (isset($_GET['bpm_done'])) :
    ?><div class="notice notice-success"><p><?php echo esc_html__('Die Angaben wurden gespeichert.', 'openmieterportal'); ?></p></div><?php
        endif; ?><?php
if (isset($_GET['bpm_migration_report'])) {
    $report = get_option('bpm_meter_points_migration_report');
    if ($report) {
        echo '<div class="notice notice-success"><p>' . esc_html__('Übernahme geprüft:', 'openmieterportal') . ' ' . (int)$report['devices'] . esc_html__(' Geräte und ', 'openmieterportal') . (int)$report['readings'] . (' ' . esc_html__('Ablesungen einschließlich Testdaten unverändert erhalten.', 'openmieterportal') . '</p></div>');
    }
}
if ($id) {
    try {
        $u = BPM_Portal::unit($id);
    } catch (InvalidArgumentException $e) {
        wp_die(esc_html($e->getMessage()), '', ['response' => 404]);
    }
    $meters = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE unit_id=%d ORDER BY label,number', BPM_Portal::table('meters'), $id));
    ?><p><a href="<?php echo  esc_url(self::url()) ?>"><?php echo esc_html__('← Alle Wohnungen', 'openmieterportal'); ?></a> · <a href="<?php echo  esc_url(add_query_arg(['page' => 'belger-mieterportal','bpm_unit' => $id], admin_url('admin.php'))) ?>"><?php echo esc_html__('Mietverhältnisse dieser Wohnung anzeigen', 'openmieterportal'); ?></a></p>
            <section class="bpm-card"><h2><?php echo esc_html__('Wohnung bearbeiten', 'openmieterportal'); ?></h2><p><strong><?php echo  esc_html($u->building_label) ?></strong><br><?php echo  esc_html($u->address) ?></p>
            <form method="post" action="<?php echo  esc_url(admin_url('admin-post.php')) ?>"><?php BPM_Portal::form('unit_update'); ?><input type="hidden" name="unit_id" value="<?php echo (int) $id ?>"><label><?php echo esc_html__('Wohnungsnummer', 'openmieterportal'); ?><input name="unit_reference" maxlength="100" required value="<?php echo  esc_attr($u->reference) ?>"></label><label><?php echo esc_html__('Wohnungsbezeichnung', 'openmieterportal'); ?><input name="unit_name" maxlength="190" required value="<?php echo  esc_attr($u->label) ?>"></label><button type="submit"><?php echo esc_html__('Wohnung speichern', 'openmieterportal'); ?></button></form></section>
            <?php require __DIR__ . '/view-meter-points-admin.php'; ?>
            <?php $unassigned = BPM_Data::rows($wpdb->prepare('SELECT m.* FROM %i m WHERE (m.unit_id IS NULL OR m.unit_id=0) AND m.household_id=0 AND NOT EXISTS (SELECT 1 FROM %i r WHERE r.meter_id=m.id) ORDER BY m.label,m.number', BPM_Portal::table('meters'), BPM_Portal::table('readings'))); if ($unassigned) : ?>
            <section class="bpm-card"><h2><?php echo esc_html__('Vorhandenen freien Zähler zuordnen', 'openmieterportal'); ?></h2><form method="post" action="<?php echo  esc_url(admin_url('admin-post.php')) ?>"><?php BPM_Portal::form('unit_meter_assign'); ?><input type="hidden" name="unit_id" value="<?php echo (int) $id ?>"><label><?php echo esc_html__('Zähler', 'openmieterportal'); ?><select name="meter_id" required><?php foreach ($unassigned as $m) :
                ?><option value="<?php echo  (int)$m->id ?>"><?php echo  esc_html($m->label . ' · ' . $m->number) ?></option><?php
                                          endforeach; ?></select></label><button type="submit"><?php echo esc_html__('Zähler zuordnen', 'openmieterportal'); ?></button></form></section><?php
            endif;
} else {
    $bid = absint($_GET['building_id'] ?? 0);
    $buildings = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i ORDER BY address', BPM_Portal::table('buildings')));
    ?><form method="get" action="<?php echo  esc_url(admin_url('admin.php')) ?>"><input type="hidden" name="page" value="bpm-units"><label><?php echo esc_html__('Gebäude', 'openmieterportal'); ?><select name="building_id"><option value="0"><?php echo esc_html__('Alle Gebäude', 'openmieterportal'); ?></option><?php foreach ($buildings as $b) :
    ?><option value="<?php echo  (int)$b->id ?>" <?php echo  selected($bid, $b->id, false) ?>><?php echo  esc_html($b->label) ?></option><?php
    endforeach; ?></select></label><button><?php echo esc_html__('Wohnungen anzeigen', 'openmieterportal'); ?></button></form>
            <?php $sql = 'SELECT u.*,b.label AS building_label,b.address,(SELECT COUNT(*) FROM ' . BPM_Portal::table('meter_points') . ' p WHERE p.unit_id=u.id) AS point_count,(SELECT COUNT(*) FROM ' . BPM_Portal::table('meters') . ' m WHERE m.unit_id=u.id) AS meter_count FROM ' . BPM_Portal::table('units') . ' u JOIN ' . BPM_Portal::table('buildings') . ' b ON b.id=u.building_id';
            if ($bid) {
                $sql .= $wpdb->prepare(' WHERE b.id=%d', $bid);
            }$units = BPM_Data::rows($sql . ' ORDER BY b.address,u.label,u.id', OBJECT, BPM_Portal::table('units')); // Explicit outer table: SELECT contains count subqueries.
            ?>
            <div class="bpm-table-scroll"><table><thead><tr><th><?php echo esc_html__('Gebäude', 'openmieterportal'); ?></th><th><?php echo esc_html__('Wohnung', 'openmieterportal'); ?></th><th><?php echo esc_html__('Wohnungsnummer', 'openmieterportal'); ?></th><th><?php echo esc_html__('Messstellen', 'openmieterportal'); ?></th><th><?php echo esc_html__('Geräte', 'openmieterportal'); ?></th></tr></thead><tbody><?php foreach ($units as $u) :
                ?><tr><td><?php echo  esc_html($u->building_label) ?></td><td><a href="<?php echo  esc_url(self::url((int)$u->id)) ?>"><?php echo  esc_html($u->label) ?> <?php echo esc_html__( '– bearbeiten', 'openmieterportal' ); ?></a></td><td><?php echo  esc_html($u->reference) ?></td><td><?php echo  (int)$u->point_count ?></td><td><?php echo  (int)$u->meter_count ?></td></tr><?php
                                                                endforeach; ?></tbody></table></div><?php
}
        echo '</div>';
    }
}
