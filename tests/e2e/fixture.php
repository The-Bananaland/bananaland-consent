<?php
/**
 * E2E fixture. Loaded only in local environments when a request carries X-BLC-E2E (see Plugin::boot).
 * Injects test services and a page with an embed, without touching the database.
 */

defined('ABSPATH') || exit;

add_filter('blc_config', static function (array $config): array {
    $config['services'] = [
        ['uid' => 'e2e-ga4', 'preset' => 'ga4', 'settings' => ['id' => 'G-TEST000001']],
        ['uid' => 'e2e-meta', 'preset' => 'meta_pixel', 'settings' => ['id' => '000000000000001']],
    ];
    $layout = $_SERVER['HTTP_X_BLC_E2E_LAYOUT'] ?? '';
    if (in_array($layout, ['bar', 'box', 'modal'], true)) {
        $config['banner']['layout'] = $layout;
    }
    $config['banner']['floating_button'] = true;
    return $config;
});

// Every test run logs choices from the same IP.
add_filter('blc_rate_limit', static fn() => 100000);

add_action('template_redirect', static function () {
    if (($_GET['blc_e2e'] ?? '') !== 'embed') {
        return;
    }
    status_header(200);
    get_header();
    echo '<main id="main" class="bl-container" style="padding:4rem 0">';
    echo apply_filters('the_content', '<p>Embed test</p><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315" title="Test video"></iframe>'); // phpcs:ignore
    echo '<p>' . blc_preferences_link() . '</p>'; // phpcs:ignore
    echo '</main>';
    get_footer();
    exit;
});
