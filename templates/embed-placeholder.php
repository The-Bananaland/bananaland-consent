<?php
/**
 * Placeholder shown instead of a third-party embed until it is allowed.
 * The original embed sits inert in the <template>; consent.js swaps it in.
 *
 * @version 0.1.0
 * @var \Bananaland\Consent\Templates\View $consent
 * @var string $provider   e.g. "YouTube"
 * @var string $embed      original embed HTML
 * @var string $thumbnail  local thumbnail URL or ''
 * @var string $category   consent category that unlocks it
 * @var string $ratio      CSS aspect-ratio, e.g. "16 / 9"
 */

defined('ABSPATH') || exit;
?>
<div class="blc-embed" data-blc="embed" data-consent="<?php echo esc_attr($category); ?>" style="aspect-ratio: <?php echo esc_attr($ratio); ?>">
    <?php if ($thumbnail) : ?>
        <img class="blc-embed__thumb" src="<?php echo esc_url($thumbnail); ?>" alt="" loading="lazy" decoding="async">
    <?php endif; ?>
    <div class="blc-embed__panel">
        <p class="blc-embed__text"><?php echo esc_html(sprintf($consent->text('embed', 'body'), $provider)); ?></p>
        <div class="blc-embed__actions">
            <button type="button" class="blc-btn blc-btn--primary" data-blc-action="embed-once"><?php echo esc_html($consent->text('embed', 'load_once')); ?></button>
            <button type="button" class="blc-btn blc-btn--secondary" data-blc-action="embed-always"><?php echo esc_html($consent->text('embed', 'always')); ?></button>
        </div>
    </div>
    <template data-blc="embed-source"><?php echo $embed; // phpcs:ignore WordPress.Security.EscapeOutput -- original embed markup from WordPress. ?></template>
</div>
