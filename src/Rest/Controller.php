<?php

namespace Bananaland\Consent\Rest;

use Bananaland\Consent\Config;
use Bananaland\Consent\ConsentLog\Anonymizer;
use Bananaland\Consent\ConsentLog\Repository;
use Bananaland\Consent\Regions\Resolver;
use Bananaland\Consent\Regions\Rules;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Public endpoints used by consent.js. POST only, so page caches never store them.
 */
final class Controller
{
    public const RATE_LIMIT = 20; // per IP per window

    public const RATE_WINDOW = 600;

    public static function register(): void
    {
        add_action('rest_api_init', static function () {
            register_rest_route('blc/v1', '/region', [
                'methods' => 'POST',
                'callback' => [self::class, 'region'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route('blc/v1', '/consent', [
                'methods' => 'POST',
                'callback' => [self::class, 'consent'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public static function region(): WP_REST_Response
    {
        $location = Resolver::resolve();
        $mode = Rules::mode($location['country'], $location['region']);

        if (get_option('blc_geo_source') !== $location['source']) {
            update_option('blc_geo_source', $location['source'], false);
        }

        $response = new WP_REST_Response([
            'country' => $location['country'],
            'region' => $location['region'],
            'mode' => $location['country'] ? $mode : 'unknown',
            'fallback' => $mode,
            'source' => $location['source'],
        ]);
        $response->set_headers(wp_get_nocache_headers());
        return $response;
    }

    public static function consent(WP_REST_Request $request): WP_REST_Response
    {
        if (!Config::get('log.enabled')) {
            return new WP_REST_Response(['logged' => false], 200);
        }

        $record = self::validate((array) $request->get_json_params());
        if (is_string($record)) {
            return new WP_REST_Response(['error' => $record], 400);
        }

        $salt = wp_salt('auth');
        $ip_hash = Anonymizer::hash(Anonymizer::truncate_ip(self::client_ip()), $salt);
        if (!self::within_rate_limit($ip_hash)) {
            return new WP_REST_Response(['error' => 'rate_limited'], 429);
        }

        $record['ip_hash'] = $ip_hash;
        $record['ua_hash'] = Anonymizer::hash(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512), $salt);

        $ok = Repository::insert($record);
        do_action('blc_consent_logged', $record);

        return new WP_REST_Response(['logged' => $ok], $ok ? 201 : 500);
    }

    /**
     * @return array|string Clean record, or an error code.
     */
    public static function validate(array $body): array|string
    {
        $id = (string) ($body['id'] ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            return 'invalid_id';
        }
        $categories = $body['categories'] ?? null;
        if (!is_array($categories) || array_diff($categories, Config::CATEGORIES)) {
            return 'invalid_categories';
        }
        $mode = (string) ($body['mode'] ?? '');
        if (!in_array($mode, Config::MODES, true)) {
            return 'invalid_mode';
        }
        $method = (string) ($body['method'] ?? '');
        if (!in_array($method, Repository::METHODS, true)) {
            return 'invalid_method';
        }
        $url = (string) ($body['url'] ?? '');
        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        $categories = array_values(array_unique(array_merge(['necessary'], $categories)));
        sort($categories);

        return [
            'consent_id' => $id,
            'categories' => $categories,
            'policy_version' => (string) ($body['policyVersion'] ?? ''),
            'mode' => $mode,
            'method' => $method,
            'gpc' => !empty($body['gpc']),
            'region' => preg_match('/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', (string) ($body['region'] ?? '')) ? (string) $body['region'] : '',
            'url' => $path,
        ];
    }

    public static function client_ip(): string
    {
        $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        return (string) apply_filters('blc_client_ip', $ip);
    }

    private static function within_rate_limit(string $ip_hash): bool
    {
        if ($ip_hash === '') {
            return true;
        }
        $key = 'blc_rl_' . substr($ip_hash, 0, 32);
        $count = (int) get_transient($key);
        /**
         * Filters how many choices one (hashed) IP may log per 10 minutes.
         */
        if ($count >= (int) apply_filters('blc_rate_limit', self::RATE_LIMIT)) {
            return false;
        }
        set_transient($key, $count + 1, self::RATE_WINDOW);
        return true;
    }
}
