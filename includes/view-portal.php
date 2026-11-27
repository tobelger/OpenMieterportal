<?php
defined('ABSPATH') || exit;
$h = BPM_Portal::household();
$endpoint = admin_url('admin-post.php');
?>
<div class="bpm-portal">
  <?php if ($show_header ?? true) :
        ?><header class="bpm-head"><p class="bpm-eyebrow"><?php echo  esc_html(BPM_Settings::value('name')) ?></p><h2><?php echo esc_html__('Ihr Mieterportal', 'openmieterportal'); ?></h2><p><?php echo esc_html__('Ein gemeinsamer Zugang. Alles rund um Ihr Mietverhältnis.', 'openmieterportal'); ?></p></header><?php
  endif; ?>
<?php if (!is_user_logged_in()) : ?>
  <div class="bpm-columns">
    <?php if (!BPM_Portal::is_activation()) :
        ?><section class="bpm-card"><h3><?php echo esc_html__('Willkommen zurück', 'openmieterportal'); ?></h3><p><?php echo esc_html__('Melden Sie sich mit dem gemeinsamen Zugang Ihrer Mietpartei an.', 'openmieterportal'); ?></p>
        <?php wp_login_form(['redirect' => BPM_Portal::url(), 'label_username' => __('E-Mail-Adresse oder Benutzername', 'openmieterportal'), 'label_password' => __('Passwort', 'openmieterportal'), 'label_remember' => __('Angemeldet bleiben', 'openmieterportal'), 'label_log_in' => __('Anmelden', 'openmieterportal')]); ?>
      <p><a href="<?php echo  esc_url(wp_lostpassword_url(BPM_Portal::url())) ?>"><?php echo esc_html__('Passwort vergessen?', 'openmieterportal'); ?></a></p>
    </section>
    <?php endif; if (BPM_Portal::is_activation()) :
        ?><section class="bpm-card" id="bpm-activation"><h3><?php echo esc_html__('Einladung erhalten?', 'openmieterportal'); ?></h3><p><?php echo esc_html__('Scannen Sie den QR-Code auf Ihrem Brief oder geben Sie den Einladungscode ein. Pro Mietpartei wird genau ein Zugang eingerichtet.', 'openmieterportal'); ?></p>
      <form action="<?php echo  esc_url($endpoint) ?>" method="post" autocomplete="off"><?php BPM_Portal::form('activate'); ?>
        <label for="bpm-code"><?php echo esc_html__('Einladungscode', 'openmieterportal'); ?></label><input id="bpm-code" name="invite_code" required minlength="16" maxlength="64" spellcheck="false" autocapitalize="characters" autocomplete="off">
        <label for="bpm-email"><?php echo esc_html__('Gemeinsame Kontakt-E-Mail-Adresse', 'openmieterportal'); ?></label><input id="bpm-email" name="email" type="email" required maxlength="190" autocomplete="email">
        <p class="bpm-small"><?php echo esc_html__('An diese Adresse senden wir einen Link zur Bestätigung und zum Festlegen Ihres Passworts. Ihre Entscheidung zur digitalen Kommunikation treffen Sie anschließend separat im Portal.', 'openmieterportal'); ?></p>
        <?php if (get_privacy_policy_url()) : ?><p class="bpm-small"><?php echo esc_html__('Informationen zur Datenverarbeitung finden Sie in der', 'openmieterportal'); ?> <a href="<?php echo esc_url(get_privacy_policy_url()); ?>"><?php echo esc_html__('Datenschutzerklärung', 'openmieterportal'); ?></a>.</p><?php else : ?><p class="bpm-small"><?php echo esc_html__('Informationen zur Datenverarbeitung erhalten Sie von Ihrer Verwaltung.', 'openmieterportal'); ?></p><?php endif; ?>
        <button type="submit"><?php echo esc_html__('Aktivierungslink anfordern', 'openmieterportal'); ?></button>
      </form>
    </section>
    <?php else :
        ?><section class="bpm-card"><h3><?php echo esc_html__('Einladung erhalten?', 'openmieterportal'); ?></h3><p><?php echo esc_html__('Aktivieren Sie den gemeinsamen Zugang mit dem Code aus Ihrem Einladungsbrief.', 'openmieterportal'); ?></p><a class="bpm-button" href="<?php echo  esc_url(BPM_Portal::activation_url()) ?>"><?php echo esc_html__('Zugang aktivieren', 'openmieterportal'); ?></a></section><?php
    endif; ?>
  </div>
