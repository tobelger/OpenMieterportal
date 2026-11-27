<?php
defined('ABSPATH') || exit;
$has_current_consent = $preference && $preference->choice === 'portal' && $preference->version === BPM_Settings::consent_version();
$has_current_authority = $has_current_consent && (int)$preference->authority_confirmed === 1;
?>
  <section class="bpm-card <?php echo  $needs_communication ? 'bpm-communication-prompt' : '' ?>" id="bpm-preference" aria-labelledby="bpm-communication-title"><h3 id="bpm-communication-title"><?php echo  $needs_communication ? esc_html__('Bitte entscheiden Sie über die elektronische Zustellung', 'openmieterportal') : esc_html__('Digitale Kommunikation', 'openmieterportal') ?></h3><p><?php echo esc_html__('Ihre Mietpartei kann ausdrücklich die ausschließlich elektronische Übermittlung der in der Vereinbarung genannten Unterlagen wählen. Die Nutzung des Portals setzt keine Zustimmung zur digitalen Zustellung voraus.', 'openmieterportal'); ?></p>
    <p><strong><?php echo esc_html__('Aktuelle Wahl:', 'openmieterportal'); ?></strong> <?php echo  esc_html($preference && $preference->choice === 'portal' ? esc_html__('Elektronische Zustellung vereinbart', 'openmieterportal') : esc_html__('Keine aktive Vereinbarung zur digitalen Zustellung', 'openmieterportal')) ?><?php if ($preference) :
        ?> · <?php echo  esc_html(get_date_from_gmt($preference->created_at, 'd.m.Y H:i') . ' · Fassung ' . $preference->version) ?><?php
               endif; ?></p>
    <?php if (get_option('bpm_consent_enabled')) : ?>
      <details <?php echo  $needs_communication ? 'open' : '' ?>><summary><?php echo esc_html__('Vereinbarung lesen', 'openmieterportal'); ?></summary><p><?php echo  esc_html(BPM_Settings::consent_text()) ?></p><p class="bpm-small"><?php echo esc_html__( 'Textfassung', 'openmieterportal' ); ?> <?php echo  esc_html(BPM_Settings::consent_version()) ?></p></details>
      <form method="post" action="<?php echo  esc_url($endpoint) ?>"><?php BPM_Portal::form('preference'); ?><input type="hidden" name="choice" value="portal"><label class="bpm-check"><input name="agree" type="checkbox" value="1" <?php echo  checked((bool)$has_current_consent, true, false) ?> required> <?php echo esc_html__('Ich stimme der Vereinbarung ausdrücklich zu: Für die erfassten Unterlagen genügt die elektronische Übermittlung; zusätzliche Papierbriefe entfallen.', 'openmieterportal'); ?></label><label class="bpm-check"><input name="authority" type="checkbox" value="1" <?php echo  checked((bool)$has_current_authority, true, false) ?> required> <?php echo esc_html__('Ich bin berechtigt, diese Wahl für die Mietpartei zu erklären, und habe sie mit den weiteren Vertragspartnern abgestimmt.', 'openmieterportal'); ?></label><button type="submit"><?php echo esc_html__('Wahl dokumentieren', 'openmieterportal'); ?></button></form>
    <?php else :
        ?><p class="bpm-empty"><?php echo esc_html__('Die Vereinbarung zur digitalen Zustellung wird noch vorbereitet. Bis zur Freigabe gelten Ihre bisherigen Kommunikationswege weiter.', 'openmieterportal'); ?></p><?php
    endif; ?>
    <?php if ($preference && $preference->choice === 'portal') :
        ?><form method="post" action="<?php echo  esc_url($endpoint) ?>"><?php BPM_Portal::form('preference'); ?><input type="hidden" name="choice" value="widerrufen"><button type="submit" class="bpm-secondary"><?php echo esc_html__('Digitale Zustellung für die Zukunft widerrufen', 'openmieterportal'); ?></button></form><?php
    endif; ?>
    <p class="bpm-small"><?php echo esc_html__('Die Erklärung gilt für den gemeinsamen Zugang Ihrer Mietpartei. Sie ersetzt keine gesetzlich vorgeschriebene Form und bestätigt nicht automatisch den Zugang einzelner Schreiben.', 'openmieterportal'); ?></p>
  </section>
