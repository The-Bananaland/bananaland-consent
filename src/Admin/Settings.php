<?php

namespace Bananaland\Consent\Admin;

use Bananaland\Consent\Config;
use Bananaland\Consent\Services\Presets;

/**
 * Sanitizes settings submitted from the admin screens or imported as JSON.
 */
final class Settings
{
    public const SECTIONS = [
        'banner' => ['banner', 'colors'],
        'text' => ['texts', 'categories'],
        'services' => ['services'],
        'settings' => ['privacy_page', 'cookie_page', 'regions', 'embeds', 'consent_mode', 'log', 'expiry', 'uninstall'],
        'import' => ['policy_version'],
    ];

    /**
     * Sanitize the keys belonging to $sections from $input.
     */
    public static function sanitize(array $input, array $sections): array
    {
        $defaults = Config::defaults();
        $out = [];
        $keys = [];
        foreach ($sections as $section) {
            $keys = array_merge($keys, self::SECTIONS[$section] ?? []);
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            switch ($key) {
                case 'policy_version':
                    $out[$key] = substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string) $value), 0, 32) ?: '1';
                    break;
                case 'privacy_page':
                case 'cookie_page':
                    $out[$key] = absint($value);
                    break;
                case 'expiry':
                    foreach (Config::MODES as $mode) {
                        $out[$key][$mode] = min(395, max(1, absint($value[$mode] ?? $defaults['expiry'][$mode])));
                    }
                    break;
                case 'consent_mode':
                    $out[$key] = [
                        'enabled' => !empty($value['enabled']),
                        'mode' => ($value['mode'] ?? '') === 'advanced' ? 'advanced' : 'basic',
                        'ads_data_redaction' => !empty($value['ads_data_redaction']),
                        'url_passthrough' => !empty($value['url_passthrough']),
                    ];
                    break;
                case 'embeds':
                    $out[$key] = ['enabled' => !empty($value['enabled']), 'generic_iframes' => !empty($value['generic_iframes'])];
                    break;
                case 'log':
                    $out[$key] = ['enabled' => !empty($value['enabled']), 'retention_days' => min(3650, max(30, absint($value['retention_days'] ?? 1095)))];
                    break;
                case 'uninstall':
                    $out[$key] = ['delete_data' => !empty($value['delete_data'])];
                    break;
                case 'banner':
                    $out[$key] = [
                        'layout' => in_array($value['layout'] ?? '', ['bar', 'box', 'modal'], true) ? $value['layout'] : 'bar',
                        'position' => in_array($value['position'] ?? '', ['bottom', 'top', 'bottom-left', 'bottom-right'], true) ? $value['position'] : 'bottom',
                        'floating_button' => !empty($value['floating_button']),
                        'floating_position' => ($value['floating_position'] ?? '') === 'bottom-right' ? 'bottom-right' : 'bottom-left',
                        'opt_out_button' => !empty($value['opt_out_button']),
                    ];
                    break;
                case 'colors':
                    foreach (array_keys($defaults['colors']) as $color) {
                        $out[$key][$color] = sanitize_hex_color((string) ($value[$color] ?? '')) ?: $defaults['colors'][$color];
                    }
                    break;
                case 'texts':
                    foreach ($defaults['texts'] as $group => $strings) {
                        foreach (array_keys($strings) as $string) {
                            if (isset($value[$group][$string])) {
                                $out[$key][$group][$string] = sanitize_textarea_field((string) $value[$group][$string]);
                            }
                        }
                    }
                    break;
                case 'categories':
                    foreach (Config::CATEGORIES as $category) {
                        $out[$key][$category] = [
                            'label' => sanitize_text_field((string) ($value[$category]['label'] ?? $defaults['categories'][$category]['label'])),
                            'description' => sanitize_textarea_field((string) ($value[$category]['description'] ?? $defaults['categories'][$category]['description'])),
                        ];
                    }
                    break;
                case 'services':
                    $out[$key] = self::services(is_array($value) ? $value : []);
                    break;
                case 'regions':
                    $out[$key] = [
                        'us' => in_array($value['us'] ?? '', Config::MODES, true) ? $value['us'] : 'opt-out',
                        'other' => in_array($value['other'] ?? '', Config::MODES, true) ? $value['other'] : 'notice',
                        'unknown_mode' => in_array($value['unknown_mode'] ?? '', Config::MODES, true) ? $value['unknown_mode'] : 'opt-in',
                        'timezone_fallback' => !empty($value['timezone_fallback']),
                        'overrides' => self::overrides($value['overrides'] ?? []),
                    ];
                    break;
            }
        }
        return $out;
    }

    private static function services(array $rows): array
    {
        $presets = Presets::all();
        $can_html = current_user_can('unfiltered_html');
        $existing = [];
        foreach ((array) (Config::saved()['services'] ?? []) as $row) {
            if (!empty($row['uid'])) {
                $existing[$row['uid']] = $row;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['preset']) || !isset($presets[$row['preset']])) {
                continue;
            }
            $preset = $presets[$row['preset']];
            $uid = sanitize_key((string) ($row['uid'] ?? '')) ?: $row['preset'] . '-' . strtolower(wp_generate_password(6, false));
            $settings = [];
            foreach (array_keys($preset['fields']) as $field) {
                $settings[$field] = sanitize_text_field((string) ($row['settings'][$field] ?? ''));
            }
            if ($row['preset'] === 'custom') {
                foreach (['head', 'body'] as $slot) {
                    if ($can_html) {
                        $settings[$slot] = (string) ($row['settings'][$slot] ?? '');
                    } else {
                        // Without unfiltered_html, keep whatever an administrator saved before.
                        $settings[$slot] = (string) ($existing[$uid]['settings'][$slot] ?? '');
                    }
                }
            }
            $out[] = [
                'uid' => $uid,
                'enabled' => !empty($row['enabled']),
                'preset' => $row['preset'],
                'label' => sanitize_text_field((string) ($row['label'] ?? '')),
                'provider' => sanitize_text_field((string) ($row['provider'] ?? '')),
                'description' => sanitize_textarea_field((string) ($row['description'] ?? '')),
                'category' => in_array($row['category'] ?? '', Config::CATEGORIES, true) ? $row['category'] : $preset['category'],
                'privacy_url' => esc_url_raw((string) ($row['privacy_url'] ?? '')),
                'settings' => $settings,
                'cookies' => self::cookies($row['cookies'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Cookies arrive as "name | duration | purpose" lines (admin) or a list of arrays (import).
     */
    public static function cookies(mixed $value): array
    {
        $rows = [];
        if (is_string($value)) {
            foreach (preg_split('/\r\n|\r|\n/', $value) as $line) {
                $parts = array_map('trim', explode('|', $line));
                if ($parts[0] !== '') {
                    $rows[] = ['name' => $parts[0], 'duration' => $parts[1] ?? '', 'purpose' => $parts[2] ?? ''];
                }
            }
        } elseif (is_array($value)) {
            $rows = array_filter($value, 'is_array');
        }
        return array_values(array_map(static fn($c) => [
            'name' => preg_replace('/[^A-Za-z0-9_\-.*]/', '', (string) ($c['name'] ?? '')),
            'duration' => sanitize_text_field((string) ($c['duration'] ?? '')),
            'purpose' => sanitize_text_field((string) ($c['purpose'] ?? '')),
        ], array_filter($rows, static fn($c) => !empty($c['name']))));
    }

    /**
     * Overrides arrive as "US-CA = opt-in" lines (admin) or a map (import).
     */
    public static function overrides(mixed $value): array
    {
        $pairs = [];
        if (is_string($value)) {
            foreach (preg_split('/\r\n|\r|\n/', $value) as $line) {
                if (str_contains($line, '=')) {
                    [$code, $mode] = array_map('trim', explode('=', $line, 2));
                    $pairs[$code] = $mode;
                }
            }
        } elseif (is_array($value)) {
            $pairs = $value;
        }
        $out = [];
        foreach ($pairs as $code => $mode) {
            $code = strtoupper((string) $code);
            if (preg_match('/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', $code) && in_array($mode, Config::MODES, true)) {
                $out[$code] = $mode;
            }
        }
        return $out;
    }

    /**
     * WCAG contrast ratio between two hex colors.
     */
    public static function contrast(string $a, string $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);
        return round((max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05), 2);
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $rgb = array_map(static function ($c) {
            $c = hexdec($c) / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 0, 6), 2));
        return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
    }
}
