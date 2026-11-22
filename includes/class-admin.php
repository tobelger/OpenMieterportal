<?php
defined('ABSPATH') || exit;

final class BPM_Admin {
    public static function boot(): void {
        add_action('admin_menu', static function () { add_menu_page('Mieterportal', 'Mieterportal', 'bpm_manage', 'belger-mieterportal', [self::class, 'render'], 'dashicons-building', 26); add_submenu_page('belger-mieterportal','Mieterportal','Übersicht','bpm_manage','belger-mieterportal',[self::class,'render']); });
        add_action('admin_enqueue_scripts', static function ($hook) {
            if ($hook === 'toplevel_page_belger-mieterportal') { wp_enqueue_style('bpm-admin', plugins_url('assets/portal.css', BPM_FILE), [], BPM_VERSION); }
        });
    }
    private static function household(int $id): object {
        global $wpdb;
        $h = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . BPM_Portal::table('households') . ' WHERE id=%d', $id));
        if (!$h) { throw new InvalidArgumentException('Die Mietpartei wurde nicht gefunden.'); }
        return $h;
    }
    public static function action(string $operation): void {
        global $wpdb;
        if (!BPM_Portal::manager()) { throw new RuntimeException('Keine Berechtigung.'); }
        if (in_array($operation, ['unit_update','unit_meter_save','unit_meter_assign','point_save','device_save','device_exchange'], true)) { BPM_Units_Admin::action($operation); return; }
        $hid = absint($_POST['household_id'] ?? 0);
        if ($operation === 'building') {
            BPM_Portal::insert('buildings', ['reference'=>BPM_Portal::text('building_reference',100), 'label'=>BPM_Portal::text('building_label',190), 'address'=>BPM_Portal::text('address',190)]);
            BPM_Portal::event('building_created', 0, (int)$wpdb->insert_id);
        } elseif ($operation === 'unit') {
            $bid = absint($_POST['building_id'] ?? 0);
            if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . BPM_Portal::table('buildings') . ' WHERE id=%d', $bid))) { throw new InvalidArgumentException('Bitte ein Gebäude auswählen.'); }
            BPM_Portal::insert('units', ['building_id'=>$bid, 'reference'=>BPM_Portal::text('unit_reference',100), 'label'=>BPM_Portal::text('unit_name',190)]);
            BPM_Portal::event('unit_created', 0, (int)$wpdb->insert_id);
        } elseif ($operation === 'household') {
            $email = sanitize_email(wp_unslash((string) ($_POST['expected_email'] ?? '')));
            if (!empty($_POST['expected_email']) && !is_email($email)) { throw new InvalidArgumentException('Bitte eine gültige E-Mail-Adresse angeben.'); }
            $u = BPM_Portal::unit(absint($_POST['unit_id'] ?? 0));
            BPM_Portal::insert('households', ['unit_id'=>$u->id, 'reference' => BPM_Portal::text('reference', 100), 'label' => BPM_Portal::text('label', 190), 'unit_label' => $u->address . ' · ' . $u->label, 'expected_email' => $email, 'status' => 'invited', 'created_at' => BPM_Portal::now()]);
            $hid = (int) $wpdb->insert_id;
            BPM_Portal::event('household_created', $hid);
        } elseif ($operation === 'settings') {
            update_option('bpm_consent_enabled', empty($_POST['consent_enabled']) ? 0 : 1);
            BPM_Portal::event('communication_settings_changed');
        } else {
            $h = self::household($hid);
            if ($operation === 'invite') { self::invite($h); return; }
            if ($operation === 'tenancy') {
                $data = BPM_Domain::tenancy_data(wp_unslash($_POST));
                $data['label'] = BPM_Portal::text('label', 190);
                $u = BPM_Portal::unit(absint($_POST['unit_id'] ?? 0));
                if (!empty($h->unit_id) && (int)$h->unit_id !== (int)$u->id) { throw new InvalidArgumentException('Ein Wohnungswechsel benötigt ein neues Mietverhältnis.'); }
                $data['unit_id'] = $u->id;
                $data['unit_label'] = $u->address . ' · ' . $u->label;
                if ($wpdb->update(BPM_Portal::table('households'), $data, ['id' => $hid]) === false) { throw new RuntimeException('Die Profildaten konnten nicht gespeichert werden.'); }
                $updated = self::household($hid);
                BPM_Portal::expire_household($updated);
                BPM_Portal::event('tenancy_updated', $hid);
            } elseif ($operation === 'rent') {
                $effective = BPM_Domain::tenancy_date(trim((string) ($_POST['effective_from'] ?? '')));
                if (!$effective) { throw new InvalidArgumentException('Bitte das Gültigkeitsdatum angeben.'); }
                $data = ['household_id' => $hid, 'effective_from' => $effective,
                    'rent_cents' => BPM_Domain::money_cents((string) ($_POST['rent_amount'] ?? '')),
                    'advance_cents' => BPM_Domain::money_cents((string) ($_POST['advance_amount'] ?? '')),
                    'updated_at' => BPM_Portal::now(), 'actor_id' => get_current_user_id()];
                $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . BPM_Portal::table('rent_terms') . ' WHERE household_id=%d AND effective_from=%s', $hid, $effective));
                if ($existing) {
                    if ($wpdb->update(BPM_Portal::table('rent_terms'), $data, ['id' => $existing]) === false) { throw new RuntimeException('Die Mietbeträge konnten nicht gespeichert werden.'); }
                } else { $data['created_at'] = BPM_Portal::now(); BPM_Portal::insert('rent_terms', $data); }
                BPM_Portal::event('rent_terms_saved', $hid, (int) ($existing ?: $wpdb->insert_id));
            } elseif ($operation === 'resend') {
                if ($h->status !== 'pending_email' || !$h->user_id || !BPM_Portal::activation_mail((int) $h->user_id)) { throw new RuntimeException('Die Aktivierungsmail konnte nicht versendet werden. Bitte E-Mail-Konfiguration prüfen.'); }
            } elseif ($operation === 'access') {
                $new_status = sanitize_key($_POST['access_status'] ?? '');
                if (!$h->user_id || !in_array($new_status, ['active', 'blocked'], true) || !in_array($h->status, ['active', 'blocked', 'expired'], true)) {
                    throw new InvalidArgumentException('Nur bereits aktivierte Zugänge können hier gesperrt oder wieder freigegeben werden.');
                }
                if ($new_status === 'active' && BPM_Portal::past_deadline($h)) { throw new InvalidArgumentException('Die Zugangsfrist ist abgelaufen. Bitte zuerst das Mietende prüfen; eine Verlängerung über 25 Monate hinaus ist nicht möglich.'); }
                $wpdb->update(BPM_Portal::table('households'), ['status' => $new_status], ['id' => $hid]);
                if ($new_status === 'blocked') { WP_Session_Tokens::get_instance((int) $h->user_id)->destroy_all(); }
                BPM_Portal::event('access_' . $new_status, $hid);
            } elseif ($operation === 'document') {
                $title = BPM_Portal::text('title', 160);
                $category = sanitize_key($_POST['category'] ?? '');
                if (!in_array($category, ['vertrag', 'abrechnung', 'mitteilung', 'sonstiges'], true)) { throw new InvalidArgumentException('Bitte eine Dokumentkategorie auswählen.'); }
                $file = BPM_Storage::upload($_FILES['document'] ?? [], true);
                try { BPM_Portal::insert('documents', array_merge(['household_id' => $hid, 'title' => $title, 'category' => $category, 'created_at' => BPM_Portal::now()], $file)); }
                catch (Throwable $e) { @unlink(BPM_Storage::path($file['storage_key'])); throw $e; }
                BPM_Portal::event('document_uploaded', $hid, (int) $wpdb->insert_id);
                BPM_Portal::notify($hid, true);
            } elseif ($operation === 'meter') {
                $uid=absint($_POST['unit_id'] ?? 0);
                if ((int)$h->unit_id!==$uid) { throw new InvalidArgumentException('Die Wohnung stimmt nicht mit dem Mietverhältnis überein.'); }
                $data=wp_unslash($_POST);$data['active']=1;BPM_Units_Admin::save_meter($uid,$data);
            } elseif ($operation === 'admin_reply') {
                if (BPM_Tickets::enabled($h)) { throw new InvalidArgumentException('Bitte in der Ticketverwaltung antworten.'); }
                $thread = BPM_Portal::owned('threads', absint($_POST['thread_id'] ?? 0), $hid);
                $status = sanitize_key($_POST['status'] ?? '');
                if (!in_array($status, ['offen', 'in_bearbeitung', 'erledigt'], true)) { throw new InvalidArgumentException('Ungültiger Status.'); }
                BPM_Portal::insert('messages', ['thread_id' => $thread->id, 'user_id' => get_current_user_id(), 'body' => BPM_Portal::text('body'), 'created_at' => BPM_Portal::now()]);
                $wpdb->update(BPM_Portal::table('threads'), ['status' => $status, 'updated_at' => BPM_Portal::now()], ['id' => $thread->id]);
                BPM_Portal::event('admin_replied', $hid, (int) $thread->id);
                BPM_Portal::notify($hid, true);
            } elseif ($operation === 'admin_reading') {
                self::save_reading($h, wp_unslash($_POST));
            } elseif ($operation === 'review_reading') {
                $reading = BPM_Portal::owned('readings', absint($_POST['reading_id'] ?? 0), $hid);
                $status = sanitize_key($_POST['status'] ?? '');
                if (!in_array($status, ['bestaetigt', 'rueckfrage'], true)) { throw new InvalidArgumentException('Ungültiger Prüfstatus.'); }
                $wpdb->update(BPM_Portal::table('readings'), ['status' => $status], ['id' => $reading->id]);
                BPM_Portal::event('reading_' . $status, $hid, (int) $reading->id);
            }
        }
        wp_safe_redirect(add_query_arg(['page' => 'belger-mieterportal', 'household_id' => $hid, 'bpm_done' => 1], admin_url('admin.php'))); exit;
    }
    public static function save_reading(object $h, array $data): int {
        global $wpdb;
        if (!BPM_Portal::manager()) { throw new RuntimeException('Keine Berechtigung.'); }
        $date = BPM_Domain::date((string)($data['read_at'] ?? ''));
        $meter = BPM_Portal::meter_for_reading(absint($data['meter_id'] ?? 0), $h, $date, true);
        $value = BPM_Domain::reading((string)($data['value'] ?? ''));
        $source = sanitize_key($data['source'] ?? '');
        if (!in_array($source, ['verwaltung', 'ista', 'messdienst'], true)) { throw new InvalidArgumentException('Bitte eine gültige Quelle auswählen.'); }
        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . BPM_Portal::table('readings') . ' WHERE meter_id=%d AND read_at=%s', $meter->id, $date));
        if ($exists) { throw new InvalidArgumentException('Für diesen Zähler und Ablesetag ist bereits ein Stand vorhanden. Bitte den vorhandenen Eintrag prüfen.'); }
        BPM_Portal::insert('readings', ['household_id'=>(int)$h->id, 'meter_id'=>(int)$meter->id, 'value'=>$value, 'read_at'=>$date, 'source'=>$source, 'status'=>'bestaetigt', 'photo_key'=>'', 'created_at'=>BPM_Portal::now()]);
        $id=(int)$wpdb->insert_id;
        BPM_Portal::event('admin_reading_created', (int)$h->id, $id);
        return $id;
    }
    private static function invite(object $h): void {
        global $wpdb;
        if (get_post_status((int) get_option('bpm_page_id')) !== 'publish') {
            throw new RuntimeException('Das Portal befindet sich in Vorbereitung. Einladungen sind erst nach Veröffentlichung der geprüften Portalseite möglich.');
        }
        if ($h->user_id || $h->status !== 'invited') { throw new RuntimeException('Diese Mietpartei hat bereits einen Zugang. Ein weiterer Zugang wird nicht erstellt.'); }
        $lock = BPM_Domain::token();
        $locked = $wpdb->query($wpdb->prepare('UPDATE ' . BPM_Portal::table('households') . ' SET registration_lock=%s WHERE id=%d AND user_id IS NULL AND registration_lock=%s AND status=%s', $lock, $h->id, '', 'invited'));
        if ($locked !== 1) { throw new RuntimeException('Für diese Mietpartei läuft bereits eine Registrierung. Bitte den Vorgang prüfen.'); }
        $token = BPM_Domain::invitation_token();
        $expires = gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS);
        try {
            $wpdb->update(BPM_Portal::table('invites'), ['state' => 'revoked'], ['household_id' => $h->id, 'state' => 'open']);
            BPM_Portal::insert('invites', ['household_id' => $h->id, 'token_hash' => BPM_Domain::token_hash($token), 'expires_at' => $expires, 'state' => 'open', 'created_at' => BPM_Portal::now()]);
            $invite_id = (int) $wpdb->insert_id;
        } finally { $wpdb->update(BPM_Portal::table('households'), ['registration_lock' => ''], ['id' => $h->id, 'registration_lock' => $lock]); }
        BPM_Portal::event('invitation_issued', (int) $h->id, $invite_id);
        BPM_Portal::headers();
        $url = BPM_Portal::activation_url() . '#einladung=' . $token;
        ?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Einladung ins Mieterportal</title><link rel="stylesheet" href="<?= esc_url(plugins_url('assets/portal.css', BPM_FILE)) ?>"></head><body class="bpm-letter"><main class="bpm-letter-paper"><header class="bpm-letter-brand"><img src="<?= esc_url(home_url('/wp-content/uploads/2019/06/Belger_Logo.svg')) ?>" alt="Belger Immobilienverwaltung"></header><div class="bpm-letter-sender">Belger Immobilienverwaltung · Chausseestr. 30 · 15806 Zossen</div><address class="bpm-letter-recipient"><strong><?= esc_html($h->label) ?></strong><br><?= nl2br(esc_html($h->unit_label)) ?></address><div class="bpm-letter-date">Zossen, <?= esc_html(wp_date('d.m.Y')) ?></div><article class="bpm-letter-content"><h1>Ihr Zugang zum Mieterportal</h1><p>Guten Tag,</p><p>mit Ihrem gemeinsamen Mieterzugang können Sie Unterlagen abrufen, Nachrichten an die Verwaltung senden und Zählerstände melden.</p><div class="bpm-letter-activation"><div><h2>So aktivieren Sie Ihren Zugang</h2><ol><li>Scannen Sie den QR-Code oder öffnen Sie die unten genannte Adresse.</li><li>Geben Sie den Einladungscode und die gemeinsame Kontakt-E-Mail-Adresse Ihrer Mietpartei ein.</li><li>Öffnen Sie unsere Bestätigungs-E-Mail und legen Sie Ihr Passwort fest.</li></ol></div><div data-bpm-qr="<?= esc_attr($url) ?>" aria-label="QR-Code zur einmaligen Aktivierung"></div></div><p class="bpm-letter-url"><?= esc_html(BPM_Portal::activation_url()) ?></p><p class="bpm-code"><?= esc_html($token) ?></p><p class="bpm-letter-valid">Einmalig verwendbar bis <?= esc_html(get_date_from_gmt($expires, 'd.m.Y H:i')) ?> Uhr. Ein neuer Einladungsbrief ersetzt frühere ungenutzte Codes.</p><?php if ($h->expected_email): ?><p>Bitte verwenden Sie die bereits mit der Verwaltung vereinbarte E-Mail-Adresse.</p><?php endif; ?><p>Für Ihren Vertrag gibt es einen gemeinsamen Zugang. Stimmen Sie die Nutzung innerhalb Ihrer Mietpartei ab und bewahren Sie den Brief vertraulich auf.</p><p>Die elektronische Zustellung vereinbaren Sie anschließend gesondert im Portal. Allein durch die Aktivierung ändern sich Ihre bisherigen Kommunikationswege nicht.</p><p>Freundliche Grüße<br>Ihre Belger Immobilienverwaltung</p></article><footer class="bpm-letter-footer"><strong>Belger Immobilienverwaltung · Tobias Belger</strong><br>Chausseestr. 30 · 15806 Zossen<br>Telefon 033702 121228 · info@belger-immobilienverwaltung.de<br>www.belger-immobilienverwaltung.de</footer></main><div class="bpm-no-print bpm-letter-tools"><button type="button" id="bpm-print">Brief drucken / als PDF speichern</button><p><a href="<?= esc_url(admin_url('admin.php?page=belger-mieterportal&household_id=' . (int) $h->id)) ?>">Zurück zur Verwaltung</a></p><p>Der Code wird nicht im Klartext gespeichert. Drucken oder speichern Sie diesen Brief jetzt sicher. Die Empfängeranschrift vor dem Versand bitte prüfen und gegebenenfalls ergänzen.</p></div><script src="<?= esc_url(plugins_url('assets/qrcode.js', BPM_FILE)) ?>"></script><script src="<?= esc_url(plugins_url('assets/portal.js', BPM_FILE)) ?>"></script></body></html><?php
        exit;
    }
    private static function unit_select(string $id, int $selected = 0): void {
        global $wpdb;
        $units = $wpdb->get_results('SELECT u.*, b.address FROM ' . BPM_Portal::table('units') . ' u JOIN ' . BPM_Portal::table('buildings') . ' b ON b.id=u.building_id ORDER BY b.address,u.label');
        echo '<label for="' . esc_attr($id) . '">Wohnung</label><select required id="' . esc_attr($id) . '" name="unit_id"><option value="">Bitte auswählen</option>';
        foreach ($units as $u) { echo '<option value="' . (int)$u->id . '" ' . selected($selected, $u->id, false) . '>' . esc_html($u->address . ' · ' . $u->label) . '</option>'; }
        echo '</select>';
    }
    public static function render(): void {
        if (!BPM_Portal::manager()) { wp_die('Keine Berechtigung.'); }
        global $wpdb;
        $endpoint = admin_url('admin-post.php');
        $search = mb_substr(sanitize_text_field(wp_unslash((string)($_GET['bpm_search'] ?? ''))), 0, 190);
        $building_filter = absint($_GET['bpm_building'] ?? 0);
        $unit_filter = absint($_GET['bpm_unit'] ?? 0);
        $status_filter = sanitize_key($_GET['bpm_status'] ?? '');
        $status_labels = ['invited'=>'Noch nicht registriert', 'pending_email'=>'E-Mail-Bestätigung offen', 'active'=>'Aktiv', 'blocked'=>'Gesperrt', 'expired'=>'Abgelaufen'];
        if (!isset($status_labels[$status_filter])) { $status_filter = ''; }
        $where = ['1=1']; $params = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(h.label LIKE %s OR h.reference LIKE %s OR h.unit_label LIKE %s OR b.label LIKE %s OR b.address LIKE %s OR u.reference LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($building_filter) { $where[] = 'u.building_id=%d'; $params[] = $building_filter; }
        if ($unit_filter) { $where[] = 'h.unit_id=%d'; $params[] = $unit_filter; }
        if ($status_filter !== '') { $where[] = 'h.status=%s'; $params[] = $status_filter; }
        $from = ' FROM ' . BPM_Portal::table('households') . ' h LEFT JOIN ' . BPM_Portal::table('units') . ' u ON u.id=h.unit_id LEFT JOIN ' . BPM_Portal::table('buildings') . ' b ON b.id=u.building_id WHERE ' . implode(' AND ', $where);
        $count_sql = 'SELECT COUNT(*)' . $from;
        $total = (int)$wpdb->get_var($params ? $wpdb->prepare($count_sql, ...$params) : $count_sql);
        $per_page = 10;
        $pages = max(1, (int)ceil($total / $per_page));
        $list_page = min($pages, max(1, absint($_GET['bpm_paged'] ?? 1)));
        $list_sql = 'SELECT h.*' . $from . ' ORDER BY h.unit_label, h.label, h.id LIMIT %d OFFSET %d';
        $households = $wpdb->get_results($wpdb->prepare($list_sql, ...array_merge($params, [$per_page, ($list_page-1)*$per_page])));
        $list_args = array_filter(['page'=>'belger-mieterportal', 'bpm_search'=>$search, 'bpm_building'=>$building_filter, 'bpm_unit'=>$unit_filter, 'bpm_status'=>$status_filter, 'bpm_paged'=>$list_page], static fn($v)=>$v !== '' && $v !== 0);
        $hid = absint($_GET['household_id'] ?? 0);
        $selected = $hid ? self::household($hid) : null;
        try { BPM_Storage::root(); $storage = true; } catch (Throwable $e) { $storage = false; }
        $mail_errors = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . BPM_Portal::table('events') . " WHERE kind='mail_failed'");
        ?><div class="wrap bpm-admin bpm-portal"><h1>Mieterportal verwalten</h1><p>Ein Zugang je Mietpartei · Vorbereitete Pilotversion <?= esc_html(BPM_VERSION) ?></p>
        <?php if (get_post_status((int) get_option('bpm_page_id')) !== 'publish'): ?><div class="notice notice-info"><p>Das Portal ist noch privat und nur für die Verwaltung sichtbar. Einladungen bleiben gesperrt. Vor Veröffentlichung müssen die Testkopie, der Dateispeicher und der E-Mail-Versand geprüft sein.</p></div><?php endif; ?>
        <?php if (isset($_GET['bpm_done'])): ?><div class="notice notice-success"><p>Die Angaben wurden gespeichert.</p></div><?php endif; ?>
        <?php if (!$storage): ?><div class="notice notice-warning"><p>Der geschützte Dateispeicher ist noch nicht eingerichtet. Dokumentuploads und Fotouploads bleiben gesperrt, bis ein schreibbares Verzeichnis außerhalb des öffentlichen Webverzeichnisses über BPM_PRIVATE_DIR eingerichtet ist.</p></div><?php endif; ?>
        <?php if ($mail_errors): ?><div class="notice notice-warning"><p><?= $mail_errors ?> fehlgeschlagene E-Mail-Versandvorgänge wurden protokolliert. Bitte Zustellung prüfen; ein erfolgreicher Versandaufruf bestätigt noch keinen Eingang beim Empfänger.</p></div><?php endif; ?>
        <section class="bpm-card"><h2>Gebäude und Wohnungen</h2><p>Wohnungen und Zähler bleiben bestehen. Für jeden Mieterwechsel wird ein neues Mietverhältnis mit eigenem Zugang angelegt.</p>
        <?php $buildings = $wpdb->get_results('SELECT * FROM ' . BPM_Portal::table('buildings') . ' ORDER BY address'); foreach ($buildings as $b): ?><details><summary><?= esc_html($b->label) ?></summary><ul><?php $units = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . BPM_Portal::table('units') . ' WHERE building_id=%d ORDER BY label', $b->id)); foreach ($units as $u): ?><li><strong><?= esc_html($u->label) ?></strong> · <?= esc_html($u->reference) ?> · <a href="<?= esc_url(BPM_Units_Admin::url((int)$u->id)) ?>">Wohnung und Zähler bearbeiten</a> · <a href="<?= esc_url(add_query_arg(['page'=>'belger-mieterportal','bpm_building'=>$b->id,'bpm_unit'=>$u->id],admin_url('admin.php'))) ?>">Mietverhältnisse dieser Wohnung anzeigen</a></li><?php endforeach; ?></ul></details><?php endforeach; ?>
        <div class="bpm-columns"><form method="post" action="<?= esc_url($endpoint) ?>"><h3>Gebäude anlegen</h3><?php BPM_Portal::form('building'); ?><label for="bpm-building-ref">Gebäudenummer</label><input id="bpm-building-ref" name="building_reference" maxlength="100" required><label for="bpm-building-name">Gebäudebezeichnung</label><input id="bpm-building-name" name="building_label" maxlength="190" required><label for="bpm-building-address">Anschrift</label><input id="bpm-building-address" name="address" maxlength="190" required><button>Gebäude speichern</button></form>
        <form method="post" action="<?= esc_url($endpoint) ?>"><h3>Wohnung anlegen</h3><?php BPM_Portal::form('unit'); ?><label for="bpm-building-choice">Gebäude</label><select id="bpm-building-choice" name="building_id" required><option value="">Bitte auswählen</option><?php foreach ($buildings as $b): ?><option value="<?= (int)$b->id ?>"><?= esc_html($b->label) ?></option><?php endforeach; ?></select><label for="bpm-unit-ref">Wohnungsnummer</label><input id="bpm-unit-ref" name="unit_reference" maxlength="100" required><label for="bpm-unit-name">Wohnungsbezeichnung</label><input id="bpm-unit-name" name="unit_name" maxlength="190" required><button>Wohnung speichern</button></form></div></section>
        <div class="bpm-columns"><section class="bpm-card"><h2>Mietparteien</h2><p>Wählen Sie die Mietpartei, für die Sie Dokumente oder Nachrichten bereitstellen möchten.</p>
        <form method="get" action="<?= esc_url(admin_url('admin.php')) ?>" class="bpm-household-filter"><input type="hidden" name="page" value="belger-mieterportal"><?php if ($hid): ?><input type="hidden" name="household_id" value="<?= $hid ?>"><?php endif; ?>
        <label for="bpm-search">Freitextsuche</label><input type="search" id="bpm-search" name="bpm_search" maxlength="190" value="<?= esc_attr($search) ?>" placeholder="Name, Vertragsnummer, Wohnung oder Anschrift">
        <label for="bpm-filter-building">Gebäude filtern</label><select id="bpm-filter-building" name="bpm_building"><option value="0">Alle Gebäude</option><?php foreach ($buildings as $b): ?><option value="<?= (int)$b->id ?>" <?= selected($building_filter,$b->id,false) ?>><?= esc_html($b->label) ?></option><?php endforeach; ?></select>
        <label for="bpm-filter-unit">Wohnung filtern</label><select id="bpm-filter-unit" name="bpm_unit"><option value="0">Alle Wohnungen</option><?php $filter_units = $wpdb->get_results('SELECT u.*,b.address FROM ' . BPM_Portal::table('units') . ' u JOIN ' . BPM_Portal::table('buildings') . ' b ON b.id=u.building_id ORDER BY b.address,u.label'); foreach ($filter_units as $u): ?><option value="<?= (int)$u->id ?>" <?= selected($unit_filter,$u->id,false) ?>><?= esc_html($u->address . ' · ' . $u->label) ?></option><?php endforeach; ?></select>
        <label for="bpm-filter-status">Zugangsstatus filtern</label><select id="bpm-filter-status" name="bpm_status"><option value="">Alle Zugangsstatus</option><?php foreach ($status_labels as $key=>$label): ?><option value="<?= esc_attr($key) ?>" <?= selected($status_filter,$key,false) ?>><?= esc_html($label) ?></option><?php endforeach; ?></select><p><button type="submit">Mietparteien filtern</button> <a href="<?= esc_url(add_query_arg(['page'=>'belger-mieterportal'],admin_url('admin.php'))) ?>">Filter zurücksetzen</a></p></form>
        <p class="bpm-list-count" role="status"><?= $total ? (($list_page-1)*$per_page+1) . '–' . min($list_page*$per_page,$total) . ' von ' . $total . ' Mietparteien' : 'Keine Mietparteien für diese Filter gefunden.' ?></p><ul class="bpm-households"><?php foreach ($households as $h): ?><li><a href="<?= esc_url(add_query_arg(array_merge($list_args, ['household_id'=>$h->id]), admin_url('admin.php'))) ?>"><?= esc_html($h->label) ?></a><small><?= esc_html($h->unit_label . ' · ' . $h->reference . ' · ' . $h->status) ?></small></li><?php endforeach; ?></ul>
        <?php if ($pages>1): ?><nav class="bpm-pagination" aria-label="Mietparteien blättern"><?php if ($list_page>1): ?><a class="bpm-button" rel="prev" href="<?= esc_url(add_query_arg(array_merge($list_args,['bpm_paged'=>$list_page-1,'household_id'=>$hid ?: false]),admin_url('admin.php'))) ?>">Zurück</a><?php endif; ?><span>Seite <?= $list_page ?> von <?= $pages ?></span><?php if ($list_page<$pages): ?><a class="bpm-button" rel="next" href="<?= esc_url(add_query_arg(array_merge($list_args,['bpm_paged'=>$list_page+1,'household_id'=>$hid ?: false]),admin_url('admin.php'))) ?>">Weiter</a><?php endif; ?></nav><?php endif; ?></section>
        <section class="bpm-card"><h2>Mietpartei anlegen</h2><form method="post" action="<?= esc_url($endpoint) ?>"><?php BPM_Portal::form('household'); ?><label for="bpm-reference">Eindeutige Vertrags-/Mietparteiennummer</label><input id="bpm-reference" name="reference" maxlength="100" required><label for="bpm-label">Bezeichnung der Mietpartei</label><input id="bpm-label" name="label" maxlength="190" required><?php self::unit_select('bpm-unit'); ?><label for="bpm-expected">Vereinbarte Kontakt-E-Mail (optional)</label><input id="bpm-expected" name="expected_email" type="email" maxlength="190"><p class="bpm-small">Wenn hinterlegt, kann nur diese E-Mail-Adresse die Einladung einlösen. Ohne Vorgabe legitimiert der vertrauliche Brief die erstmalige Registrierung.</p><button type="submit">Mietpartei anlegen</button></form></section></div>
        <?php if ($selected): $h = $selected; ?>
        <section class="bpm-card"><h2><?= esc_html($h->label) ?></h2><p><?= esc_html($h->unit_label) ?> · <?= esc_html($h->reference) ?></p><p><strong>Zugang:</strong> <?= esc_html($h->status) ?></p>
            <?php if (!$h->user_id): ?><form method="post" action="<?= esc_url($endpoint) ?>" target="_blank"><?php BPM_Portal::form('invite'); ?><input type="hidden" name="household_id" value="<?= (int) $h->id ?>"><button type="submit">Einladungsbrief mit QR-Code erstellen</button></form><p class="bpm-small">30 Tage gültig. Frühere ungenutzte Einladungen werden ungültig.</p>
            <?php elseif ($h->status === 'pending_email'): ?><form method="post" action="<?= esc_url($endpoint) ?>"><?php BPM_Portal::form('resend'); ?><input type="hidden" name="household_id" value="<?= (int) $h->id ?>"><button type="submit">Aktivierungsmail erneut senden</button></form>
            <?php else: ?><p>Ein Zugang ist zugeordnet. Es wird kein weiterer Zugang für diesen Vertrag erstellt.</p><form method="post" action="<?= esc_url($endpoint) ?>"><?php BPM_Portal::form('access'); ?><input type="hidden" name="household_id" value="<?= (int) $h->id ?>"><input type="hidden" name="access_status" value="<?= in_array($h->status, ['blocked', 'expired'], true) ? 'active' : 'blocked' ?>"><button type="submit"><?= in_array($h->status, ['blocked', 'expired'], true) ? 'Zugang wieder freigeben' : 'Mieterzugang sperren' ?></button></form><?php endif; ?>
        </section>
        <section class="bpm-card"><h2>Mieterprofil und Kündigung</h2><p>Die Angaben werden der Mietpartei im Profil angezeigt. Das Erfassen einer Kündigung versendet keine Erklärung.</p>
        <form method="post" action="<?= esc_url($endpoint) ?>"><?php BPM_Portal::form('tenancy'); ?><input type="hidden" name="household_id" value="<?= (int) $h->id ?>">
        <label for="bpm-profile-label">Mietpartei</label><input id="bpm-profile-label" name="label" maxlength="190" required value="<?= esc_attr($h->label) ?>">
        <?php self::unit_select('bpm-profile-unit', (int)($h->unit_id ?? 0)); ?><p class="bpm-small">Bei einem Mieterwechsel ein neues Mietverhältnis mit dieser Wohnung anlegen. Der bisherige Vertrag und seine Unterlagen bleiben erhalten.</p>
        <div class="bpm-columns"><div><label for="bpm-tenancy-start">Mietbeginn</label><input type="date" id="bpm-tenancy-start" name="tenancy_start" value="<?= esc_attr($h->tenancy_start ?? '') ?>"></div><div><label for="bpm-tenancy-end">Mietende</label><input type="date" id="bpm-tenancy-end" name="tenancy_end" value="<?= esc_attr($h->tenancy_end ?? '') ?>"></div></div>
        <label for="bpm-notice-received">Kündigung / Vereinbarung eingegangen am</label><input type="date" id="bpm-notice-received" name="notice_received" value="<?= esc_attr($h->notice_received ?? '') ?>">
        <label for="bpm-termination-by">Beendigungsart</label><select id="bpm-termination-by" name="termination_by"><?php foreach (['' => 'Keine Kündigung erfasst', 'mieter' => 'Kündigung durch Mietpartei', 'vermieter' => 'Kündigung durch Verwaltung / Vermieter', 'aufhebung' => 'Aufhebungsvereinbarung'] as $key => $caption): ?><option value="<?= esc_attr($key) ?>" <?= selected($h->termination_by ?? '', $key, false) ?>><?= esc_html($caption) ?></option><?php endforeach; ?></select>
        <?php $deadline = BPM_Domain::access_deadline($h->tenancy_end ?? null); ?><p><strong>Portalzugang längstens bis:</strong> <?= $deadline ? esc_html(mysql2date('d.m.Y', $deadline)) . ' einschließlich (Europe/Berlin)' : 'Noch kein Mietende hinterlegt' ?></p><p class="bpm-small">25 Kalendermonate nach Mietende. Danach werden auch bestehende Sitzungen gesperrt. Eine frühere manuelle Sperre bleibt möglich. Dokumente werden durch die Zugangssperre nicht gelöscht.</p><button type="submit">Profil und Kündigung speichern</button></form></section>
        <section class="bpm-card"><h2>Mietzins und Nebenkostenvorauszahlung</h2><p>Monatliche Eurobeträge. Ein neuer Gültigkeitsbeginn ergänzt die Historie; derselbe Gültigkeitsbeginn korrigiert den vorhandenen Stand.</p>
        <?php $current_rent = BPM_Portal::current_rent((int) $h->id); if ($current_rent): ?><p><strong>Aktuell seit <?= esc_html(mysql2date('d.m.Y', $current_rent->effective_from)) ?>:</strong> <?= esc_html(BPM_Portal::euros((int) $current_rent->rent_cents)) ?> Nettokaltmiete + <?= esc_html(BPM_Portal::euros((int) $current_rent->advance_cents)) ?> NK-Vorauszahlung.</p><?php endif; ?>
        <form method="post" action="<?= esc_url($endpoint) ?>"><?php BPM_Portal::form('rent'); ?><input type="hidden" name="household_id" value="<?= (int) $h->id ?>"><label for="bpm-rent-effective">Gültig ab</label><input type="date" id="bpm-rent-effective" name="effective_from" required><label for="bpm-rent-amount">Monatlicher Mietzins / Nettokaltmiete (€)</label><input id="bpm-rent-amount" name="rent_amount" inputmode="decimal" required placeholder="z. B. 750,00"><label for="bpm-advance-amount">Monatliche Nebenkostenvorauszahlung (€)</label><input id="bpm-advance-amount" name="advance_amount" inputmode="decimal" required placeholder="z. B. 200,00"><button type="submit">Mietbeträge speichern</button></form>
        <?php $rent_history = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . BPM_Portal::table('rent_terms') . ' WHERE household_id=%d ORDER BY effective_from DESC LIMIT 100', $h->id)); if ($rent_history): ?><h3>Bisherige und geplante Beträge</h3><div class="bpm-table-scroll"><table><thead><tr><th>Gültig ab</th><th>Nettokaltmiete</th><th>NK-Vorauszahlung</th><th>Summe dieser Beträge</th></tr></thead><tbody><?php foreach ($rent_history as $term): ?><tr><td><?= esc_html(mysql2date('d.m.Y', $term->effective_from)) ?><?= $term->effective_from > BPM_Portal::today() ? ' (zukünftig)' : '' ?></td><td><?= esc_html(BPM_Portal::euros((int) $term->rent_cents)) ?></td><td><?= esc_html(BPM_Portal::euros((int) $term->advance_cents)) ?></td><td><?= esc_html(BPM_Portal::euros((int) $term->rent_cents + (int) $term->advance_cents)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
        <?php BPM_Account::admin_card($h); ?><section class="bpm-card"><h2>Dokument bereitstellen</h2><p>Wählen Sie eine PDF-Datei aus und stellen Sie diese der ausgewählten Mietpartei bereit. Bereitgestellte PDFs werden nicht überschrieben; Korrekturen stellen Sie als neues Dokument mit einem eindeutigen Titel bereit. Vor jedem Abruf werden Dateigröße und SHA-256-Prüfsumme geprüft. Unter jedem bereitgestellten Dokument sehen Sie „Durch Mietpartei abgerufen“ mit Datum, Uhrzeit und Konto-ID oder „Noch nicht durch die Mietpartei abgerufen“. Verwaltungsabrufe zählen nicht.</p><form method="post" action="<?= esc_url($endpoint) ?>" enctype="multipart/form-data"><?php BPM_Portal::form('document'); ?><input type="hidden" name="household_id" value="<?= (int) $h->id ?>"><label for="bpm-doctitle">Titel</label><input id="bpm-doctitle" name="title" maxlength="160" required><label for="bpm-category">Kategorie</label><select id="bpm-category" name="category"><option value="vertrag">Vertrag</option><option value="abrechnung">Abrechnung</option><option value="mitteilung">Mitteilung</option><option value="sonstiges">Sonstiges</option></select><label for="bpm-docfile">PDF-Datei (maximal 15 MB)</label><input id="bpm-docfile" name="document" type="file" accept="application/pdf" required><p class="bpm-small">Empfänger: ausschließlich <?= esc_html($h->label . ' · ' . $h->unit_label) ?> und berechtigte Verwalter.</p><button type="submit" <?= $storage ? '' : 'disabled' ?>>PDF bereitstellen</button></form>
        <?php $docs = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . BPM_Portal::table('documents') . ' WHERE household_id=%d ORDER BY id DESC LIMIT 100', $h->id)); ?><ul class="bpm-filelist"><?php foreach ($docs as $doc): ?><li><div><strong><?= esc_html($doc->title) ?></strong><small><?= esc_html($doc->category . ' · ' . $doc->filename) ?></small><small><?= !empty($doc->first_download_at) ? 'Durch Mietpartei abgerufen: ' . esc_html(get_date_from_gmt($doc->first_download_at, 'd.m.Y H:i:s')) . ' · Konto-ID ' . (int) $doc->first_download_user_id : 'Noch nicht durch die Mietpartei abgerufen' ?></small></div><a href="<?= esc_url(BPM_Portal::download_url((int) $doc->id)) ?>">PDF prüfen</a></li><?php endforeach; ?></ul></section>
        <section class="bpm-card"><h2>Messstellen und Zählergeräte der Wohnung</h2><p>Messstellen, Geräte und Gültigkeitszeiträume verwalten Sie direkt an der Wohnung.</p><a class="bpm-button" href="<?= esc_url(BPM_Units_Admin::url((int)$h->unit_id)) ?>">Messstellen und Geräte bearbeiten</a><?php $meters=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.BPM_Portal::table('meters').' WHERE unit_id=%d ORDER BY label,number',(int)$h->unit_id)); ?></section>
        <section class="bpm-card" id="bpm-admin-reading"><h2>Zählerstand eintragen</h2><p>Für Ablesungen der Verwaltung oder eines Messdienstes. Datum und Quelle werden gespeichert; der Stand bleibt dem Wohnungszähler zugeordnet.</p><form method="post" action="<?= esc_url($endpoint) ?>"><?php BPM_Portal::form('admin_reading'); ?><input type="hidden" name="household_id" value="<?= (int)$h->id ?>"><label for="bpm-admin-meter">Zähler</label><select id="bpm-admin-meter" name="meter_id" required><option value="">Bitte auswählen</option><?php foreach ($meters as $m): ?><option value="<?= (int)$m->id ?>"><?= esc_html($m->label . ' · ' . $m->number . ' · ' . $m->unit) ?></option><?php endforeach; ?></select><label for="bpm-admin-read-at">Ablesedatum</label><input type="date" id="bpm-admin-read-at" name="read_at" max="<?= esc_attr(BPM_Portal::today()) ?>" required><label for="bpm-admin-value">Zählerstand (ohne Tausenderpunkte)</label><input id="bpm-admin-value" name="value" inputmode="decimal" placeholder="z. B. 1234,567" required><label for="bpm-admin-source">Quelle</label><select id="bpm-admin-source" name="source"><option value="verwaltung">Eigene Ablesung / Verwaltung</option><option value="ista">ista</option><option value="messdienst">Anderer Messdienst</option></select><p class="bpm-small">Verwaltungseinträge werden als bestätigt gespeichert. Es erfolgt keine automatische Übernahme in WISO.</p><button type="submit">Zählerstand speichern</button></form></section>
        <section class="bpm-card"><h2>Zählerstandsmeldungen prüfen</h2><?php $readings = $wpdb->get_results($wpdb->prepare('SELECT r.*, m.label, m.number, m.unit FROM ' . BPM_Portal::table('readings') . ' r JOIN ' . BPM_Portal::table('meters') . ' m ON m.id=r.meter_id WHERE r.household_id=%d ORDER BY r.id DESC LIMIT 100', $h->id)); foreach ($readings as $r): ?><details class="bpm-thread"><summary><?= esc_html($r->label . ' · ' . $r->number . ' · ' . BPM_Domain::reading_display((string)$r->value) . ' ' . $r->unit . ' · ' . $r->read_at . ' · Quelle: ' . $r->source . ' · ' . $r->status) ?></summary><?php if ($r->photo_key): ?><p><a href="<?= esc_url(BPM_Portal::download_url((int) $r->id, true)) ?>">Zählerfoto herunterladen</a></p><?php endif; ?><form method="post" action="<?= esc_url($endpoint) ?>"><?php BPM_Portal::form('review_reading'); ?><input type="hidden" name="household_id" value="<?= (int) $h->id ?>"><input type="hidden" name="reading_id" value="<?= (int) $r->id ?>"><label for="bpm-review-<?= (int) $r->id ?>">Prüfung</label><select name="status" id="bpm-review-<?= (int) $r->id ?>"><option value="bestaetigt">Bestätigt</option><option value="rueckfrage">Rückfrage erforderlich</option></select><button type="submit">Prüfstatus speichern</button></form><p class="bpm-small">Die Prüfung überträgt keine Daten automatisch in WISO oder eine Abrechnung.</p></details><?php endforeach; ?></section>
        <?php if (BPM_Tickets::enabled($h)): BPM_Tickets::admin_list($h); else: ?>
        <section class="bpm-card"><h2>Nachrichten beantworten</h2><?php $threads = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . BPM_Portal::table('threads') . ' WHERE household_id=%d ORDER BY updated_at DESC, id DESC LIMIT 50', $h->id)); foreach ($threads as $t): ?><details class="bpm-thread"><summary><?= esc_html($t->subject . ' · ' . $t->kind . ' · ' . $t->status) ?></summary><?php $messages = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . BPM_Portal::table('messages') . ' WHERE thread_id=%d ORDER BY id LIMIT 100', $t->id)); foreach ($messages as $m): ?><article class="bpm-message"><p class="bpm-small"><?= (int) $m->user_id === (int) $h->user_id ? 'Mietpartei' : 'Verwaltung' ?> · <?= esc_html(get_date_from_gmt($m->created_at, 'd.m.Y H:i')) ?></p><p><?= nl2br(esc_html($m->body)) ?></p></article><?php endforeach; ?><form method="post" action="<?= esc_url($endpoint) ?>"><?php BPM_Portal::form('admin_reply'); ?><input type="hidden" name="household_id" value="<?= (int) $h->id ?>"><input type="hidden" name="thread_id" value="<?= (int) $t->id ?>"><label for="bpm-answer-<?= (int) $t->id ?>">Antwort</label><textarea id="bpm-answer-<?= (int) $t->id ?>" name="body" rows="4" maxlength="5000" required></textarea><label for="bpm-state-<?= (int) $t->id ?>">Status</label><select id="bpm-state-<?= (int) $t->id ?>" name="status"><option value="in_bearbeitung">In Bearbeitung</option><option value="offen">Offen</option><option value="erledigt">Erledigt</option></select><button type="submit">Antwort senden</button></form></details><?php endforeach; ?></section>
        <?php endif; ?>
        <section class="bpm-card"><h2>Dokumentierte Kommunikationswahl</h2><?php $prefs = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . BPM_Portal::table('preferences') . ' WHERE household_id=%d ORDER BY id DESC LIMIT 30', $h->id)); if (!$prefs): ?><p>Noch keine Erklärung abgegeben.</p><?php endif; foreach ($prefs as $p): ?><details><summary><?= esc_html($p->choice . ' · ' . get_date_from_gmt($p->created_at, 'd.m.Y H:i') . ' · ' . $p->version) ?></summary><p><?= esc_html($p->wording) ?></p><p>Vertretungsberechtigung bestätigt: <?= (int) $p->authority_confirmed ? 'Ja' : 'Nein / Widerruf' ?></p></details><?php endforeach; ?></section>
        <?php endif; ?>
        <section class="bpm-card"><h2>Freigabe der Kommunikationsvereinbarung</h2><p>Die Textfassung ist ein Entwurf. Vor einer Freigabe müssen Wortlaut, Vertretung der Mietpartei, Zustellung und alternative Kommunikationswege rechtlich und organisatorisch abgestimmt sein.</p><details><summary>Entwurf lesen</summary><p><?= esc_html(BPM_Domain::CONSENT_TEXT) ?></p><p>Fassung: <?= esc_html(BPM_Domain::CONSENT_VERSION) ?></p></details><form method="post" action="<?= esc_url($endpoint) ?>"><?php BPM_Portal::form('settings'); ?><label class="bpm-check"><input type="checkbox" name="consent_enabled" value="1" <?= checked((bool) get_option('bpm_consent_enabled'), true, false) ?>> Der Text und die Abläufe sind abgestimmt. Die freiwillige Wahl darf im Portal angeboten werden.</label><button type="submit">Freigabe speichern</button></form></section>
        </div><?php
    }
}
