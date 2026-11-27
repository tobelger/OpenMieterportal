<?php
defined('ABSPATH') || exit;
$h = BPM_Portal::household();
$endpoint = admin_url('admin-post.php');
?>
<div class="bpm-portal">
  <?php if ($show_header ?? true): ?><header class="bpm-head"><p class="bpm-eyebrow"><?= esc_html(BPM_Settings::value('name')) ?></p><h2>Ihr Mieterportal</h2><p>Ein gemeinsamer Zugang. Alles rund um Ihr Mietverhältnis.</p></header><?php endif; ?>
<?php if (!is_user_logged_in()): ?>
  <div class="bpm-columns">
    <?php if (!BPM_Portal::is_activation()): ?><section class="bpm-card"><h3>Willkommen zurück</h3><p>Melden Sie sich mit dem gemeinsamen Zugang Ihrer Mietpartei an.</p>
      <?php wp_login_form(['redirect' => BPM_Portal::url(), 'label_username' => 'E-Mail-Adresse oder Benutzername', 'label_password' => 'Passwort', 'label_remember' => 'Angemeldet bleiben', 'label_log_in' => 'Anmelden']); ?>
      <p><a href="<?= esc_url(wp_lostpassword_url(BPM_Portal::url())) ?>">Passwort vergessen?</a></p>
    </section>
    <?php endif; if (BPM_Portal::is_activation()): ?><section class="bpm-card" id="bpm-activation"><h3>Einladung erhalten?</h3><p>Scannen Sie den QR-Code auf Ihrem Brief oder geben Sie den Einladungscode ein. Pro Mietpartei wird genau ein Zugang eingerichtet.</p>
      <form action="<?= esc_url($endpoint) ?>" method="post" autocomplete="off"><?php BPM_Portal::form('activate'); ?>
        <label for="bpm-code">Einladungscode</label><input id="bpm-code" name="invite_code" required minlength="16" maxlength="64" spellcheck="false" autocapitalize="characters" autocomplete="off">
        <label for="bpm-email">Gemeinsame Kontakt-E-Mail-Adresse</label><input id="bpm-email" name="email" type="email" required maxlength="190" autocomplete="email">
        <p class="bpm-small">An diese Adresse senden wir einen Link zur Bestätigung und zum Festlegen Ihres Passworts. Ihre Entscheidung zur digitalen Kommunikation treffen Sie anschließend separat im Portal.</p>
        <p class="bpm-small">Informationen zur Datenverarbeitung finden Sie in der <a href="<?= esc_url(get_privacy_policy_url() ?: home_url('/privacy-policy/#datenschutz')) ?>">Datenschutzerklärung</a>.</p>
        <button type="submit">Aktivierungslink anfordern</button>
      </form>
    </section>
    <?php else: ?><section class="bpm-card"><h3>Einladung erhalten?</h3><p>Aktivieren Sie den gemeinsamen Zugang mit dem Code aus Ihrem Einladungsbrief.</p><a class="bpm-button" href="<?= esc_url(BPM_Portal::activation_url()) ?>">Zugang aktivieren</a></section><?php endif; ?>
  </div>
<?php elseif (!BPM_Portal::can_access($h)): ?>
  <section class="bpm-card"><h3><?= BPM_Portal::manager() ? 'Verwaltungszugang' : 'Zugang nicht verfügbar' ?></h3>
    <p><?= BPM_Portal::manager() ? 'Mietparteien, Einladungen und Inhalte verwalten Sie im WordPress-Verwaltungsbereich. Ihr Verwalterkonto wird keiner Mietpartei zugeordnet.' : 'Für dieses Konto ist der Mieterzugang noch nicht aktiviert, gesperrt oder die Zugangsfrist ist abgelaufen. Bitte verwenden Sie den Bestätigungslink aus Ihrer E-Mail oder wenden Sie sich an die Verwaltung.' ?></p>
    <?php if (BPM_Portal::manager()): ?><a class="bpm-button" href="<?= esc_url(admin_url('admin.php?page=belger-mieterportal')) ?>">Portal verwalten</a><?php endif; ?>
    <p><a href="<?= esc_url(wp_logout_url(BPM_Portal::url())) ?>">Abmelden</a></p>
  </section>
