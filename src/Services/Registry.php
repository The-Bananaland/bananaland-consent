<?php

namespace Bananaland\Consent\Services;

use Bananaland\Consent\Config;

/**
 * Configured services, validated and rendered from presets.
 */
final class Registry
{
    private static ?array $services = null;

    /** @var array<int, string> */
    private static array $errors = [];

    public static function reset(): void
    {
        self::$services = null;
        self::$errors = [];
    }

    /**
     * @return array<string, array> keyed by service uid
     */
    public static function all(): array
    {
        if (self::$services !== null) {
            return self::$services;
        }
        self::$services = [];
        foreach ((array) Config::get('services', []) as $index => $row) {
            $service = self::build(is_array($row) ? $row : [], (int) $index);
            if ($service) {
                self::$services[$service['uid']] = $service;
            }
        }
        return self::$services;
    }

    /**
     * Validation problems found while building services, for admin notices.
     */
    public static function errors(): array
    {
        self::all();
        return self::$errors;
    }

    public static function by_category(string $category): array
    {
        return array_filter(self::all(), static fn($s) => $s['category'] === $category);
    }

    /**
     * Cookie names per category, used by the front end to clear cookies when consent is withdrawn.
     *
     * @return array<string, array<int, string>>
     */
    public static function cookie_names_by_category(): array
    {
        $out = array_fill_keys(Config::CATEGORIES, []);
        foreach (self::all() as $service) {
            foreach ($service['cookies'] as $cookie) {
                $out[$service['category']][] = $cookie['name'];
            }
        }
        return array_map(static fn($names) => array_values(array_unique($names)), $out);
    }

    /**
     * Service uids per category, stored with a choice so new services trigger a re-prompt.
     *
     * @return array<string, array<int, string>>
     */
    public static function uids_by_category(): array
    {
        $out = array_fill_keys(Config::CATEGORIES, []);
        foreach (self::all() as $uid => $service) {
            $out[$service['category']][] = $uid;
        }
        return $out;
    }

    private static function build(array $row, int $index): ?array
    {
        if (isset($row['enabled']) && !$row['enabled']) {
            return null;
        }
        $preset_id = (string) ($row['preset'] ?? '');
        $preset = Presets::get($preset_id);
        if (!$preset) {
            self::$errors[] = sprintf(__('Service %1$d uses an unknown preset "%2$s".', 'bananaland-consent'), $index + 1, $preset_id);
            return null;
        }

        $settings = is_array($row['settings'] ?? null) ? $row['settings'] : [];
        foreach ($preset['fields'] as $key => $field) {
            $value = trim((string) ($settings[$key] ?? ''));
            if ($value === '' || !preg_match($field['pattern'], $value)) {
                self::$errors[] = sprintf(__('%1$s: "%2$s" is not a valid %3$s.', 'bananaland-consent'), $preset['label'], $value, $field['label']);
                return null;
            }
            $settings[$key] = $value;
        }

        $category = (string) ($row['category'] ?? $preset['category']);
        if (!in_array($category, Config::CATEGORIES, true)) {
            $category = $preset['category'];
        }

        $label = (string) ($row['label'] ?? '') ?: $preset['label'];
        $uid = (string) ($row['uid'] ?? '') ?: $preset_id . '-' . substr(md5(wp_json_encode($settings) ?: ''), 0, 6);

        $cookies = $preset['cookies'];
        if (!empty($row['cookies']) && is_array($row['cookies'])) {
            $cookies = array_values(array_filter(array_map(static fn($c) => is_array($c) && !empty($c['name']) ? [
                'name' => (string) $c['name'],
                'duration' => (string) ($c['duration'] ?? ''),
                'purpose' => (string) ($c['purpose'] ?? ''),
            ] : null, $row['cookies'])));
        }

        $rendered = ($preset['render'])($settings);

        return [
            'uid' => sanitize_key($uid),
            'preset' => $preset_id,
            'label' => $label,
            'provider' => (string) ($row['provider'] ?? '') ?: $preset['provider'],
            'description' => (string) ($row['description'] ?? ''),
            'category' => $category,
            'settings' => $settings,
            'cookies' => $cookies,
            'privacy_url' => (string) ($row['privacy_url'] ?? '') ?: $preset['privacy_url'],
            'google' => (bool) $preset['google'],
            'head' => $rendered['head'],
            'body' => $rendered['body'],
        ];
    }
}
