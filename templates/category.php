<?php
/**
 * One category in the preferences panel.
 *
 * @version 0.1.0
 * @var \Bananaland\Consent\Templates\View $consent
 * @var array{key: string, label: string, description: string, services: array} $category
 */

defined('ABSPATH') || exit;

$blc_key = $category['key'];
$blc_id = 'blc-cat-' . $blc_key;
?>
<section class="blc-cat" data-category="<?php echo esc_attr($blc_key); ?>">
    <div class="blc-cat__header">
        <h3 class="blc-cat__title" id="<?php echo esc_attr($blc_id); ?>"><?php echo esc_html($category['label']); ?></h3>
        <?php if ($blc_key === 'necessary') : ?>
            <span class="blc-cat__always"><?php echo esc_html($consent->text('preferences', 'always_active')); ?></span>
        <?php else : ?>
            <label class="blc-switch">
                <input type="checkbox" role="switch" class="blc-switch__input"
                    data-blc="toggle" data-category="<?php echo esc_attr($blc_key); ?>"
                    aria-labelledby="<?php echo esc_attr($blc_id); ?>"
                    aria-describedby="<?php echo esc_attr($blc_id); ?>-desc">
                <span class="blc-switch__track" aria-hidden="true"></span>
            </label>
        <?php endif; ?>
    </div>
    <p class="blc-cat__desc" id="<?php echo esc_attr($blc_id); ?>-desc"><?php echo esc_html($category['description']); ?></p>
    <?php if ($category['services']) : ?>
        <details class="blc-cat__services">
            <summary><?php echo esc_html($consent->text('preferences', 'services')); ?> (<?php echo count($category['services']); ?>)</summary>
            <ul class="blc-services">
                <?php foreach ($category['services'] as $blc_service) : ?>
                    <?php $consent->part('service-row', ['service' => $blc_service]); ?>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</section>