<?php else:
    $hid = (int) $h->id;
    $documents = BPM_Data::rows($wpdb->prepare('SELECT * FROM ' . BPM_Portal::table('documents') . ' WHERE household_id=%d ORDER BY created_at DESC, id DESC LIMIT 100', $hid));
    $meters = BPM_Portal::meters_for($h);
    $readings = BPM_Data::rows($wpdb->prepare('SELECT r.*, m.number, m.label, m.unit FROM ' . BPM_Portal::table('readings') . ' r JOIN ' . BPM_Portal::table('meters') . ' m ON m.id=r.meter_id WHERE r.household_id=%d ORDER BY r.read_at DESC, r.id DESC LIMIT 50', $hid));
    $threads = BPM_Data::rows($wpdb->prepare('SELECT * FROM ' . BPM_Portal::table('threads') . ' WHERE household_id=%d ORDER BY updated_at DESC, id DESC LIMIT 30', $hid));
    $preference = BPM_Data::row($wpdb->prepare('SELECT * FROM ' . BPM_Portal::table('preferences') . ' WHERE household_id=%d ORDER BY id DESC LIMIT 1', $hid));
    $needs_communication = (bool) get_option('bpm_consent_enabled') && (!$preference || $preference->choice !== 'portal' || $preference->version !== BPM_Settings::consent_version());
