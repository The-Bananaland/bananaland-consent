<?php
/**
 * Preferences panel (second layer). A native <dialog>; consent.js opens it with showModal().
 *
 * Override: copy to {theme}/consent/preferences.php. Keep the data-blc* attributes.
 *
 * @version 0.1.0
 * @var \Bananaland\Consent\Templates\View $consent
 */

defined('ABSPATH') || exit;

$blc_policy = $consent->policy_url();
$blc_cookies = $consent->cookie_url();
?>
<dialog class="blc-prefs" data-blc="preferences" aria-labelledby="blc-prefs-title" aria-describedby="blc-prefs-intro">
    <div class="blc-prefs__header">
        <h2 class="blc-prefs__title" id="blc-prefs-title"><?php echo esc_html($consent->text('preferences', 'title')); ?></h2>
        <button type="button" class="blc-prefs__close" data-blc-action="close" aria-label="<?php echo esc_attr($consent->text('preferences', 'close')); ?>">
            <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
    </div>
    <div class="blc-prefs__body">
        <p class="blc-prefs__intro" id="blc-prefs-intro"><?php echo esc_html($consent->text('preferences', 'intro')); ?></p>
        <p class="blc-prefs__gpc" data-blc="gpc-notice" role="status" hidden><?php echo esc_html($consent->text('preferences', 'gpc_notice')); ?></p>
        <?php foreach ($consent->categories() as $blc_category) : ?>
            <?php $consent->part('category', ['category' => $blc_category]); ?>
        <?php endforeach; ?>
        <p class="blc-prefs__meta">
            <?php echo esc_html($consent->text('preferences', 'consent_id')); ?>:
            <code data-blc="consent-id">&mdash;</code>
        </p>
        <?php if ($blc_policy || $blc_cookies) : ?>
            <p class="blc-prefs__links">
                <?php if ($blc_policy) : ?><a href="<?php echo esc_url($blc_policy); ?>"><?php echo esc_html($consent->text('preferences', 'policy_link')); ?></a><?php endif; ?>
                <?php if ($blc_cookies) : ?><a href="<?php echo esc_url($blc_cookies); ?>"><?php echo esc_html($consent->text('preferences', 'cookie_link')); ?></a><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <div class="blc-prefs__footer">
        <button type="button" class="blc-btn blc-btn--secondary" data-blc-action="reject-all"><?php echo esc_html($consent->text('preferences', 'reject')); ?></button>
        <button type="button" class="blc-btn blc-btn--secondary" data-blc-action="accept-all"><?php echo esc_html($consent->text('preferences', 'accept')); ?></button>
        <button type="button" class="blc-btn blc-btn--primary" data-blc-action="save"><?php echo esc_html($consent->text('preferences', 'save')); ?></button>
    </div>
</dialog>
