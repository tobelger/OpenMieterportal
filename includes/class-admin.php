<?php

defined('ABSPATH') || exit;

final class BPM_Admin
{
    public static function boot(): void
    {
        add_action('admin_menu', static function () {
            add_menu_page(__('Mieterportal', 'openmieterportal'), __('Mieterportal', 'openmieterportal'), 'bpm_manage', 'belger-mieterportal', [self::class, 'render'], 'dashicons-building', 26);
            add_submenu_page('belger-mieterportal', __('Mieterportal', 'openmieterportal'), __('Übersicht', 'openmieterportal'), 'bpm_manage', 'belger-mieterportal', [self::class,'render']);
        });
        add_action('admin_enqueue_scripts', static function ($hook) {
            if ($hook === 'toplevel_page_belger-mieterportal') {
                wp_enqueue_style('bpm-admin', plugins_url('assets/portal.css', BPM_FILE), [], BPM_VERSION);
            }
        });
    }
    private static function household(int $id): object
    {
        global $wpdb;
        $h = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', BPM_Portal::table('households'), $id));
        if (!$h) {
            throw new InvalidArgumentException(esc_html__('Die Mietpartei wurde nicht gefunden.', 'openmieterportal'));
        }
        return $h;
    }
    public static function action(string $operation): void
    {
        global $wpdb;
        if (!BPM_Portal::manager()) {
            throw new RuntimeException(esc_html__('Keine Berechtigung.', 'openmieterportal'));
        }
        check_admin_referer('bpm_' . $operation);
        if (in_array($operation, ['unit_update','unit_meter_save','unit_meter_assign','point_save','device_save','device_exchange'], true)) {
            BPM_Units_Admin::action($operation);
            return;
        }
        $hid = absint($_POST['household_id'] ?? 0);
        if ($operation === 'branding') {
            BPM_Settings::save(wp_unslash($_POST));
        } elseif ($operation === 'building') {
            BPM_Portal::insert('buildings', ['reference' => BPM_Portal::text('building_reference', 100), 'label' => BPM_Portal::text('building_label', 190), 'address' => BPM_Portal::text('address', 190)]);
            BPM_Portal::event('building_created', 0, (int)$wpdb->insert_id);
        } elseif ($operation === 'unit') {
            $bid = absint($_POST['building_id'] ?? 0);
            if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id=%d', BPM_Portal::table('buildings'), $bid))) {
                throw new InvalidArgumentException(esc_html__('Bitte ein Gebäude auswählen.', 'openmieterportal'));
            }
            BPM_Portal::insert('units', ['building_id' => $bid, 'reference' => BPM_Portal::text('unit_reference', 100), 'label' => BPM_Portal::text('unit_name', 190)]);
            BPM_Portal::event('unit_created', 0, (int)$wpdb->insert_id);
        } elseif ($operation === 'household') {
            $email = sanitize_email(wp_unslash((string) ($_POST['expected_email'] ?? '')));
            if (!empty($_POST['expected_email']) && !is_email($email)) {
                throw new InvalidArgumentException(esc_html__('Bitte eine gültige E-Mail-Adresse angeben.', 'openmieterportal'));
            }
            $u = BPM_Portal::unit(absint($_POST['unit_id'] ?? 0));
            BPM_Portal::insert('households', ['unit_id' => $u->id, 'reference' => BPM_Portal::text('reference', 100), 'label' => BPM_Portal::text('label', 190), 'unit_label' => $u->address . ' · ' . $u->label, 'expected_email' => $email, 'status' => 'invited', 'created_at' => BPM_Portal::now()]);
            $hid = (int) $wpdb->insert_id;
            BPM_Portal::event('household_created', $hid);
        } elseif ($operation === 'settings') {
            update_option('bpm_consent_enabled', empty($_POST['consent_enabled']) ? 0 : 1);
            BPM_Portal::event('communication_settings_changed');
        } else {
            $h = self::household($hid);
            if ($operation === 'invite') {
                self::invite($h);
                return;
            }
            if ($operation === 'tenancy') {
                $data = BPM_Domain::tenancy_data(wp_unslash($_POST));
                $data['label'] = BPM_Portal::text('label', 190);
                $u = BPM_Portal::unit(absint($_POST['unit_id'] ?? 0));
                if (!empty($h->unit_id) && (int)$h->unit_id !== (int)$u->id) {
                    throw new InvalidArgumentException(esc_html__('Ein Wohnungswechsel benötigt ein neues Mietverhältnis.', 'openmieterportal'));
                }
                $data['unit_id'] = $u->id;
                $data['unit_label'] = $u->address . ' · ' . $u->label;
                if (BPM_Data::update(BPM_Portal::table('households'), $data, ['id' => $hid]) === false) {
                    throw new RuntimeException(esc_html__('Die Profildaten konnten nicht gespeichert werden.', 'openmieterportal'));
                }
                $updated = self::household($hid);
                BPM_Portal::expire_household($updated);
                BPM_Portal::event('tenancy_updated', $hid);
            } elseif ($operation === 'rent') {
                BPM_Rent_Terms::save($h, wp_unslash($_POST));
            } elseif ($operation === 'resend') {
                if ($h->status !== 'pending_email' || !$h->user_id || !BPM_Portal::activation_mail((int) $h->user_id)) {
                    throw new RuntimeException(esc_html__('Die Aktivierungsmail konnte nicht versendet werden. Bitte E-Mail-Konfiguration prüfen.', 'openmieterportal'));
                }
            } elseif ($operation === 'access') {
                $new_status = sanitize_key($_POST['access_status'] ?? '');
                if (!$h->user_id || !in_array($new_status, ['active', 'blocked'], true) || !in_array($h->status, ['active', 'blocked', 'expired'], true)) {
                    throw new InvalidArgumentException(esc_html__('Nur bereits aktivierte Zugänge können hier gesperrt oder wieder freigegeben werden.', 'openmieterportal'));
                }
                if ($new_status === 'active' && BPM_Portal::past_deadline($h)) {
                    throw new InvalidArgumentException(esc_html__('Die Zugangsfrist ist abgelaufen. Bitte zuerst das Mietende prüfen; eine Verlängerung über 25 Monate hinaus ist nicht möglich.', 'openmieterportal'));
                }
                BPM_Data::update(BPM_Portal::table('households'), ['status' => $new_status], ['id' => $hid]);
                if ($new_status === 'blocked') {
                    WP_Session_Tokens::get_instance((int) $h->user_id)->destroy_all();
                }
                BPM_Portal::event('access_' . $new_status, $hid);
            } elseif ($operation === 'document') {
                $title = BPM_Portal::text('title', 160);
                $category = sanitize_key($_POST['category'] ?? '');
                if (!in_array($category, ['vertrag', 'abrechnung', 'mitteilung', 'sonstiges'], true)) {
                    throw new InvalidArgumentException(esc_html__('Bitte eine Dokumentkategorie auswählen.', 'openmieterportal'));
                }
                // Raw upload metadata is validated by is_uploaded_file, finfo, size and image checks in BPM_Storage.
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $file = BPM_Storage::upload($_FILES['document'] ?? [], true);
                try {
                    BPM_Portal::insert('documents', array_merge(['household_id' => $hid, 'title' => $title, 'category' => $category, 'created_at' => BPM_Portal::now()], $file));
                } catch (Throwable $e) {
                    wp_delete_file(BPM_Storage::path($file['storage_key']));
                    throw $e;
                }
                BPM_Portal::event('document_uploaded', $hid, (int) $wpdb->insert_id);
                BPM_Portal::notify($hid, true);
            } elseif ($operation === 'meter') {
                $uid = absint($_POST['unit_id'] ?? 0);
                if ((int)$h->unit_id !== $uid) {
                    throw new InvalidArgumentException(esc_html__('Die Wohnung stimmt nicht mit dem Mietverhältnis überein.', 'openmieterportal'));
                }
                $data = wp_unslash($_POST);
                $data['active'] = 1;
                        BPM_Units_Admin::save_meter($uid, $data);
            } elseif ($operation === 'admin_reply') {
                if (BPM_Tickets::enabled($h)) {
                    throw new InvalidArgumentException(esc_html__('Bitte in der Ticketverwaltung antworten.', 'openmieterportal'));
                }
                $thread = BPM_Portal::owned('threads', absint($_POST['thread_id'] ?? 0), $hid);
                $status = sanitize_key($_POST['status'] ?? '');
                if (!in_array($status, ['offen', 'in_bearbeitung', 'erledigt'], true)) {
                    throw new InvalidArgumentException(esc_html__('Ungültiger Status.', 'openmieterportal'));
                }
                BPM_Portal::insert('messages', ['thread_id' => $thread->id, 'user_id' => get_current_user_id(), 'body' => BPM_Portal::text('body'), 'created_at' => BPM_Portal::now()]);
                BPM_Data::update(BPM_Portal::table('threads'), ['status' => $status, 'updated_at' => BPM_Portal::now()], ['id' => $thread->id]);
                BPM_Portal::event('admin_replied', $hid, (int) $thread->id);
                BPM_Portal::notify($hid, true);
            } elseif ($operation === 'admin_reading') {
                self::save_reading($h, wp_unslash($_POST));
            } elseif ($operation === 'review_reading') {
                $reading = BPM_Portal::owned('readings', absint($_POST['reading_id'] ?? 0), $hid);
                $status = sanitize_key($_POST['status'] ?? '');
                if (!in_array($status, ['bestaetigt', 'rueckfrage'], true)) {
                    throw new InvalidArgumentException(esc_html__('Ungültiger Prüfstatus.', 'openmieterportal'));
                }
                BPM_Data::update(BPM_Portal::table('readings'), ['status' => $status], ['id' => $reading->id]);
                BPM_Portal::event('reading_' . $status, $hid, (int) $reading->id);
            }
        }
        wp_safe_redirect(add_query_arg(['page' => 'belger-mieterportal', 'household_id' => $hid, 'bpm_done' => 1], admin_url('admin.php')));
        exit;
    }
    public static function save_reading(object $h, array $data): int
    {
        global $wpdb;
        if (!BPM_Portal::manager()) {
            throw new RuntimeException(esc_html__('Keine Berechtigung.', 'openmieterportal'));
        }
        $date = BPM_Domain::date((string)($data['read_at'] ?? ''));
        $meter = BPM_Portal::meter_for_reading(absint($data['meter_id'] ?? 0), $h, $date, true);
        $value = BPM_Domain::reading((string)($data['value'] ?? ''));
        $source = sanitize_key($data['source'] ?? '');
        if (!in_array($source, ['verwaltung', 'ista', 'messdienst'], true)) {
            throw new InvalidArgumentException(esc_html__('Bitte eine gültige Quelle auswählen.', 'openmieterportal'));
        }
        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE meter_id=%d AND read_at=%s', BPM_Portal::table('readings'), $meter->id, $date));
        if ($exists) {
            throw new InvalidArgumentException(esc_html__('Für diesen Zähler und Ablesetag ist bereits ein Stand vorhanden. Bitte den vorhandenen Eintrag prüfen.', 'openmieterportal'));
        }
        BPM_Portal::insert('readings', ['household_id' => (int)$h->id, 'meter_id' => (int)$meter->id, 'value' => $value, 'read_at' => $date, 'source' => $source, 'status' => 'bestaetigt', 'photo_key' => '', 'created_at' => BPM_Portal::now()]);
        $id = (int)$wpdb->insert_id;
        BPM_Portal::event('admin_reading_created', (int)$h->id, $id);
        return $id;
    }
    private static function invite(object $h): void
    {
        global $wpdb;
        if (get_post_status((int) get_option('bpm_page_id')) !== 'publish') {
            throw new RuntimeException(esc_html__('Das Portal befindet sich in Vorbereitung. Einladungen sind erst nach Veröffentlichung der geprüften Portalseite möglich.', 'openmieterportal'));
        }
        if ($h->user_id || $h->status !== 'invited') {
            throw new RuntimeException(esc_html__('Diese Mietpartei hat bereits einen Zugang. Ein weiterer Zugang wird nicht erstellt.', 'openmieterportal'));
        }
        $lock = BPM_Domain::token();
        $locked = $wpdb->query($wpdb->prepare('UPDATE %i SET registration_lock=%s WHERE id=%d AND user_id IS NULL AND registration_lock=%s AND status=%s', BPM_Portal::table('households'), $lock, $h->id, '', 'invited'));
        if ($locked !== 1) {
            throw new RuntimeException(esc_html__('Für diese Mietpartei läuft bereits eine Registrierung. Bitte den Vorgang prüfen.', 'openmieterportal'));
        }
        $token = BPM_Domain::invitation_token();
        $expires = gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS);
        try {
            BPM_Data::update(BPM_Portal::table('invites'), ['state' => 'revoked'], ['household_id' => $h->id, 'state' => 'open']);
            BPM_Portal::insert('invites', ['household_id' => $h->id, 'token_hash' => BPM_Domain::token_hash($token), 'expires_at' => $expires, 'state' => 'open', 'created_at' => BPM_Portal::now()]);
            $invite_id = (int) $wpdb->insert_id;
        } finally {
            BPM_Data::update(BPM_Portal::table('households'), ['registration_lock' => ''], ['id' => $h->id, 'registration_lock' => $lock]);
        }
        BPM_Portal::event('invitation_issued', (int) $h->id, $invite_id);
        BPM_Portal::headers();
        $url = BPM_Portal::activation_url() . '#einladung=' . $token;
        wp_enqueue_style('bpm-letter', plugins_url('assets/portal.css', BPM_FILE), [], BPM_VERSION);
        wp_enqueue_script('bpm-qr', plugins_url('assets/qrcode.js', BPM_FILE), [], BPM_VERSION, true);
        wp_enqueue_script('bpm-letter-tools', plugins_url('assets/portal.js', BPM_FILE), ['bpm-qr'], BPM_VERSION, true);
        ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title><?php echo esc_html__('Einladung ins Mieterportal', 'openmieterportal'); ?></title><?php wp_print_styles('bpm-letter'); ?></head><body class="bpm-letter"><main class="bpm-letter-paper"><header class="bpm-letter-brand"><img src="<?php echo  esc_url(BPM_Settings::value('logo')) ?><?php echo esc_html__( '" alt="', 'openmieterportal' ); ?><?php echo  esc_attr(BPM_Settings::value('name')) ?>"></header><div class="bpm-letter-sender"><?php echo  esc_html(BPM_Settings::value('name') . ' · ' . str_replace("\n", ' · ', BPM_Settings::value('address'))) ?></div><address class="bpm-letter-recipient"><strong><?php echo  esc_html($h->label) ?></strong><br><?php echo  nl2br(esc_html($h->unit_label)) ?></address><div class="bpm-letter-date"><?php echo  esc_html(BPM_Settings::value('city')) ?>, <?php echo  esc_html(wp_date('d.m.Y')) ?></div><article class="bpm-letter-content"><h1><?php echo esc_html__('Ihr Zugang zum Mieterportal', 'openmieterportal'); ?></h1><p><?php echo esc_html__('Guten Tag,', 'openmieterportal'); ?></p><p><?php echo esc_html__('mit Ihrem gemeinsamen Mieterzugang können Sie Unterlagen abrufen, Nachrichten an die Verwaltung senden und Zählerstände melden.', 'openmieterportal'); ?></p><div class="bpm-letter-activation"><div><h2><?php echo esc_html__('So aktivieren Sie Ihren Zugang', 'openmieterportal'); ?></h2><ol><li><?php echo esc_html__('Scannen Sie den QR-Code oder öffnen Sie die unten genannte Adresse.', 'openmieterportal'); ?></li><li><?php echo esc_html__('Geben Sie den Einladungscode und die gemeinsame Kontakt-E-Mail-Adresse Ihrer Mietpartei ein.', 'openmieterportal'); ?></li><li><?php echo esc_html__('Öffnen Sie unsere Bestätigungs-E-Mail und legen Sie Ihr Passwort fest.', 'openmieterportal'); ?></li></ol></div><div data-bpm-qr="<?php echo  esc_attr($url) ?><?php echo esc_html__( '" aria-label="', 'openmieterportal' ); ?><?php echo esc_attr__( 'QR-Code zur einmaligen Aktivierung', 'openmieterportal' ); ?>"></div></div><p class="bpm-letter-url"><?php echo  esc_html(BPM_Portal::activation_url()) ?></p><p class="bpm-code"><?php echo  esc_html($token) ?></p><p class="bpm-letter-valid"><?php echo esc_html__( 'Einmalig verwendbar bis', 'openmieterportal' ); ?> <?php echo  esc_html(get_date_from_gmt($expires, 'd.m.Y H:i')) ?> <?php echo esc_html__( 'Uhr. Ein neuer Einladungsbrief ersetzt frühere ungenutzte Codes.', 'openmieterportal' ); ?></p><?php if ($h->expected_email) :
    ?><p><?php echo esc_html__('Bitte verwenden Sie die bereits mit der Verwaltung vereinbarte E-Mail-Adresse.', 'openmieterportal'); ?></p><?php
        endif; ?><p><?php echo esc_html__('Für Ihren Vertrag gibt es einen gemeinsamen Zugang. Stimmen Sie die Nutzung innerhalb Ihrer Mietpartei ab und bewahren Sie den Brief vertraulich auf.', 'openmieterportal'); ?></p><p><?php echo esc_html__('Die elektronische Zustellung vereinbaren Sie anschließend gesondert im Portal. Allein durch die Aktivierung ändern sich Ihre bisherigen Kommunikationswege nicht.', 'openmieterportal'); ?></p><p><?php echo esc_html__('Freundliche Grüße', 'openmieterportal'); ?><br><?php echo  esc_html(BPM_Settings::value('name')) ?></p></article><footer class="bpm-letter-footer"><strong><?php echo  esc_html(BPM_Settings::value('name')) ?></strong><br><?php echo  nl2br(esc_html(BPM_Settings::value('address'))) ?><br><?php echo  esc_html(BPM_Settings::value('phone') . ' · ' . BPM_Settings::value('email')) ?><br><?php echo  esc_html(BPM_Settings::value('website')) ?></footer></main><div class="bpm-no-print bpm-letter-tools"><button type="button" id="bpm-print"><?php echo esc_html__('Brief drucken / als PDF speichern', 'openmieterportal'); ?></button><p><a href="<?php echo  esc_url(admin_url('admin.php?page=belger-mieterportal&household_id=' . (int) $h->id)) ?>"><?php echo esc_html__('Zurück zur Verwaltung', 'openmieterportal'); ?></a></p><p><?php echo esc_html__('Der Code wird nicht im Klartext gespeichert. Drucken oder speichern Sie diesen Brief jetzt sicher. Die Empfängeranschrift vor dem Versand bitte prüfen und gegebenenfalls ergänzen.', 'openmieterportal'); ?></p></div><?php wp_print_scripts(['bpm-qr', 'bpm-letter-tools']); ?></body></html><?php
        exit;
    }
    private static function unit_select(string $id, int $selected = 0): void
    {
        global $wpdb;
        $units = BPM_Data::rows($wpdb->prepare('SELECT u.*, b.address FROM %i u JOIN %i b ON b.id=u.building_id ORDER BY b.address,u.label', BPM_Portal::table('units'), BPM_Portal::table('buildings')));
        echo '<label for="' . esc_attr($id) . ('">' . esc_html__('Wohnung', 'openmieterportal') . '</label><select required id="') . esc_attr($id) . ('" name="unit_id"><option value="">' . esc_html__('Bitte auswählen', 'openmieterportal') . '</option>');
        foreach ($units as $u) {
            echo '<option value="' . (int)$u->id . '" ' . selected($selected, $u->id, false) . '>' . esc_html($u->address . ' · ' . $u->label) . '</option>';
        }
        echo '</select>';
    }
    public static function render(): void
    {
        if (!BPM_Portal::manager()) {
            wp_die(esc_html__('Keine Berechtigung.', 'openmieterportal'));
        }
        global $wpdb;
        $endpoint = admin_url('admin-post.php');
        $search = mb_substr(sanitize_text_field(wp_unslash((string)($_GET['bpm_search'] ?? ''))), 0, 190);
        $building_filter = absint($_GET['bpm_building'] ?? 0);
        $unit_filter = absint($_GET['bpm_unit'] ?? 0);
        $status_filter = sanitize_key($_GET['bpm_status'] ?? '');
        $status_labels = ['invited' => __('Noch nicht registriert', 'openmieterportal'), 'pending_email' => __('E-Mail-Bestätigung offen', 'openmieterportal'), 'active' => __('Aktiv', 'openmieterportal'), 'blocked' => __('Gesperrt', 'openmieterportal'), 'expired' => __('Abgelaufen', 'openmieterportal')];
        if (!isset($status_labels[$status_filter])) {
            $status_filter = '';
        }
        $where = ['1=1'];
        $params = [BPM_Portal::table('households'), BPM_Portal::table('units'), BPM_Portal::table('buildings')];
        if ($search !== '' && BPM_Crypto::configured()) {
            $ids = BPM_Data::search_households($search);
            $ids = $ids ?: [0];
            $where[] = 'h.id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
            array_push($params, ...array_map('intval', $ids));
        } elseif ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(h.label LIKE %s OR h.reference LIKE %s OR h.unit_label LIKE %s OR b.label LIKE %s OR b.address LIKE %s OR u.reference LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($building_filter) {
            $where[] = 'u.building_id=%d';
            $params[] = $building_filter;
        }
        if ($unit_filter) {
            $where[] = 'h.unit_id=%d';
            $params[] = $unit_filter;
        }
        if ($status_filter !== '') {
            $where[] = 'h.status=%s';
            $params[] = $status_filter;
        }
        $from = ' FROM %i h LEFT JOIN %i u ON u.id=h.unit_id LEFT JOIN %i b ON b.id=u.building_id WHERE ' . implode(' AND ', $where);
        $count_sql = 'SELECT COUNT(*)' . $from;
        // All table identifiers, filter values and matched IDs are bound; fragments contain only fixed operators.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = (int)$wpdb->get_var($wpdb->prepare($count_sql, ...$params));
        $per_page = 10;
        $pages = max(1, (int)ceil($total / $per_page));
        $list_page = min($pages, max(1, absint($_GET['bpm_paged'] ?? 1)));
        $list_sql = 'SELECT h.*' . $from . ' ORDER BY h.unit_label, h.label, h.id LIMIT %d OFFSET %d';
        // Same bound filter query, with bound integer pagination; not SQL received from a request.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $households = BPM_Data::rows($wpdb->prepare($list_sql, ...array_merge($params, [$per_page, ($list_page - 1) * $per_page])));
        $list_args = array_filter(['page' => 'belger-mieterportal', 'bpm_search' => $search, 'bpm_building' => $building_filter, 'bpm_unit' => $unit_filter, 'bpm_status' => $status_filter, 'bpm_paged' => $list_page], static fn($v)=>$v !== '' && $v !== 0);
        $hid = absint($_GET['household_id'] ?? 0);
        $selected = $hid ? self::household($hid) : null;
        try {
            BPM_Storage::root();
            $storage = true;
        } catch (Throwable $e) {
            $storage = false;
        }
        $mail_errors = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE kind=\'mail_failed\'', BPM_Portal::table('events')));
        ?><div class="wrap bpm-admin bpm-portal"><h1><?php echo esc_html__('Mieterportal verwalten', 'openmieterportal'); ?></h1><p><?php echo esc_html__( 'Ein Zugang je Mietpartei · Vorbereitete Pilotversion', 'openmieterportal' ); ?> <?php echo  esc_html(BPM_VERSION) ?></p>
        <?php if (get_post_status((int) get_option('bpm_page_id')) !== 'publish') :
            ?><div class="notice notice-info"><p><?php echo esc_html__('Das Portal ist noch privat und nur für die Verwaltung sichtbar. Einladungen bleiben gesperrt. Vor Veröffentlichung müssen die Testkopie, der Dateispeicher und der E-Mail-Versand geprüft sein.', 'openmieterportal'); ?></p></div><?php
        endif; ?>
        <?php if (isset($_GET['bpm_done'])) :
            ?><div class="notice notice-success"><p><?php echo esc_html__('Die Angaben wurden gespeichert.', 'openmieterportal'); ?></p></div><?php
        endif; ?>
        <?php if (!$storage) :
            ?><div class="notice notice-warning"><p><?php echo esc_html__('Der geschützte Dateispeicher ist noch nicht eingerichtet. Dokumentuploads und Fotouploads bleiben gesperrt, bis ein schreibbares Verzeichnis außerhalb des öffentlichen Webverzeichnisses über BPM_PRIVATE_DIR eingerichtet ist.', 'openmieterportal'); ?></p></div><?php
        endif; ?>
        <?php if ($mail_errors) :
            ?><div class="notice notice-warning"><p><?php echo (int) $mail_errors ?> <?php echo esc_html__( 'fehlgeschlagene E-Mail-Versandvorgänge wurden protokolliert. Bitte Zustellung prüfen; ein erfolgreicher Versandaufruf bestätigt noch keinen Eingang beim Empfänger.', 'openmieterportal' ); ?></p></div><?php
        endif; ?>
        <section class="bpm-card"><h2><?php echo esc_html__('Gebäude und Wohnungen', 'openmieterportal'); ?></h2><p><?php echo esc_html__('Wohnungen und Zähler bleiben bestehen. Für jeden Mieterwechsel wird ein neues Mietverhältnis mit eigenem Zugang angelegt.', 'openmieterportal'); ?></p>
        <?php $buildings = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i ORDER BY address', BPM_Portal::table('buildings'))); foreach ($buildings as $b) :
            ?><details><summary><?php echo  esc_html($b->label) ?></summary><ul><?php $units = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE building_id=%d ORDER BY label', BPM_Portal::table('units'), $b->id)); foreach ($units as $u) :
    ?><li><strong><?php echo  esc_html($u->label) ?></strong> · <?php echo  esc_html($u->reference) ?> · <a href="<?php echo  esc_url(BPM_Units_Admin::url((int)$u->id)) ?>"><?php echo esc_html__('Wohnung und Zähler bearbeiten', 'openmieterportal'); ?></a> · <a href="<?php echo  esc_url(add_query_arg(['page' => 'belger-mieterportal','bpm_building' => $b->id,'bpm_unit' => $u->id], admin_url('admin.php'))) ?>"><?php echo esc_html__('Mietverhältnisse dieser Wohnung anzeigen', 'openmieterportal'); ?></a></li><?php
            endforeach; ?></ul></details><?php
        endforeach; ?>
        <div class="bpm-columns"><form method="post" action="<?php echo  esc_url($endpoint) ?>"><h3><?php echo esc_html__('Gebäude anlegen', 'openmieterportal'); ?></h3><?php BPM_Portal::form('building'); ?><label for="bpm-building-ref"><?php echo esc_html__('Gebäudenummer', 'openmieterportal'); ?></label><input id="bpm-building-ref" name="building_reference" maxlength="100" required><label for="bpm-building-name"><?php echo esc_html__('Gebäudebezeichnung', 'openmieterportal'); ?></label><input id="bpm-building-name" name="building_label" maxlength="190" required><label for="bpm-building-address"><?php echo esc_html__('Anschrift', 'openmieterportal'); ?></label><input id="bpm-building-address" name="address" maxlength="190" required><button><?php echo esc_html__('Gebäude speichern', 'openmieterportal'); ?></button></form>
        <form method="post" action="<?php echo  esc_url($endpoint) ?>"><h3><?php echo esc_html__('Wohnung anlegen', 'openmieterportal'); ?></h3><?php BPM_Portal::form('unit'); ?><label for="bpm-building-choice"><?php echo esc_html__('Gebäude', 'openmieterportal'); ?></label><select id="bpm-building-choice" name="building_id" required><option value=""><?php echo esc_html__('Bitte auswählen', 'openmieterportal'); ?></option><?php foreach ($buildings as $b) :
            ?><option value="<?php echo  (int)$b->id ?>"><?php echo  esc_html($b->label) ?></option><?php
                                    endforeach; ?></select><label for="bpm-unit-ref"><?php echo esc_html__('Wohnungsnummer', 'openmieterportal'); ?></label><input id="bpm-unit-ref" name="unit_reference" maxlength="100" required><label for="bpm-unit-name"><?php echo esc_html__('Wohnungsbezeichnung', 'openmieterportal'); ?></label><input id="bpm-unit-name" name="unit_name" maxlength="190" required><button><?php echo esc_html__('Wohnung speichern', 'openmieterportal'); ?></button></form></div></section>
        <div class="bpm-columns"><section class="bpm-card"><h2><?php echo esc_html__('Mietparteien', 'openmieterportal'); ?></h2><p><?php echo esc_html__('Wählen Sie die Mietpartei, für die Sie Dokumente oder Nachrichten bereitstellen möchten.', 'openmieterportal'); ?></p>
        <form method="get" action="<?php echo  esc_url(admin_url('admin.php')) ?>" class="bpm-household-filter"><input type="hidden" name="page" value="belger-mieterportal"><?php if ($hid) :
            ?><input type="hidden" name="household_id" value="<?php echo (int) $hid ?>"><?php
                                   endif; ?>
        <label for="bpm-search"><?php echo esc_html__('Freitextsuche', 'openmieterportal'); ?></label><input type="search" id="bpm-search" name="bpm_search" maxlength="190" value="<?php echo  esc_attr($search) ?><?php echo esc_html__( '" placeholder="', 'openmieterportal' ); ?><?php echo esc_attr__( 'Name, Vertragsnummer, Wohnung oder Anschrift', 'openmieterportal' ); ?>">
        <label for="bpm-filter-building"><?php echo esc_html__('Gebäude filtern', 'openmieterportal'); ?></label><select id="bpm-filter-building" name="bpm_building"><option value="0"><?php echo esc_html__('Alle Gebäude', 'openmieterportal'); ?></option><?php foreach ($buildings as $b) :
            ?><option value="<?php echo  (int)$b->id ?>" <?php echo  selected($building_filter, $b->id, false) ?>><?php echo  esc_html($b->label) ?></option><?php
                                         endforeach; ?></select>
        <label for="bpm-filter-unit"><?php echo esc_html__('Wohnung filtern', 'openmieterportal'); ?></label><select id="bpm-filter-unit" name="bpm_unit"><option value="0"><?php echo esc_html__('Alle Wohnungen', 'openmieterportal'); ?></option><?php $filter_units = BPM_Data::rows($wpdb->prepare('SELECT u.*,b.address FROM %i u JOIN %i b ON b.id=u.building_id ORDER BY b.address,u.label', BPM_Portal::table('units'), BPM_Portal::table('buildings'))); foreach ($filter_units as $u) :
            ?><option value="<?php echo  (int)$u->id ?>" <?php echo  selected($unit_filter, $u->id, false) ?>><?php echo  esc_html($u->address . ' · ' . $u->label) ?></option><?php
                                     endforeach; ?></select>
        <label for="bpm-filter-status"><?php echo esc_html__('Zugangsstatus filtern', 'openmieterportal'); ?></label><select id="bpm-filter-status" name="bpm_status"><option value=""><?php echo esc_html__('Alle Zugangsstatus', 'openmieterportal'); ?></option><?php foreach ($status_labels as $key => $label) :
            ?><option value="<?php echo  esc_attr($key) ?>" <?php echo  selected($status_filter, $key, false) ?>><?php echo  esc_html($label) ?></option><?php
                                       endforeach; ?></select><p><button type="submit"><?php echo esc_html__('Mietparteien filtern', 'openmieterportal'); ?></button> <a href="<?php echo  esc_url(add_query_arg(['page' => 'belger-mieterportal'], admin_url('admin.php'))) ?>"><?php echo esc_html__('Filter zurücksetzen', 'openmieterportal'); ?></a></p></form>
        <p class="bpm-list-count" role="status"><?php echo esc_html($total ? (($list_page - 1) * $per_page + 1) . '–' . min($list_page * $per_page, $total) . ' von ' . $total . esc_html__(' Mietparteien', 'openmieterportal') : esc_html__('Keine Mietparteien für diese Filter gefunden.', 'openmieterportal')) ?></p><ul class="bpm-households"><?php foreach ($households as $h) :
            ?><li><a href="<?php echo  esc_url(add_query_arg(array_merge($list_args, ['household_id' => $h->id]), admin_url('admin.php'))) ?>"><?php echo  esc_html($h->label) ?></a><small><?php echo  esc_html($h->unit_label . ' · ' . $h->reference . ' · ' . $h->status) ?></small></li><?php
                                                endforeach; ?></ul>
        <?php if ($pages > 1) :
            ?><nav class="bpm-pagination" aria-label="<?php echo esc_attr__('Mietparteien blättern', 'openmieterportal'); ?>"><?php if ($list_page > 1) :
    ?><a class="bpm-button" rel="prev" href="<?php echo  esc_url(add_query_arg(array_merge($list_args, ['bpm_paged' => $list_page - 1,'household_id' => $hid ?: false]), admin_url('admin.php'))) ?>"><?php echo esc_html__('Zurück', 'openmieterportal'); ?></a><?php
            endif; ?><span><?php echo esc_html__( 'Seite', 'openmieterportal' ); ?> <?php echo (int) $list_page ?> <?php echo esc_html__( 'von', 'openmieterportal' ); ?> <?php echo (int) $pages ?></span><?php if ($list_page < $pages) :
    ?><a class="bpm-button" rel="next" href="<?php echo  esc_url(add_query_arg(array_merge($list_args, ['bpm_paged' => $list_page + 1,'household_id' => $hid ?: false]), admin_url('admin.php'))) ?>"><?php echo esc_html__('Weiter', 'openmieterportal'); ?></a><?php
            endif; ?></nav><?php
        endif; ?></section>
        <section class="bpm-card"><h2><?php echo esc_html__('Mietpartei anlegen', 'openmieterportal'); ?></h2><form method="post" action="<?php echo  esc_url($endpoint) ?>"><?php BPM_Portal::form('household'); ?><label for="bpm-reference"><?php echo esc_html__('Eindeutige Vertrags-/Mietparteiennummer', 'openmieterportal'); ?></label><input id="bpm-reference" name="reference" maxlength="100" required><label for="bpm-label"><?php echo esc_html__('Bezeichnung der Mietpartei', 'openmieterportal'); ?></label><input id="bpm-label" name="label" maxlength="190" required><?php self::unit_select('bpm-unit'); ?><label for="bpm-expected"><?php echo esc_html__('Vereinbarte Kontakt-E-Mail (optional)', 'openmieterportal'); ?></label><input id="bpm-expected" name="expected_email" type="email" maxlength="190"><p class="bpm-small"><?php echo esc_html__('Wenn hinterlegt, kann nur diese E-Mail-Adresse die Einladung einlösen. Ohne Vorgabe legitimiert der vertrauliche Brief die erstmalige Registrierung.', 'openmieterportal'); ?></p><button type="submit"><?php echo esc_html__('Mietpartei anlegen', 'openmieterportal'); ?></button></form></section></div>
        <?php if ($selected) :
            $h = $selected; ?>
        <section class="bpm-card"><h2><?php echo  esc_html($h->label) ?></h2><p><?php echo  esc_html($h->unit_label) ?> · <?php echo  esc_html($h->reference) ?></p><p><strong><?php echo esc_html__('Zugang:', 'openmieterportal'); ?></strong> <?php echo  esc_html($h->status) ?></p>
            <?php if (!$h->user_id) :
                ?><form method="post" action="<?php echo  esc_url($endpoint) ?>" target="_blank"><?php BPM_Portal::form('invite'); ?><input type="hidden" name="household_id" value="<?php echo  (int) $h->id ?>"><button type="submit"><?php echo esc_html__('Einladungsbrief mit QR-Code erstellen', 'openmieterportal'); ?></button></form><p class="bpm-small"><?php echo esc_html__('30 Tage gültig. Frühere ungenutzte Einladungen werden ungültig.', 'openmieterportal'); ?></p>
            <?php elseif ($h->status === 'pending_email') :
                ?><form method="post" action="<?php echo  esc_url($endpoint) ?>"><?php BPM_Portal::form('resend'); ?><input type="hidden" name="household_id" value="<?php echo  (int) $h->id ?>"><button type="submit"><?php echo esc_html__('Aktivierungsmail erneut senden', 'openmieterportal'); ?></button></form>
            <?php else :
                ?><p><?php echo esc_html__('Ein Zugang ist zugeordnet. Es wird kein weiterer Zugang für diesen Vertrag erstellt.', 'openmieterportal'); ?></p><form method="post" action="<?php echo  esc_url($endpoint) ?>"><?php BPM_Portal::form('access'); ?><input type="hidden" name="household_id" value="<?php echo  (int) $h->id ?>"><input type="hidden" name="access_status" value="<?php echo  in_array($h->status, ['blocked', 'expired'], true) ? 'active' : 'blocked' ?>"><button type="submit"><?php echo  in_array($h->status, ['blocked', 'expired'], true) ? esc_html__('Zugang wieder freigeben', 'openmieterportal') : esc_html__('Mieterzugang sperren', 'openmieterportal') ?></button></form><?php
            endif; ?>
        </section>
        <section class="bpm-card"><h2><?php echo esc_html__('Mieterprofil und Kündigung', 'openmieterportal'); ?></h2><p><?php echo esc_html__('Die Angaben werden der Mietpartei im Profil angezeigt. Das Erfassen einer Kündigung versendet keine Erklärung.', 'openmieterportal'); ?></p>
        <form method="post" action="<?php echo  esc_url($endpoint) ?>"><?php BPM_Portal::form('tenancy'); ?><input type="hidden" name="household_id" value="<?php echo  (int) $h->id ?>">
        <label for="bpm-profile-label"><?php echo esc_html__('Mietpartei', 'openmieterportal'); ?></label><input id="bpm-profile-label" name="label" maxlength="190" required value="<?php echo  esc_attr($h->label) ?>">
            <?php self::unit_select('bpm-profile-unit', (int)($h->unit_id ?? 0)); ?><p class="bpm-small"><?php echo esc_html__('Bei einem Mieterwechsel ein neues Mietverhältnis mit dieser Wohnung anlegen. Der bisherige Vertrag und seine Unterlagen bleiben erhalten.', 'openmieterportal'); ?></p>
        <div class="bpm-columns"><div><label for="bpm-tenancy-start"><?php echo esc_html__('Mietbeginn', 'openmieterportal'); ?></label><input type="date" id="bpm-tenancy-start" name="tenancy_start" value="<?php echo  esc_attr($h->tenancy_start ?? '') ?>"></div><div><label for="bpm-tenancy-end"><?php echo esc_html__('Mietende', 'openmieterportal'); ?></label><input type="date" id="bpm-tenancy-end" name="tenancy_end" value="<?php echo  esc_attr($h->tenancy_end ?? '') ?>"></div></div>
        <label for="bpm-notice-received"><?php echo esc_html__('Kündigung / Vereinbarung eingegangen am', 'openmieterportal'); ?></label><input type="date" id="bpm-notice-received" name="notice_received" value="<?php echo  esc_attr($h->notice_received ?? '') ?>">
        <label for="bpm-termination-by"><?php echo esc_html__('Beendigungsart', 'openmieterportal'); ?></label><select id="bpm-termination-by" name="termination_by"><?php foreach (['' => __('Keine Kündigung erfasst', 'openmieterportal'), 'mieter' => __('Kündigung durch Mietpartei', 'openmieterportal'), 'vermieter' => 'Kündigung durch Verwaltung / Vermieter', 'aufhebung' => __('Aufhebungsvereinbarung', 'openmieterportal')] as $key => $caption) :
            ?><option value="<?php echo  esc_attr($key) ?>" <?php echo  selected($h->termination_by ?? '', $key, false) ?>><?php echo  esc_html($caption) ?></option><?php
                                        endforeach; ?></select>
            <?php $deadline = BPM_Domain::access_deadline($h->tenancy_end ?? null); ?><p><strong><?php echo esc_html__('Portalzugang längstens bis:', 'openmieterportal'); ?></strong> <?php echo  $deadline ? esc_html(mysql2date('d.m.Y', $deadline)) . ' einschließlich (Europe/Berlin)' : esc_html__('Noch kein Mietende hinterlegt', 'openmieterportal') ?></p><p class="bpm-small"><?php echo esc_html__('25 Kalendermonate nach Mietende. Danach werden auch bestehende Sitzungen gesperrt. Eine frühere manuelle Sperre bleibt möglich. Dokumente werden durch die Zugangssperre nicht gelöscht.', 'openmieterportal'); ?></p><button type="submit"><?php echo esc_html__('Profil und Kündigung speichern', 'openmieterportal'); ?></button></form></section>
            <?php require __DIR__ . '/view-rent-admin.php'; ?>
            <?php BPM_Account::admin_card($h); ?><section class="bpm-card"><h2><?php echo esc_html__('Dokument bereitstellen', 'openmieterportal'); ?></h2><p><?php echo esc_html__('Wählen Sie eine PDF-Datei aus und stellen Sie diese der ausgewählten Mietpartei bereit. Bereitgestellte PDFs werden nicht überschrieben; Korrekturen stellen Sie als neues Dokument mit einem eindeutigen Titel bereit. Vor jedem Abruf werden Dateigröße und SHA-256-Prüfsumme geprüft. Unter jedem bereitgestellten Dokument sehen Sie „Durch Mietpartei abgerufen“ mit Datum, Uhrzeit und Konto-ID oder „Noch nicht durch die Mietpartei abgerufen“. Verwaltungsabrufe zählen nicht.', 'openmieterportal'); ?></p><form method="post" action="<?php echo  esc_url($endpoint) ?>" enctype="multipart/form-data"><?php BPM_Portal::form('document'); ?><input type="hidden" name="household_id" value="<?php echo  (int) $h->id ?>"><label for="bpm-doctitle"><?php echo esc_html__('Titel', 'openmieterportal'); ?></label><input id="bpm-doctitle" name="title" maxlength="160" required><label for="bpm-category"><?php echo esc_html__('Kategorie', 'openmieterportal'); ?></label><select id="bpm-category" name="category"><option value="vertrag"><?php echo esc_html__('Vertrag', 'openmieterportal'); ?></option><option value="abrechnung"><?php echo esc_html__('Abrechnung', 'openmieterportal'); ?></option><option value="mitteilung"><?php echo esc_html__('Mitteilung', 'openmieterportal'); ?></option><option value="sonstiges"><?php echo esc_html__('Sonstiges', 'openmieterportal'); ?></option></select><label for="bpm-docfile"><?php echo esc_html__('PDF-Datei (maximal 15 MB)', 'openmieterportal'); ?></label><input id="bpm-docfile" name="document" type="file" accept="application/pdf" required><p class="bpm-small"><?php echo esc_html__( 'Empfänger: ausschließlich', 'openmieterportal' ); ?> <?php echo  esc_html($h->label . ' · ' . $h->unit_label) ?> <?php echo esc_html__( 'und berechtigte Verwalter.', 'openmieterportal' ); ?></p><button type="submit" <?php echo  $storage ? '' : 'disabled' ?>><?php echo esc_html__('PDF bereitstellen', 'openmieterportal'); ?></button></form>
            <?php $docs = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE household_id=%d ORDER BY id DESC LIMIT 100', BPM_Portal::table('documents'), $h->id)); ?><ul class="bpm-filelist"><?php foreach ($docs as $doc) :
                ?><li><div><strong><?php echo  esc_html($doc->title) ?></strong><small><?php echo  esc_html($doc->category . ' · ' . $doc->filename) ?></small><small><?php echo  !empty($doc->first_download_at) ? esc_html__('Durch Mietpartei abgerufen: ', 'openmieterportal') . esc_html(get_date_from_gmt($doc->first_download_at, 'd.m.Y H:i:s')) . ' · Konto-ID ' . (int) $doc->first_download_user_id : esc_html__('Noch nicht durch die Mietpartei abgerufen', 'openmieterportal') ?></small></div><a href="<?php echo  esc_url(BPM_Portal::download_url((int) $doc->id)) ?>"><?php echo esc_html__('PDF prüfen', 'openmieterportal'); ?></a></li><?php
            endforeach; ?></ul></section>
        <section class="bpm-card"><h2><?php echo esc_html__('Messstellen und Zählergeräte der Wohnung', 'openmieterportal'); ?></h2><p><?php echo esc_html__('Messstellen, Geräte und Gültigkeitszeiträume verwalten Sie direkt an der Wohnung.', 'openmieterportal'); ?></p><a class="bpm-button" href="<?php echo  esc_url(BPM_Units_Admin::url((int)$h->unit_id)) ?>"><?php echo esc_html__('Messstellen und Geräte bearbeiten', 'openmieterportal'); ?></a><?php $meters = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE unit_id=%d ORDER BY label,number', BPM_Portal::table('meters'), (int)$h->unit_id)); ?></section>
        <section class="bpm-card" id="bpm-admin-reading"><h2><?php echo esc_html__('Zählerstand eintragen', 'openmieterportal'); ?></h2><p><?php echo esc_html__('Für Ablesungen der Verwaltung oder eines Messdienstes. Datum und Quelle werden gespeichert; der Stand bleibt dem Wohnungszähler zugeordnet.', 'openmieterportal'); ?></p><form method="post" action="<?php echo  esc_url($endpoint) ?>"><?php BPM_Portal::form('admin_reading'); ?><input type="hidden" name="household_id" value="<?php echo  (int)$h->id ?>"><label for="bpm-admin-meter"><?php echo esc_html__('Zähler', 'openmieterportal'); ?></label><select id="bpm-admin-meter" name="meter_id" required><option value=""><?php echo esc_html__('Bitte auswählen', 'openmieterportal'); ?></option><?php foreach ($meters as $m) :
            ?><option value="<?php echo  (int)$m->id ?>"><?php echo  esc_html($m->label . ' · ' . $m->number . ' · ' . $m->unit) ?></option><?php
                                                             endforeach; ?></select><label for="bpm-admin-read-at"><?php echo esc_html__('Ablesedatum', 'openmieterportal'); ?></label><input type="date" id="bpm-admin-read-at" name="read_at" max="<?php echo  esc_attr(BPM_Portal::today()) ?>" required><label for="bpm-admin-value"><?php echo esc_html__('Zählerstand (ohne Tausenderpunkte)', 'openmieterportal'); ?></label><input id="bpm-admin-value" name="value" inputmode="decimal" placeholder="<?php echo esc_attr__( 'z. B. 1234,567', 'openmieterportal' ); ?>" required><label for="bpm-admin-source"><?php echo esc_html__('Quelle', 'openmieterportal'); ?></label><select id="bpm-admin-source" name="source"><option value="verwaltung"><?php echo esc_html__('Eigene Ablesung / Verwaltung', 'openmieterportal'); ?></option><option value="ista"><?php echo esc_html__('ista', 'openmieterportal'); ?></option><option value="messdienst"><?php echo esc_html__('Anderer Messdienst', 'openmieterportal'); ?></option></select><p class="bpm-small"><?php echo esc_html__('Verwaltungseinträge werden als bestätigt gespeichert. Es erfolgt keine automatische Übernahme in WISO.', 'openmieterportal'); ?></p><button type="submit"><?php echo esc_html__('Zählerstand speichern', 'openmieterportal'); ?></button></form></section>
        <section class="bpm-card"><h2><?php echo esc_html__('Zählerstandsmeldungen prüfen', 'openmieterportal'); ?></h2><?php $readings = BPM_Data::rows($wpdb->prepare('SELECT r.*, m.label, m.number, m.unit FROM %i r JOIN %i m ON m.id=r.meter_id WHERE r.household_id=%d ORDER BY r.id DESC LIMIT 100', BPM_Portal::table('readings'), BPM_Portal::table('meters'), $h->id)); foreach ($readings as $r) :
            ?><details class="bpm-thread"><summary><?php echo  esc_html($r->label . ' · ' . $r->number . ' · ' . BPM_Domain::reading_display((string)$r->value) . ' ' . $r->unit . ' · ' . $r->read_at . ' · Quelle: ' . $r->source . ' · ' . $r->status) ?></summary><?php if ($r->photo_key) :
    ?><p><a href="<?php echo  esc_url(BPM_Portal::download_url((int) $r->id, true)) ?>"><?php echo esc_html__('Zählerfoto herunterladen', 'openmieterportal'); ?></a></p><?php
            endif; ?><form method="post" action="<?php echo  esc_url($endpoint) ?>"><?php BPM_Portal::form('review_reading'); ?><input type="hidden" name="household_id" value="<?php echo  (int) $h->id ?>"><input type="hidden" name="reading_id" value="<?php echo  (int) $r->id ?>"><label for="bpm-review-<?php echo  (int) $r->id ?>"><?php echo esc_html__('Prüfung', 'openmieterportal'); ?></label><select name="status" id="bpm-review-<?php echo  (int) $r->id ?>"><option value="bestaetigt"><?php echo esc_html__('Bestätigt', 'openmieterportal'); ?></option><option value="rueckfrage"><?php echo esc_html__('Rückfrage erforderlich', 'openmieterportal'); ?></option></select><button type="submit"><?php echo esc_html__('Prüfstatus speichern', 'openmieterportal'); ?></button></form><p class="bpm-small"><?php echo esc_html__('Die Prüfung überträgt keine Daten automatisch in WISO oder eine Abrechnung.', 'openmieterportal'); ?></p></details><?php
                                      endforeach; ?></section>
            <?php if (BPM_Tickets::enabled($h)) :
                BPM_Tickets::admin_list($h);
            else : ?>
        <section class="bpm-card"><h2><?php echo esc_html__('Nachrichten beantworten', 'openmieterportal'); ?></h2><?php $threads = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE household_id=%d ORDER BY updated_at DESC, id DESC LIMIT 50', BPM_Portal::table('threads'), $h->id)); foreach ($threads as $t) :
            ?><details class="bpm-thread"><summary><?php echo  esc_html($t->subject . ' · ' . $t->kind . ' · ' . $t->status) ?></summary><?php $messages = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE thread_id=%d ORDER BY id LIMIT 100', BPM_Portal::table('messages'), $t->id)); foreach ($messages as $m) :
    ?><article class="bpm-message"><p class="bpm-small"><?php echo  (int) $m->user_id === (int) $h->user_id ? esc_html__('Mietpartei', 'openmieterportal') : esc_html__('Verwaltung', 'openmieterportal') ?> · <?php echo  esc_html(get_date_from_gmt($m->created_at, 'd.m.Y H:i')) ?></p><p><?php echo  nl2br(esc_html($m->body)) ?></p></article><?php
            endforeach; ?><form method="post" action="<?php echo  esc_url($endpoint) ?>"><?php BPM_Portal::form('admin_reply'); ?><input type="hidden" name="household_id" value="<?php echo  (int) $h->id ?>"><input type="hidden" name="thread_id" value="<?php echo  (int) $t->id ?>"><label for="bpm-answer-<?php echo  (int) $t->id ?>"><?php echo esc_html__('Antwort', 'openmieterportal'); ?></label><textarea id="bpm-answer-<?php echo  (int) $t->id ?>" name="body" rows="4" maxlength="5000" required></textarea><label for="bpm-state-<?php echo  (int) $t->id ?>"><?php echo esc_html__('Status', 'openmieterportal'); ?></label><select id="bpm-state-<?php echo  (int) $t->id ?>" name="status"><option value="in_bearbeitung"><?php echo esc_html__('In Bearbeitung', 'openmieterportal'); ?></option><option value="offen"><?php echo esc_html__('Offen', 'openmieterportal'); ?></option><option value="erledigt"><?php echo esc_html__('Erledigt', 'openmieterportal'); ?></option></select><button type="submit"><?php echo esc_html__('Antwort senden', 'openmieterportal'); ?></button></form></details><?php
                                      endforeach; ?></section>
            <?php endif; ?>
        <section class="bpm-card"><h2><?php echo esc_html__('Dokumentierte Kommunikationswahl', 'openmieterportal'); ?></h2><?php $prefs = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE household_id=%d ORDER BY id DESC LIMIT 30', BPM_Portal::table('preferences'), $h->id)); if (!$prefs) :
            ?><p><?php echo esc_html__('Noch keine Erklärung abgegeben.', 'openmieterportal'); ?></p><?php
                                      endif; foreach ($prefs as $p) :
                                            ?><details><summary><?php echo  esc_html($p->choice . ' · ' . get_date_from_gmt($p->created_at, 'd.m.Y H:i') . ' · ' . $p->version) ?></summary><p><?php echo  esc_html($p->wording) ?></p><p><?php echo esc_html__( 'Vertretungsberechtigung bestätigt:', 'openmieterportal' ); ?> <?php echo  (int) $p->authority_confirmed ? esc_html__('Ja', 'openmieterportal') : 'Nein / Widerruf' ?></p></details><?php
                                      endforeach; ?></section>
        <?php endif; ?>
        <section class="bpm-card"><h2><?php echo esc_html__('Freigabe der Kommunikationsvereinbarung', 'openmieterportal'); ?></h2><p><?php echo esc_html__('Die Textfassung ist ein Entwurf. Vor einer Freigabe müssen Wortlaut, Vertretung der Mietpartei, Zustellung und alternative Kommunikationswege rechtlich und organisatorisch abgestimmt sein.', 'openmieterportal'); ?></p><details><summary><?php echo esc_html__('Entwurf lesen', 'openmieterportal'); ?></summary><p><?php echo  esc_html(BPM_Settings::consent_text()) ?></p><p><?php echo esc_html__( 'Fassung:', 'openmieterportal' ); ?> <?php echo  esc_html(BPM_Settings::consent_version()) ?></p></details><form method="post" action="<?php echo  esc_url($endpoint) ?>"><?php BPM_Portal::form('settings'); ?><label class="bpm-check"><input type="checkbox" name="consent_enabled" value="1" <?php echo  checked((bool) get_option('bpm_consent_enabled'), true, false) ?>> <?php echo esc_html__('Der Text und die Abläufe sind abgestimmt. Die freiwillige Wahl darf im Portal angeboten werden.', 'openmieterportal'); ?></label><button type="submit"><?php echo esc_html__('Freigabe speichern', 'openmieterportal'); ?></button></form></section>
        <?php BPM_Settings::render(); ?>
        </div><?php
    }
}
