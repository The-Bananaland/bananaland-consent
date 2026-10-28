<?php
/**
 * Consent banner (first layer).
 *
 * Override: copy to {theme}/consent/banner.php. Keep the data-blc* attributes; consent.js binds to them.
 * One block per consent model; consent.js shows the block matching the visitor's region.
 *
 * @version 0.1.0
 * @var \Bananaland\Consent\Templates\View $consent
 */

defined('ABSPATH') || exit;

$blc_tag = $consent->layout() === 'modal' ? 'dialog' : 'div';
$blc_policy = $consent->policy_url();
?>
<<?php echo $blc_tag; // phpcs:ignore ?> class="blc-banner blc-banner--<?php echo esc_attr($consent->layout()); ?> blc-banner--<?php echo esc_attr($consent->position()); ?>"
    data-blc="banner"
    <?php if ($blc_tag === 'div') : ?>role="region" hidden<?php endif; ?>
    aria-label="<?php esc_attr_e('Cookie consent', 'bananaland-consent'); ?>">
    <?php foreach ($consent->modes() as $blc_mode) : ?>
        <div class="blc-banner__inner" data-blc-show-for="<?php echo esc_attr($blc_mode); ?>">
            <div class="blc-banner__content">
                <h2 class="blc-banner__title"><?php echo esc_html($consent->text($blc_mode, 'title')); ?></h2>
                <p class="blc-banner__body">
                    <?php echo esc_html($consent->text($blc_mode, 'body')); ?>
                    <?php if ($blc_policy) : ?>
                        <a class="blc-banner__link" href="<?php echo esc_url($blc_policy); ?>"><?php echo esc_html($consent->text('preferences', 'policy_link')); ?></a>
                    <?php endif; ?>
                </p>
            </div>
            <div class="blc-banner__actions">
                <?php if ($blc_mode === 'opt-in') : ?>
                    <button type="button" class="blc-btn blc-btn--secondary" data-blc-action="open-preferences"><?php echo esc_html($consent->text($blc_mode, 'customize')); ?></button>
                    <button type="button" class="blc-btn blc-btn--primary" data-blc-action="reject-all"><?php echo esc_html($consent->text($blc_mode, 'reject')); ?></button>
                    <button type="button" class="blc-btn blc-btn--primary" data-blc-action="accept-all"><?php echo esc_html($consent->text($blc_mode, 'accept')); ?></button>
                <?php elseif ($blc_mode === 'opt-out') : ?>
                    <button type="button" class="blc-btn blc-btn--secondary" data-blc-action="open-preferences"><?php echo esc_html($consent->text($blc_mode, 'customize')); ?></button>
                    <button type="button" class="blc-btn blc-btn--primary" data-blc-action="opt-out"><?php echo esc_html($consent->text($blc_mode, 'opt_out')); ?></button>
                    <button type="button" class="blc-btn blc-btn--primary" data-blc-action="accept-all"><?php echo esc_html($consent->text($blc_mode, 'accept')); ?></button>
                <?php else : ?>
                    <button type="button" class="blc-btn blc-btn--secondary" data-blc-action="open-preferences"><?php echo esc_html($consent->text($blc_mode, 'customize')); ?></button>
                    <button type="button" class="blc-btn blc-btn--primary" data-blc-action="accept-all"><?php echo esc_html($consent->text($blc_mode, 'accept')); ?></button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</<?php echo $blc_tag; // phpcs:ignore ?>>