?>
  <?php if ($needs_communication): require __DIR__ . '/view-communication.php'; endif; ?>
  <div class="bpm-account"><div><strong><?= esc_html($h->label) ?></strong><span><?= esc_html($h->unit_label) ?></span></div><details class="bpm-user-menu"><summary aria-label="Benutzerkonto"><svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="7" r="4"/><path d="M4 22v-2a8 8 0 0 1 16 0v2"/></svg><span class="screen-reader-text">Benutzerkonto</span><span aria-hidden="true">▾</span></summary><div class="bpm-user-dropdown"><a href="#bpm-account-profile">Mein Profil</a><a href="#bpm-settings">Einstellungen</a><a href="<?= esc_url(wp_logout_url(BPM_Portal::url())) ?>">Abmelden</a></div></details></div>
  <?php if (isset($_GET['bpm_done'])): ?><p class="bpm-notice" role="status">Ihre Angaben wurden gespeichert. Vielen Dank.</p><?php endif; ?>
  <nav class="bpm-nav" aria-label="Mieterportal"><a href="#bpm-contact">Vertragsübersicht</a><a href="#bpm-documents">Dokumente</a><a href="#bpm-messages">Nachrichten</a><a href="#bpm-readings">Zählerstände</a><a href="#bpm-preference">Kommunikation</a></nav>
  <section class="bpm-card" id="bpm-documents"><h3>Ihre Dokumente</h3><p>Der erste vollständige Abruf wird mit Zeitpunkt und Benutzerkonto gespeichert. Verträge, Abrechnungen und weitere Unterlagen für Ihre Mietpartei. Sie können die Dateien herunterladen und dauerhaft speichern.</p>
    <?php if (!$documents): ?><p class="bpm-empty">Für Ihre Mietpartei wurden noch keine Dokumente bereitgestellt.</p><?php endif; ?>
    <div class="bpm-doc-controls"><label for="bpm-document-type">Dokumententyp</label><select id="bpm-document-type"><option value="">Alle Dokumententypen</option><?php foreach (['vertrag','abrechnung','mitteilung','sonstiges'] as $category): ?><option value="<?= esc_attr($category) ?>"><?= esc_html(BPM_Portal::category_label($category)) ?></option><?php endforeach; ?></select><label for="bpm-document-search">Dokumente durchsuchen</label><input type="search" id="bpm-document-search" placeholder="Titel oder Dateiname"></div>
    <div class="bpm-table-scroll"><table id="bpm-document-table"><thead><tr><th scope="col" aria-sort="none"><button type="button" data-bpm-sort="0">Titel <span class="bpm-sort-icons" aria-hidden="true"><span>▲</span><span>▼</span></span></button></th><th scope="col" aria-sort="none"><button type="button" data-bpm-sort="1">Dokumententyp <span class="bpm-sort-icons" aria-hidden="true"><span>▲</span><span>▼</span></span></button></th><th scope="col" aria-sort="descending"><button type="button" data-bpm-sort="2">Bereitgestellt <span class="bpm-sort-icons" aria-hidden="true"><span>▲</span><span>▼</span></span></button></th><th scope="col" aria-sort="none"><button type="button" data-bpm-sort="3">Abgerufen <span class="bpm-sort-icons" aria-hidden="true"><span>▲</span><span>▼</span></span></button></th><th scope="col">Datei</th></tr></thead><tbody><?php foreach ($documents as $doc): ?><tr data-category="<?= esc_attr($doc->category) ?>"><td><?= esc_html($doc->title) ?></td><td><?= esc_html(BPM_Portal::category_label($doc->category)) ?></td><td data-sort-value="<?= esc_attr($doc->created_at) ?>"><?= esc_html(get_date_from_gmt($doc->created_at,'d.m.Y H:i')) ?></td><td data-sort-value="<?= esc_attr($doc->first_download_at ?? '') ?>"><?= !empty($doc->first_download_at) ? esc_html(get_date_from_gmt($doc->first_download_at,'d.m.Y H:i:s')) : 'Noch nicht abgerufen' ?></td><td><a href="<?= esc_url(BPM_Portal::download_url((int)$doc->id)) ?>">PDF herunterladen<span class="screen-reader-text">: <?= esc_html($doc->title) ?></span></a></td></tr><?php endforeach; ?></tbody></table></div><p id="bpm-document-result" class="bpm-small" role="status"></p>

  </section>
  <?php if (BPM_Tickets::enabled($h)): echo BPM_Tickets::render($h); else: ?>
  <section class="bpm-card" id="bpm-messages"><h3>Nachrichten und Anliegen</h3><p>Für Ihre Fragen und Meldungen an die Verwaltung. Für akute Gefahren nutzen Sie bitte die zuständigen Notdienste. Das Portal ist kein Notrufdienst.</p>
    <details class="bpm-compose"><summary>Neue Nachricht schreiben</summary>
      <form method="post" action="<?= esc_url($endpoint) ?>"><?php BPM_Portal::form('message'); ?>
        <label for="bpm-kind">Worum geht es?</label><select id="bpm-kind" name="kind"><option value="anliegen">Allgemeines Anliegen</option><option value="schaden">Schaden oder Mangel melden</option><option value="stammdaten">Stammdatenänderung mitteilen</option></select>
        <label for="bpm-subject">Betreff</label><input id="bpm-subject" name="subject" maxlength="160" required>
        <label for="bpm-body">Ihre Nachricht</label><textarea id="bpm-body" name="body" maxlength="5000" rows="5" required></textarea>
        <button type="submit">Nachricht senden</button>
      </form>
    </details>
    <?php if (!$threads): ?><p class="bpm-empty">Hier finden Sie künftig Ihre Nachrichten und die Antworten der Verwaltung.</p><?php endif; ?>
    <?php foreach ($threads as $thread): $messages = BPM_Data::rows($wpdb->prepare('SELECT * FROM ' . BPM_Portal::table('messages') . ' WHERE thread_id=%d ORDER BY id ASC LIMIT 100', $thread->id)); ?>
      <details class="bpm-thread"><summary><?= esc_html($thread->subject) ?> <span class="bpm-badge"><?= esc_html(str_replace('_', ' ', $thread->status)) ?></span></summary>
        <?php foreach ($messages as $message): ?><article class="bpm-message"><p class="bpm-small"><strong><?= (int) $message->user_id === (int) $h->user_id ? 'Ihre Mietpartei' : 'Verwaltung' ?></strong> · <?= esc_html(get_date_from_gmt($message->created_at, 'd.m.Y H:i')) ?></p><p><?= nl2br(esc_html($message->body)) ?></p></article><?php endforeach; ?>
        <form method="post" action="<?= esc_url($endpoint) ?>"><?php BPM_Portal::form('reply'); ?><input type="hidden" name="thread_id" value="<?= (int) $thread->id ?>"><label for="bpm-reply-<?= (int) $thread->id ?>">Antwort</label><textarea id="bpm-reply-<?= (int) $thread->id ?>" name="body" rows="3" maxlength="5000" required></textarea><button type="submit">Antwort senden</button></form>
      </details>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
  <section class="bpm-card" id="bpm-readings"><h3>Zählerstände</h3><p>Bitte melden Sie nur die Werte der angezeigten Geräte. Geben Sie das tatsächliche Ablesedatum an. Meldungen werden vor einer Übernahme in die Abrechnung geprüft.</p>
    <?php if ($meters): ?>
      <form method="post" action="<?= esc_url($endpoint) ?>" enctype="multipart/form-data"><?php BPM_Portal::form('reading'); ?>
        <label for="bpm-meter">Zähler</label><select id="bpm-meter" name="meter_id" required><?php foreach ($meters as $meter): ?><option value="<?= (int) $meter->id ?>"><?= esc_html($meter->label . ' · Nr. ' . $meter->number . ' · ' . $meter->unit) ?></option><?php endforeach; ?></select>
        <div class="bpm-columns"><div><label for="bpm-value">Abgelesener Zählerstand</label><input id="bpm-value" name="value" inputmode="decimal" pattern="[0-9]{1,10}([.,][0-9]{1,3})?" required placeholder="z. B. 123,456"><small>Höchstens drei Nachkommastellen. Einheit siehe Zählerauswahl.</small></div><div><label for="bpm-read-at">Tatsächliches Ablesedatum</label><input type="date" id="bpm-read-at" name="read_at" min="2000-01-01" max="<?= esc_attr(wp_date('Y-m-d')) ?>" required></div></div>
        <label for="bpm-photo">Zählerfoto (optional)</label><input id="bpm-photo" name="photo" type="file" accept="image/jpeg,image/png"><small>JPG oder PNG, maximal 8 MB und 12 Megapixel. Bitte Gerätenummer und Anzeige lesbar fotografieren.</small>
        <button type="submit">Zählerstand melden</button>
      </form>
    <?php else: ?><p class="bpm-empty">Es wurden noch keine Zähler für Ihre Mietpartei zugeordnet. Bitte wenden Sie sich an die Verwaltung.</p><?php endif; ?>
    <?php if ($readings): ?><h4>Ihre letzten Meldungen</h4><div class="bpm-table-scroll"><table><thead><tr><th>Gerät</th><th>Abgelesen am</th><th>Stand</th><th>Bearbeitung</th><th>Foto</th></tr></thead><tbody><?php foreach ($readings as $r): ?><tr><td><?= esc_html($r->label . ' · ' . $r->number) ?></td><td><?= esc_html(mysql2date('d.m.Y', $r->read_at)) ?></td><td><?= esc_html(BPM_Domain::reading_display((string)$r->value) . ' ' . $r->unit) ?></td><td><?= esc_html($r->status === 'ungeprueft' ? 'Eingegangen, noch ungeprüft' : ($r->status === 'bestaetigt' ? 'Von Verwaltung bestätigt' : 'Rückfrage erforderlich')) ?></td><td><?php if ($r->photo_key): ?><a href="<?= esc_url(BPM_Portal::download_url((int) $r->id, true)) ?>">Foto herunterladen</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
  <section class="bpm-card" id="bpm-contact"><h3>Vertragsübersicht</h3><dl class="bpm-data"><dt>Mietpartei</dt><dd><?= esc_html($h->label) ?></dd><dt>Wohnung / Vertrag</dt><dd><?= esc_html($h->unit_label) ?></dd><dt>Vertrags-/Mietparteiennummer</dt><dd><?= esc_html($h->reference) ?></dd><dt>Mietbeginn</dt><dd><?= !empty($h->tenancy_start) ? esc_html(mysql2date('d.m.Y', $h->tenancy_start)) : 'Noch nicht hinterlegt' ?></dd><dt>Mietende</dt><dd><?= !empty($h->tenancy_end) ? esc_html(mysql2date('d.m.Y', $h->tenancy_end)) : 'Unbefristet / noch kein Mietende hinterlegt' ?></dd><dt>Mietverhältnis</dt><dd><?= !empty($h->tenancy_end) && $h->tenancy_end < BPM_Portal::today() ? 'Beendet' : (!empty($h->tenancy_start) && $h->tenancy_start > BPM_Portal::today() ? 'Beginnt zukünftig' : 'Laufend') ?></dd><?php if (!empty($h->notice_received)): ?><dt>Kündigung / Vereinbarung erfasst</dt><dd><?= esc_html(mysql2date('d.m.Y', $h->notice_received)) ?></dd><?php endif; ?><?php $profile_deadline = BPM_Domain::access_deadline($h->tenancy_end ?? null); if ($profile_deadline): ?><dt>Portalzugang längstens bis</dt><dd><?= esc_html(mysql2date('d.m.Y', $profile_deadline)) ?> einschließlich</dd><?php endif; ?><?php require __DIR__ . '/view-rent-tenant.php'; ?></dl><p>Ihre Anschrift, Bankverbindung und Kontaktdaten können Sie unter <a href="#bpm-account-profile">Mein Profil</a> pflegen. Bei Fragen zu Ihren Vertragsdaten schreiben Sie uns über <a href="#bpm-messages">Nachrichten</a>.</p></section>
  <?php if (!$needs_communication): require __DIR__ . '/view-communication.php'; endif; ?>
  <?php require __DIR__ . '/view-account.php'; ?>
<?php endif; ?>
  <footer class="bpm-foot"><p>Fragen zum Zugang? <a href="<?= esc_url(home_url('/kontakt/')) ?>">Kontakt zur Verwaltung</a></p></footer>
</div>
