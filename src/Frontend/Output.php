<?php

namespace Bananaland\Consent\Frontend;

use Bananaland\Consent\Config;
use Bananaland\Consent\Kit;
use Bananaland\Consent\Services\Registry;
use Bananaland\Consent\Services\Renderer;
use Bananaland\Consent\Templates\Locator;

/**
 * Everything printed on public pages: Consent Mode defaults, held services, banner markup, assets.
 */
final class Output
{
    public static function register(): void
    {
        add_action('wp', static function () {
            if (!self::enabled()) {
                return;
            }
            add_action('wp_head', [self::class, 'head_bootstrap'], -100);
            add_action('wp_head', [self::class, 'head_services'], 2);
            add_action('wp_body_open', [self::class, 'body_services'], 1);
            add_action('wp_footer', [self::class, 'footer'], 5);
            add_action('wp_enqueue_scripts', [self::class, 'assets']);
        });
    }

    public static function enabled(): bool
    {
        if ((defined('BLC_DISABLE') && BLC_DISABLE) || is_admin() || wp_doing_ajax() || is_feed() || is_embed() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return false;
        }
        if (isset($_GET['blc_off']) && current_user_can('manage_options')) { // phpcs:ignore WordPress.Security.NonceVerification
            return false;
        }
        /**
         * Filters whether consent output runs on this request.
         */
        return (bool) apply_filters('blc_enabled', true);
    }

    /**
     * Admin-only scan mode: page behaves as a first-time visitor with no choice made.
     */
    public static function scan_mode(): bool
    {
        return isset($_GET['blc_scan']) && current_user_can('manage_options'); // phpcs:ignore WordPress.Security.NonceVerification
    }

    public static function client_config(): array
    {
        $config = [
            'version' => BLC_VERSION,
            'policyVersion' => (string) Config::get('policy_version'),
            'rest' => [
                'region' => esc_url_raw(rest_url('blc/v1/region')),
                'consent' => esc_url_raw(rest_url('blc/v1/consent')),
            ],
            'categories' => Config::CATEGORIES,
            'expiry' => (array) Config::get('expiry'),
            'consentMode' => [
                'enabled' => (bool) Config::get('consent_mode.enabled'),
                'mode' => (string) Config::get('consent_mode.mode'),
                'adsDataRedaction' => (bool) Config::get('consent_mode.ads_data_redaction'),
                'urlPassthrough' => (bool) Config::get('consent_mode.url_passthrough'),
            ],
            'services' => Registry::uids_by_category(),
            'cookies' => Registry::cookie_names_by_category(),
            'unknownMode' => (string) Config::get('regions.unknown_mode'),
            'timezoneFallback' => (bool) Config::get('regions.timezone_fallback'),
            'layout' => (string) Config::get('banner.layout'),
            'floatingButton' => (bool) Config::get('banner.floating_button'),
            'log' => (bool) Config::get('log.enabled'),
            'scan' => self::scan_mode(),
            'secure' => is_ssl(),
        ];
        return apply_filters('blc_client_config', $config);
    }

    public static function head_bootstrap(): void
    {
        $json = wp_json_encode(self::client_config(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        // Inline and first in <head> so Consent Mode defaults exist before any Google tag.
        $js = <<<JS
(function(){var cfg=window.blcConfig={$json};window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments);};var c=null;if(!cfg.scan){try{var m=document.cookie.match(/(?:^|; )blc_consent=([^;]*)/);if(m){var s=JSON.parse(decodeURIComponent(m[1]));if(s&&String(s.pv)===cfg.policyVersion&&s.c){c=s.c;}}}catch(e){}}cfg.initial=c;if(!cfg.consentMode.enabled){return;}var g=function(k){return c&&c.indexOf(k)>-1?'granted':'denied';};gtag('consent','default',{ad_storage:g('marketing'),ad_user_data:g('marketing'),ad_personalization:g('marketing'),analytics_storage:g('analytics'),functionality_storage:g('preferences'),personalization_storage:g('preferences'),security_storage:'granted',wait_for_update:500});if(cfg.consentMode.adsDataRedaction){gtag('set','ads_data_redaction',true);}if(cfg.consentMode.urlPassthrough){gtag('set','url_passthrough',true);}})();
JS;
        wp_print_inline_script_tag($js, ['id' => 'blc-bootstrap', 'data-cfasync' => 'false', 'data-no-optimize' => '1']);
    }

    public static function head_services(): void
    {
        echo Renderer::render('head'); // phpcs:ignore WordPress.Security.EscapeOutput -- validated service snippets.
    }

    public static function body_services(): void
    {
        echo Renderer::render('body'); // phpcs:ignore WordPress.Security.EscapeOutput
        self::$body_done = true;
    }

    private static bool $body_done = false;

    public static function footer(): void
    {
        if (!self::$body_done) {
            // Theme without wp_body_open(): print body services in the footer instead.
            echo Renderer::render('body'); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '<div class="blc-root" data-blc="root">';
        echo Locator::render('banner'); // phpcs:ignore WordPress.Security.EscapeOutput
        echo Locator::render('preferences'); // phpcs:ignore WordPress.Security.EscapeOutput
        echo Locator::render('floating-button'); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '</div>';
    }

    public static function assets(): void
    {
        wp_enqueue_script('blc-consent', BLC_URL . 'assets/consent.js', [], BLC_VERSION, ['strategy' => 'defer', 'in_footer' => false]);

        $kit_css = Kit::file('consent.css');
        if ($kit_css) {
            wp_enqueue_style('blc-consent', $kit_css['url'], [], self::mtime($kit_css['path']));
        } else {
            wp_enqueue_style('blc-consent', BLC_URL . 'assets/consent.css', [], BLC_VERSION);
            wp_add_inline_style('blc-consent', self::color_tokens());
        }

        $tokens = Kit::file('tokens.css');
        if ($tokens) {
            wp_enqueue_style('blc-consent-tokens', $tokens['url'], ['blc-consent'], self::mtime($tokens['path']));
        }
    }

    private static function mtime(string $path): string
    {
        return (string) (@filemtime($path) ?: BLC_VERSION);
    }

    /**
     * Admin color settings as CSS custom properties.
     */
    public static function color_tokens(): string
    {
        $map = [
            'background' => '--blc-bg',
            'text' => '--blc-text',
            'primary_bg' => '--blc-primary-bg',
            'primary_text' => '--blc-primary-text',
            'secondary_bg' => '--blc-secondary-bg',
            'secondary_text' => '--blc-secondary-text',
            'accent' => '--blc-accent',
        ];
        $css = '';
        foreach ($map as $key => $var) {
            $value = sanitize_hex_color((string) Config::get("colors.$key"));
            if ($value) {
                $css .= "$var:$value;";
            }
        }
        return $css ? ':root{' . $css . '}' : '';
    }
}