<?php elseif (!BPM_Portal::can_access($h)) : ?>
  <section class="bpm-card"><h3><?php echo  BPM_Portal::manager() ? esc_html__('Verwaltungszugang', 'openmieterportal') : esc_html__('Zugang nicht verfügbar', 'openmieterportal') ?></h3>
    <p><?php echo  BPM_Portal::manager() ? 'Mietparteien, Einladungen und Inhalte verwalten Sie im WordPress-Verwaltungsbereich. Ihr Verwalterkonto wird keiner Mietpartei zugeordnet.' : esc_html__('Für dieses Konto ist der Mieterzugang noch nicht aktiviert, gesperrt oder die Zugangsfrist ist abgelaufen. Bitte verwenden Sie den Bestätigungslink aus Ihrer E-Mail oder wenden Sie sich an die Verwaltung.', 'openmieterportal') ?></p>
    <?php if (BPM_Portal::manager()) :
        ?><a class="bpm-button" href="<?php echo  esc_url(admin_url('admin.php?page=belger-mieterportal')) ?>"><?php echo esc_html__('Portal verwalten', 'openmieterportal'); ?></a><?php
    endif; ?>
    <p><a href="<?php echo  esc_url(wp_logout_url(BPM_Portal::url())) ?>"><?php echo esc_html__('Abmelden', 'openmieterportal'); ?></a></p>
  </section>
