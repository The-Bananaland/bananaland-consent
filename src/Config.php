<?php

namespace Bananaland\Consent;

/**
 * Effective configuration: defaults <- saved option <- theme consent/config.php <- `blc_config` filter.
 */
final class Config
{
    public const OPTION = 'blc_settings';

    public const CATEGORIES = ['necessary', 'preferences', 'analytics', 'marketing'];

    public const MODES = ['opt-in', 'opt-out', 'notice'];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $config = self::merge(self::defaults(), self::saved());
            $config = self::merge($config, self::theme());
            /**
             * Filters the effective consent configuration.
             *
             * @param array $config
             */
            self::$cache = apply_filters('blc_config', $config);
        }
        return self::$cache;
    }

    /**
     * Read a value by dotted path, e.g. `banner.layout`.
     */
    public static function get(string $path, mixed $default = null): mixed
    {
        $value = self::all();
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }

    public static function saved(): array
    {
        $saved = get_option(self::OPTION, []);
        return is_array($saved) ? $saved : [];
    }

    /**
     * Settings supplied by the theme kit's config.php (returns an array).
     */
    public static function theme(): array
    {
        $file = Kit::file('config.php');
        if (!$file) {
            return [];
        }
        $config = (static function (string $path) {
            return include $path;
        })($file['path']);
        return is_array($config) ? $config : [];
    }

    /**
     * Deep merge for associative arrays. Lists (services, cookie tables) are replaced whole.
     */
    public static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value) && !array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    public static function defaults(): array
    {
        return [
            'policy_version' => '1',
            'privacy_page' => 0,
            'cookie_page' => 0,
            'banner' => [
                'layout' => 'bar', // bar | box | modal
                'position' => 'bottom', // bottom | top (bar); bottom-left | bottom-right (box)
                'floating_button' => false,
                'floating_position' => 'bottom-left',
            ],
            'colors' => [
                'background' => '#ffffff',
                'text' => '#1e1e1e',
                'primary_bg' => '#1e1e1e',
                'primary_text' => '#ffffff',
                'secondary_bg' => '#ffffff',
                'secondary_text' => '#1e1e1e',
                'accent' => '#1e1e1e',
            ],
            'categories' => [
                'necessary' => [
                    'label' => __('Strictly necessary', 'bananaland-consent'),
                    'description' => __('Required for the site to work, such as keeping it secure and remembering your privacy choices. These cannot be turned off.', 'bananaland-consent'),
                ],
                'preferences' => [
                    'label' => __('Preferences & embedded content', 'bananaland-consent'),
                    'description' => __('Remember your settings and load content hosted by other services, such as videos and maps.', 'bananaland-consent'),
                ],
                'analytics' => [
                    'label' => __('Analytics', 'bananaland-consent'),
                    'description' => __('Help us understand how visitors use the site so we can improve it.', 'bananaland-consent'),
                ],
                'marketing' => [
                    'label' => __('Marketing', 'bananaland-consent'),
                    'description' => __('Used by advertising partners to measure campaigns and show relevant ads on other sites. Under some U.S. state laws this may be considered selling or sharing personal information, or targeted advertising.', 'bananaland-consent'),
                ],
            ],
            'texts' => [
                'opt-in' => [
                    'title' => __('We value your privacy', 'bananaland-consent'),
                    'body' => __('We use cookies and similar technologies to run this site, understand how it is used, and support our marketing. Choose which ones you allow. You can change your mind at any time from the Privacy Choices link at the bottom of every page.', 'bananaland-consent'),
                    'accept' => __('Accept all', 'bananaland-consent'),
                    'reject' => __('Reject all', 'bananaland-consent'),
                    'customize' => __('Customize', 'bananaland-consent'),
                ],
                'opt-out' => [
                    'title' => __('Your privacy choices', 'bananaland-consent'),
                    'body' => __('We and our partners use cookies for analytics and advertising. Under some U.S. state laws this may be considered selling or sharing personal information, or targeted advertising. You can opt out at any time.', 'bananaland-consent'),
                    'accept' => __('OK', 'bananaland-consent'),
                    'opt_out' => __('Opt out of sale, sharing & targeted ads', 'bananaland-consent'),
                    'customize' => __('Customize', 'bananaland-consent'),
                ],
                'notice' => [
                    'title' => __('Cookies on this site', 'bananaland-consent'),
                    'body' => __('We use cookies to run this site and to understand how it is used.', 'bananaland-consent'),
                    'accept' => __('OK', 'bananaland-consent'),
                    'customize' => __('Customize', 'bananaland-consent'),
                ],
                'preferences' => [
                    'title' => __('Privacy preferences', 'bananaland-consent'),
                    'intro' => __('Choose which categories of cookies and similar technologies you allow. Strictly necessary cookies are always on.', 'bananaland-consent'),
                    'save' => __('Save choices', 'bananaland-consent'),
                    'accept' => __('Accept all', 'bananaland-consent'),
                    'reject' => __('Reject all', 'bananaland-consent'),
                    'close' => __('Close', 'bananaland-consent'),
                    'always_active' => __('Always active', 'bananaland-consent'),
                    'services' => __('Services', 'bananaland-consent'),
                    'gpc_notice' => __('Your browser sent a Global Privacy Control signal. We have honored it by opting you out of the sale or sharing of personal information and targeted advertising.', 'bananaland-consent'),
                    'consent_id' => __('Your consent ID', 'bananaland-consent'),
                    'policy_link' => __('Privacy policy', 'bananaland-consent'),
                    'cookie_link' => __('Cookie list', 'bananaland-consent'),
                ],
                'links' => [
                    'preferences' => __('Cookie preferences', 'bananaland-consent'),
                    'opt_out' => __('Your Privacy Choices', 'bananaland-consent'),
                ],
                'embed' => [
                    'body' => __('This content is hosted by %s, which may set cookies and collect data about your visit.', 'bananaland-consent'),
                    'load_once' => __('Load once', 'bananaland-consent'),
                    'always' => __('Always allow', 'bananaland-consent'),
                ],
            ],
            'services' => [],
            'regions' => [
                'unknown_mode' => 'opt-in',
                'timezone_fallback' => true,
                'overrides' => [],
            ],
            'expiry' => [
                'opt-in' => 182,
                'opt-out' => 365,
                'notice' => 365,
            ],
            'consent_mode' => [
                'enabled' => true,
                'mode' => 'basic', // basic | advanced
                'ads_data_redaction' => true,
                'url_passthrough' => false,
            ],
            'embeds' => [
                'enabled' => true,
                'generic_iframes' => true,
            ],
            'log' => [
                'enabled' => true,
                'retention_days' => 1095,
            ],
            'uninstall' => [
                'delete_data' => false,
            ],
        ];
    }
}
