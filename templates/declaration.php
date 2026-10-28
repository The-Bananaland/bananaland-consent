<?php
/**
 * Cookie declaration ([blc_cookie_declaration] shortcode and block). Generated from the service registry.
 *
 * @version 0.1.0
 * @var \Bananaland\Consent\Templates\View $consent
 */

defined('ABSPATH') || exit;
?>
<div class="blc-declaration">
    <?php foreach ($consent->categories() as $blc_category) : ?>
        <section class="blc-declaration__category">
            <h3><?php echo esc_html($blc_category['label']); ?></h3>
            <p><?php echo esc_html($blc_category['description']); ?></p>
            <?php if ($blc_category['services']) : ?>
                <ul class="blc-services">
                    <?php foreach ($blc_category['services'] as $blc_service) : ?>
                        <?php $consent->part('service-row', ['service' => $blc_service]); ?>
                    <?php endforeach; ?>
                </ul>
            <?php elseif ($blc_category['key'] === 'necessary') : ?>
                <table class="blc-cookies">
                    <thead><tr><th scope="col"><?php esc_html_e('Cookie', 'bananaland-consent'); ?></th><th scope="col"><?php esc_html_e('Duration', 'bananaland-consent'); ?></th><th scope="col"><?php esc_html_e('Purpose', 'bananaland-consent'); ?></th></tr></thead>
                    <tbody><tr><td><code>blc_consent</code></td><td><?php esc_html_e('Up to 1 year', 'bananaland-consent'); ?></td><td><?php esc_html_e('Stores your privacy choices.', 'bananaland-consent'); ?></td></tr></tbody>
                </table>
            <?php else : ?>
                <p class="blc-declaration__none"><?php esc_html_e('No services in this category.', 'bananaland-consent'); ?></p>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <p><button type="button" class="blc-btn blc-btn--primary" data-blc-action="open-preferences"><?php echo esc_html($consent->text('links', 'preferences')); ?></button></p>
</div>
