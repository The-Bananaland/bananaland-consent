<?php

namespace Bananaland\Consent\Regions;

/**
 * Works out the visitor's country and subdivision from host-provided headers.
 */
final class Resolver
{
    /**
     * @return array{country: ?string, region: ?string, source: string}
     */
    public static function resolve(): array
    {
        $result = self::test_override()
            ?? self::from_headers('HTTP_GEOIP_COUNTRY_CODE', 'HTTP_GEOIP_REGION', 'wpengine')
            ?? self::from_headers('HTTP_CF_IPCOUNTRY', 'HTTP_CF_REGION_CODE', 'cloudflare')
            ?? ['country' => null, 'region' => null, 'source' => 'none'];

        /**
         * Filters the resolved visitor location, e.g. to plug in another geo provider.
         *
         * @param array{country: ?string, region: ?string, source: string} $result
         */
        return apply_filters('blc_resolve_region', $result);
    }

    /**
     * Lets local and staging environments force a location: `X-BLC-Test-Region: US-CA` or `?blc_test_region=DE`.
     */
    private static function test_override(): ?array
    {
        $allowed = (defined('BLC_ALLOW_TEST_REGION') && BLC_ALLOW_TEST_REGION) || in_array(wp_get_environment_type(), ['local', 'development'], true);
        if (!$allowed) {
            return null;
        }
        $raw = $_SERVER['HTTP_X_BLC_TEST_REGION'] ?? ($_GET['blc_test_region'] ?? null);
        if (!is_string($raw) || !preg_match('/^([A-Za-z]{2})(?:-([A-Za-z0-9]{1,3}))?$/', $raw, $m)) {
            return null;
        }
        return ['country' => strtoupper($m[1]), 'region' => isset($m[2]) ? strtoupper($m[2]) : null, 'source' => 'test'];
    }

    private static function from_headers(string $country_key, string $region_key, string $source): ?array
    {
        $country = self::clean($_SERVER[$country_key] ?? null);
        if (!$country) {
            return null;
        }
        $region = $_SERVER[$region_key] ?? null;
        $region = is_string($region) && preg_match('/^[A-Za-z0-9]{1,3}$/', $region) ? strtoupper($region) : null;
        return ['country' => $country, 'region' => $region, 'source' => $source];
    }

    private static function clean(mixed $code): ?string
    {
        if (!is_string($code) || !preg_match('/^[A-Za-z]{2}$/', $code)) {
            return null;
        }
        $code = strtoupper($code);
        // Cloudflare: XX = unknown, T1 = Tor.
        return in_array($code, ['XX', 'T1'], true) ? null : $code;
    }
}