<?php else :
    $hid = (int) $h->id;
    $documents = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE household_id=%d ORDER BY created_at DESC, id DESC LIMIT 100', BPM_Portal::table('documents'), $hid));
    $meters = BPM_Portal::meters_for($h);
    $readings = BPM_Data::rows($wpdb->prepare('SELECT r.*, m.number, m.label, m.unit FROM %i r JOIN %i m ON m.id=r.meter_id WHERE r.household_id=%d ORDER BY r.read_at DESC, r.id DESC LIMIT 50', BPM_Portal::table('readings'), BPM_Portal::table('meters'), $hid));
    $threads = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE household_id=%d ORDER BY updated_at DESC, id DESC LIMIT 30', BPM_Portal::table('threads'), $hid));
    $preference = BPM_Data::row($wpdb->prepare('SELECT * FROM %i WHERE household_id=%d ORDER BY id DESC LIMIT 1', BPM_Portal::table('preferences'), $hid));
    $needs_communication = (bool) get_option('bpm_consent_enabled') && (!$preference || $preference->choice !== 'portal' || $preference->version !== BPM_Settings::consent_version());
    ?>
    <?php if ($needs_communication) :
        require __DIR__ . '/view-communication.php';
    endif; ?>
  <div class="bpm-account"><div><strong><?php echo  esc_html($h->label) ?></strong><span><?php echo  esc_html($h->unit_label) ?></span></div><details class="bpm-user-menu"><summary aria-label="<?php echo esc_attr__('Benutzerkonto', 'openmieterportal'); ?>"><svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="7" r="4"/><path d="M4 22v-2a8 8 0 0 1 16 0v2"/></svg><span class="screen-reader-text"><?php echo esc_html__('Benutzerkonto', 'openmieterportal'); ?></span><span aria-hidden="true">▾</span></summary><div class="bpm-user-dropdown"><a href="#bpm-account-profile"><?php echo esc_html__('Mein Profil', 'openmieterportal'); ?></a><a href="#bpm-settings"><?php echo esc_html__('Einstellungen', 'openmieterportal'); ?></a><a href="<?php echo  esc_url(wp_logout_url(BPM_Portal::url())) ?>"><?php echo esc_html__('Abmelden', 'openmieterportal'); ?></a></div></details></div>
    <?php if (isset($_GET['bpm_done'])) :
        ?><p class="bpm-notice" role="status"><?php echo esc_html__('Ihre Angaben wurden gespeichert. Vielen Dank.', 'openmieterportal'); ?></p><?php
    endif; ?>
  <nav class="bpm-nav" aria-label="<?php echo esc_attr__('Mieterportal', 'openmieterportal'); ?>"><a href="#bpm-contact"><?php echo esc_html__('Vertragsübersicht', 'openmieterportal'); ?></a><a href="#bpm-documents"><?php echo esc_html__('Dokumente', 'openmieterportal'); ?></a><a href="#bpm-messages"><?php echo esc_html__('Nachrichten', 'openmieterportal'); ?></a><a href="#bpm-readings"><?php echo esc_html__('Zählerstände', 'openmieterportal'); ?></a><a href="#bpm-preference"><?php echo esc_html__('Kommunikation', 'openmieterportal'); ?></a></nav>
  <section class="bpm-card" id="bpm-documents"><h3><?php echo esc_html__('Ihre Dokumente', 'openmieterportal'); ?></h3><p><?php echo esc_html__('Der erste vollständige Abruf wird mit Zeitpunkt und Benutzerkonto gespeichert. Verträge, Abrechnungen und weitere Unterlagen für Ihre Mietpartei. Sie können die Dateien herunterladen und dauerhaft speichern.', 'openmieterportal'); ?></p>
    <?php if (!$documents) :
        ?><p class="bpm-empty"><?php echo esc_html__('Für Ihre Mietpartei wurden noch keine Dokumente bereitgestellt.', 'openmieterportal'); ?></p><?php
    endif; ?>
    <div class="bpm-doc-controls"><label for="bpm-document-type"><?php echo esc_html__('Dokumententyp', 'openmieterportal'); ?></label><select id="bpm-document-type"><option value=""><?php echo esc_html__('Alle Dokumententypen', 'openmieterportal'); ?></option><?php foreach (['vertrag','abrechnung','mitteilung','sonstiges'] as $category) :
        ?><option value="<?php echo  esc_attr($category) ?>"><?php echo  esc_html(BPM_Portal::category_label($category)) ?></option><?php
                                                                 endforeach; ?></select><label for="bpm-document-search"><?php echo esc_html__('Dokumente durchsuchen', 'openmieterportal'); ?></label><input type="search" id="bpm-document-search" placeholder="<?php echo esc_attr__('Titel oder Dateiname', 'openmieterportal'); ?>"></div>
    <div class="bpm-table-scroll"><table id="bpm-document-table"><thead><tr><th scope="col" aria-sort="none"><button type="button" data-bpm-sort="0"><?php echo esc_html__('Titel', 'openmieterportal'); ?> <span class="bpm-sort-icons" aria-hidden="true"><span>▲</span><span>▼</span></span></button></th><th scope="col" aria-sort="none"><button type="button" data-bpm-sort="1"><?php echo esc_html__('Dokumententyp', 'openmieterportal'); ?> <span class="bpm-sort-icons" aria-hidden="true"><span>▲</span><span>▼</span></span></button></th><th scope="col" aria-sort="descending"><button type="button" data-bpm-sort="2"><?php echo esc_html__('Bereitgestellt', 'openmieterportal'); ?> <span class="bpm-sort-icons" aria-hidden="true"><span>▲</span><span>▼</span></span></button></th><th scope="col" aria-sort="none"><button type="button" data-bpm-sort="3"><?php echo esc_html__('Abgerufen', 'openmieterportal'); ?> <span class="bpm-sort-icons" aria-hidden="true"><span>▲</span><span>▼</span></span></button></th><th scope="col"><?php echo esc_html__('Datei', 'openmieterportal'); ?></th></tr></thead><tbody><?php foreach ($documents as $doc) :
        ?><tr data-category="<?php echo  esc_attr($doc->category) ?>"><td><?php echo  esc_html($doc->title) ?></td><td><?php echo  esc_html(BPM_Portal::category_label($doc->category)) ?></td><td data-sort-value="<?php echo  esc_attr($doc->created_at) ?>"><?php echo  esc_html(get_date_from_gmt($doc->created_at, 'd.m.Y H:i')) ?></td><td data-sort-value="<?php echo  esc_attr($doc->first_download_at ?? '') ?>"><?php echo  !empty($doc->first_download_at) ? esc_html(get_date_from_gmt($doc->first_download_at, 'd.m.Y H:i:s')) : esc_html__('Noch nicht abgerufen', 'openmieterportal') ?></td><td><a href="<?php echo  esc_url(BPM_Portal::download_url((int)$doc->id)) ?>"><?php echo esc_html__('PDF herunterladen', 'openmieterportal'); ?><span class="screen-reader-text">: <?php echo  esc_html($doc->title) ?></span></a></td></tr><?php
                                                                                                                                                     endforeach; ?></tbody></table></div><p id="bpm-document-result" class="bpm-small" role="status"></p>

  </section>
    <?php if (BPM_Tickets::enabled($h)) :
        // SupportCandy shortcode HTML: authorization and escaped portal markup are handled in render().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo BPM_Tickets::render($h);
    else : ?>
  <section class="bpm-card" id="bpm-messages"><h3><?php echo esc_html__('Nachrichten und Anliegen', 'openmieterportal'); ?></h3><p><?php echo esc_html__('Für Ihre Fragen und Meldungen an die Verwaltung. Für akute Gefahren nutzen Sie bitte die zuständigen Notdienste. Das Portal ist kein Notrufdienst.', 'openmieterportal'); ?></p>
    <details class="bpm-compose"><summary><?php echo esc_html__('Neue Nachricht schreiben', 'openmieterportal'); ?></summary>
      <form method="post" action="<?php echo  esc_url($endpoint) ?>"><?php BPM_Portal::form('message'); ?>
        <label for="bpm-kind"><?php echo esc_html__('Worum geht es?', 'openmieterportal'); ?></label><select id="bpm-kind" name="kind"><option value="anliegen"><?php echo esc_html__('Allgemeines Anliegen', 'openmieterportal'); ?></option><option value="schaden"><?php echo esc_html__('Schaden oder Mangel melden', 'openmieterportal'); ?></option><option value="stammdaten"><?php echo esc_html__('Stammdatenänderung mitteilen', 'openmieterportal'); ?></option></select>
        <label for="bpm-subject"><?php echo esc_html__('Betreff', 'openmieterportal'); ?></label><input id="bpm-subject" name="subject" maxlength="160" required>
        <label for="bpm-body"><?php echo esc_html__('Ihre Nachricht', 'openmieterportal'); ?></label><textarea id="bpm-body" name="body" maxlength="5000" rows="5" required></textarea>
        <button type="submit"><?php echo esc_html__('Nachricht senden', 'openmieterportal'); ?></button>
      </form>
    </details>
        <?php if (!$threads) :
            ?><p class="bpm-empty"><?php echo esc_html__('Hier finden Sie künftig Ihre Nachrichten und die Antworten der Verwaltung.', 'openmieterportal'); ?></p><?php
        endif; ?>
        <?php foreach ($threads as $thread) :
            $messages = BPM_Data::rows($wpdb->prepare('SELECT * FROM %i WHERE thread_id=%d ORDER BY id ASC LIMIT 100', BPM_Portal::table('messages'), $thread->id)); ?>
      <details class="bpm-thread"><summary><?php echo  esc_html($thread->subject) ?> <span class="bpm-badge"><?php echo  esc_html(str_replace('_', ' ', $thread->status)) ?></span></summary>
            <?php foreach ($messages as $message) :
                ?><article class="bpm-message"><p class="bpm-small"><strong><?php echo  (int) $message->user_id === (int) $h->user_id ? esc_html__('Ihre Mietpartei', 'openmieterportal') : esc_html__('Verwaltung', 'openmieterportal') ?></strong> · <?php echo  esc_html(get_date_from_gmt($message->created_at, 'd.m.Y H:i')) ?></p><p><?php echo  nl2br(esc_html($message->body)) ?></p></article><?php
            endforeach; ?>
        <form method="post" action="<?php echo  esc_url($endpoint) ?>"><?php BPM_Portal::form('reply'); ?><input type="hidden" name="thread_id" value="<?php echo  (int) $thread->id ?>"><label for="bpm-reply-<?php echo  (int) $thread->id ?>"><?php echo esc_html__('Antwort', 'openmieterportal'); ?></label><textarea id="bpm-reply-<?php echo  (int) $thread->id ?>" name="body" rows="3" maxlength="5000" required></textarea><button type="submit"><?php echo esc_html__('Antwort senden', 'openmieterportal'); ?></button></form>
      </details>
        <?php endforeach; ?>
  </section>
    <?php endif; ?>
  <section class="bpm-card" id="bpm-readings"><h3><?php echo esc_html__('Zählerstände', 'openmieterportal'); ?></h3><p><?php echo esc_html__('Bitte melden Sie nur die Werte der angezeigten Geräte. Geben Sie das tatsächliche Ablesedatum an. Meldungen werden vor einer Übernahme in die Abrechnung geprüft.', 'openmieterportal'); ?></p>
    <?php if ($meters) : ?>
      <form method="post" action="<?php echo  esc_url($endpoint) ?>" enctype="multipart/form-data"><?php BPM_Portal::form('reading'); ?>
        <label for="bpm-meter"><?php echo esc_html__('Zähler', 'openmieterportal'); ?></label><select id="bpm-meter" name="meter_id" required><?php foreach ($meters as $meter) :
            ?><option value="<?php echo  (int) $meter->id ?>"><?php echo  esc_html($meter->label . ' · Nr. ' . $meter->number . ' · ' . $meter->unit) ?></option><?php
                               endforeach; ?></select>
        <div class="bpm-columns"><div><label for="bpm-value"><?php echo esc_html__('Abgelesener Zählerstand', 'openmieterportal'); ?></label><input id="bpm-value" name="value" inputmode="decimal" pattern="[0-9]{1,10}([.,][0-9]{1,3})?" required placeholder="<?php echo esc_attr__( 'z. B. 123,456', 'openmieterportal' ); ?>"><small><?php echo esc_html__('Höchstens drei Nachkommastellen. Einheit siehe Zählerauswahl.', 'openmieterportal'); ?></small></div><div><label for="bpm-read-at"><?php echo esc_html__('Tatsächliches Ablesedatum', 'openmieterportal'); ?></label><input type="date" id="bpm-read-at" name="read_at" min="2000-01-01" max="<?php echo  esc_attr(wp_date('Y-m-d')) ?>" required></div></div>
        <label for="bpm-photo"><?php echo esc_html__('Zählerfoto (optional)', 'openmieterportal'); ?></label><input id="bpm-photo" name="photo" type="file" accept="image/jpeg,image/png"><small><?php echo esc_html__('JPG oder PNG, maximal 8 MB und 12 Megapixel. Bitte Gerätenummer und Anzeige lesbar fotografieren.', 'openmieterportal'); ?></small>
        <button type="submit"><?php echo esc_html__('Zählerstand melden', 'openmieterportal'); ?></button>
      </form>
    <?php else :
        ?><p class="bpm-empty"><?php echo esc_html__('Es wurden noch keine Zähler für Ihre Mietpartei zugeordnet. Bitte wenden Sie sich an die Verwaltung.', 'openmieterportal'); ?></p><?php
    endif; ?>
    <?php if ($readings) :
        ?><h4><?php echo esc_html__('Ihre letzten Meldungen', 'openmieterportal'); ?></h4><div class="bpm-table-scroll"><table><thead><tr><th><?php echo esc_html__('Gerät', 'openmieterportal'); ?></th><th><?php echo esc_html__('Abgelesen am', 'openmieterportal'); ?></th><th><?php echo esc_html__('Stand', 'openmieterportal'); ?></th><th><?php echo esc_html__('Bearbeitung', 'openmieterportal'); ?></th><th><?php echo esc_html__('Foto', 'openmieterportal'); ?></th></tr></thead><tbody><?php foreach ($readings as $r) :
    ?><tr><td><?php echo  esc_html($r->label . ' · ' . $r->number) ?></td><td><?php echo  esc_html(mysql2date('d.m.Y', $r->read_at)) ?></td><td><?php echo  esc_html(BPM_Domain::reading_display((string)$r->value) . ' ' . $r->unit) ?></td><td><?php echo  esc_html($r->status === 'ungeprueft' ? esc_html__('Eingegangen, noch ungeprüft', 'openmieterportal') : ($r->status === 'bestaetigt' ? esc_html__('Von Verwaltung bestätigt', 'openmieterportal') : esc_html__('Rückfrage erforderlich', 'openmieterportal'))) ?></td><td><?php if ($r->photo_key) :
    ?><a href="<?php echo  esc_url(BPM_Portal::download_url((int) $r->id, true)) ?>"><?php echo esc_html__('Foto herunterladen', 'openmieterportal'); ?></a><?php
    else :
        ?>—<?php
    endif; ?></td></tr><?php
        endforeach; ?></tbody></table></div><?php
    endif; ?>
  </section>
  <section class="bpm-card" id="bpm-contact"><h3><?php echo esc_html__('Vertragsübersicht', 'openmieterportal'); ?></h3><dl class="bpm-data"><dt><?php echo esc_html__('Mietpartei', 'openmieterportal'); ?></dt><dd><?php echo  esc_html($h->label) ?></dd><dt><?php echo esc_html__('Wohnung / Vertrag', 'openmieterportal'); ?></dt><dd><?php echo  esc_html($h->unit_label) ?></dd><dt><?php echo esc_html__('Vertrags-/Mietparteiennummer', 'openmieterportal'); ?></dt><dd><?php echo  esc_html($h->reference) ?></dd><dt><?php echo esc_html__('Mietbeginn', 'openmieterportal'); ?></dt><dd><?php echo  !empty($h->tenancy_start) ? esc_html(mysql2date('d.m.Y', $h->tenancy_start)) : esc_html__('Noch nicht hinterlegt', 'openmieterportal') ?></dd><dt><?php echo esc_html__('Mietende', 'openmieterportal'); ?></dt><dd><?php echo  !empty($h->tenancy_end) ? esc_html(mysql2date('d.m.Y', $h->tenancy_end)) : 'Unbefristet / noch kein Mietende hinterlegt' ?></dd><dt><?php echo esc_html__('Mietverhältnis', 'openmieterportal'); ?></dt><dd><?php echo  !empty($h->tenancy_end) && $h->tenancy_end < BPM_Portal::today() ? esc_html__('Beendet', 'openmieterportal') : (!empty($h->tenancy_start) && $h->tenancy_start > BPM_Portal::today() ? esc_html__('Beginnt zukünftig', 'openmieterportal') : esc_html__('Laufend', 'openmieterportal')) ?></dd><?php if (!empty($h->notice_received)) :
        ?><dt><?php echo esc_html__('Kündigung / Vereinbarung erfasst', 'openmieterportal'); ?></dt><dd><?php echo  esc_html(mysql2date('d.m.Y', $h->notice_received)) ?></dd><?php
                                                 endif; ?><?php $profile_deadline = BPM_Domain::access_deadline($h->tenancy_end ?? null); if ($profile_deadline) :
    ?><dt><?php echo esc_html__('Portalzugang längstens bis', 'openmieterportal'); ?></dt><dd><?php echo  esc_html(mysql2date('d.m.Y', $profile_deadline)) ?> <?php echo esc_html__( 'einschließlich', 'openmieterportal' ); ?></dd><?php
                                                 endif; ?><?php require __DIR__ . '/view-rent-tenant.php'; ?></dl><p><?php echo esc_html__('Ihre Anschrift, Bankverbindung und Kontaktdaten können Sie unter', 'openmieterportal'); ?> <a href="#bpm-account-profile"><?php echo esc_html__('Mein Profil', 'openmieterportal'); ?></a> <?php echo esc_html__('pflegen. Bei Fragen zu Ihren Vertragsdaten schreiben Sie uns über', 'openmieterportal'); ?> <a href="#bpm-messages"><?php echo esc_html__('Nachrichten', 'openmieterportal'); ?></a>.</p></section>
    <?php if (!$needs_communication) :
        require __DIR__ . '/view-communication.php';
    endif; ?>
    <?php require __DIR__ . '/view-account.php'; ?>
<?php endif; ?>
  <footer class="bpm-foot"><p><?php echo esc_html__('Fragen zum Zugang?', 'openmieterportal'); ?> <a href="<?php echo  esc_url(home_url('/kontakt/')) ?>"><?php echo esc_html__('Kontakt zur Verwaltung', 'openmieterportal'); ?></a></p></footer>
</div>
