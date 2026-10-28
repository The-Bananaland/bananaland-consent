<?php
/**
 * One service in a category list.
 *
 * @version 0.1.0
 * @var \Bananaland\Consent\Templates\View $consent
 * @var array $service
 */

defined('ABSPATH') || exit;
?>
<li class="blc-service">
    <p class="blc-service__name">
        <strong><?php echo esc_html($service['label']); ?></strong>
        <?php if ($service['provider']) : ?><span class="blc-service__provider">(<?php echo esc_html($service['provider']); ?>)</span><?php endif; ?>
        <?php if ($service['privacy_url']) : ?>
            <a href="<?php echo esc_url($service['privacy_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Privacy policy', 'bananaland-consent'); ?><span class="blc-sr-only"> <?php esc_html_e('(opens in a new tab)', 'bananaland-consent'); ?></span></a>
        <?php endif; ?>
    </p>
    <?php if ($service['description']) : ?><p class="blc-service__desc"><?php echo esc_html($service['description']); ?></p><?php endif; ?>
    <?php if ($service['cookies']) : ?>
        <table class="blc-cookies">
            <thead><tr><th scope="col"><?php esc_html_e('Cookie', 'bananaland-consent'); ?></th><th scope="col"><?php esc_html_e('Duration', 'bananaland-consent'); ?></th><th scope="col"><?php esc_html_e('Purpose', 'bananaland-consent'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($service['cookies'] as $blc_cookie) : ?>
                    <tr><td><code><?php echo esc_html($blc_cookie['name']); ?></code></td><td><?php echo esc_html($blc_cookie['duration']); ?></td><td><?php echo esc_html($blc_cookie['purpose']); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</li>
