<?php

namespace Bananaland\Consent\Updater;

/**
 * Offers updates from the latest GitHub release of The-Bananaland/bananaland-consent.
 *
 * The release must carry an asset named bananaland-consent.zip (built by .github/workflows/release.yml).
 */
final class GitHubUpdater
{
    public const SLUG = 'bananaland-consent';

    public const ASSET = 'bananaland-consent.zip';

    public const CACHE_KEY = 'blc_github_release';

    public static function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject_update']);
        add_filter('plugins_api', [self::class, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [self::class, 'fix_folder_name'], 10, 4);
        add_filter('auto_update_plugin', [self::class, 'auto_update'], 10, 2);
    }

    public static function basename(): string
    {
        return plugin_basename(BLC_FILE);
    }

    /**
     * Never update a development checkout: WordPress would delete the folder, and with a symlink that is the git repo.
     */
    public static function is_dev_install(string $dir = BLC_DIR): bool
    {
        $dir = rtrim($dir, '/');
        return is_link($dir) || file_exists($dir . '/.git') || (defined('BLC_DISABLE_UPDATER') && BLC_DISABLE_UPDATER);
    }

    public static function normalize_version(string $tag): string
    {
        return ltrim(trim($tag), 'vV');
    }

    /**
     * @return array{version: string, package: string, url: string, body: string, published: string}|null
     */
    public static function latest_release(bool $force = false): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (!$force && is_array($cached)) {
            return $cached['version'] ? $cached : null;
        }

        $response = wp_remote_get('https://api.github.com/repos/' . BLC_REPO . '/releases/latest', [
            'timeout' => 8,
            'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'bananaland-consent/' . BLC_VERSION],
        ]);
        $release = self::parse_release(
            wp_remote_retrieve_response_code($response) === 200 ? json_decode((string) wp_remote_retrieve_body($response), true) : null
        );

        // Cache failures briefly too, so a GitHub outage doesn't slow down wp-admin.
        set_site_transient(self::CACHE_KEY, $release ?? ['version' => ''], $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
        return $release;
    }

    public static function parse_release(mixed $data): ?array
    {
        if (!is_array($data) || empty($data['tag_name']) || !empty($data['draft']) || !empty($data['prerelease'])) {
            return null;
        }
        $package = '';
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '') === self::ASSET) {
                $package = (string) $asset['browser_download_url'];
            }
        }
        if ($package === '') {
            return null;
        }
        return [
            'version' => self::normalize_version((string) $data['tag_name']),
            'package' => $package,
            'url' => (string) ($data['html_url'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'published' => (string) ($data['published_at'] ?? ''),
        ];
    }

    public static function inject_update(mixed $transient): mixed
    {
        if (!is_object($transient) || self::is_dev_install()) {
            return $transient;
        }
        $release = self::latest_release();
        $item = (object) [
            'id' => 'github.com/' . BLC_REPO,
            'slug' => self::SLUG,
            'plugin' => self::basename(),
            'new_version' => $release['version'] ?? BLC_VERSION,
            'url' => 'https://github.com/' . BLC_REPO,
            'package' => $release['package'] ?? '',
            'requires_php' => '8.1',
        ];
        if ($release && version_compare($release['version'], BLC_VERSION, '>')) {
            $transient->response[self::basename()] = $item;
        } else {
            $transient->no_update[self::basename()] = $item;
        }
        return $transient;
    }

    public static function plugin_info(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }
        $release = self::latest_release();
        return (object) [
            'name' => 'Bananaland Consent',
            'slug' => self::SLUG,
            'version' => $release['version'] ?? BLC_VERSION,
            'author' => '<a href="https://thebananaland.com">The Bananaland</a>',
            'homepage' => 'https://github.com/' . BLC_REPO,
            'requires' => '6.4',
            'requires_php' => '8.1',
            'last_updated' => $release['published'] ?? '',
            'download_link' => $release['package'] ?? '',
            'sections' => [
                'description' => esc_html__('Region-aware cookie consent, Google Consent Mode v2, Global Privacy Control and consent records.', 'bananaland-consent'),
                'changelog' => $release ? wpautop(esc_html($release['body'])) : '',
            ],
        ];
    }

    /**
     * GitHub zips can extract to a differently named folder; rename it so the plugin keeps its path.
     */
    public static function fix_folder_name(mixed $source, string $remote_source, object $upgrader, array $hook_extra = []): mixed
    {
        if (!is_string($source) || ($hook_extra['plugin'] ?? '') !== self::basename()) {
            return $source;
        }
        $desired = trailingslashit($remote_source) . self::SLUG . '/';
        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source;
        }
        global $wp_filesystem;
        if ($wp_filesystem && $wp_filesystem->move($source, $desired, true)) {
            return $desired;
        }
        return $source;
    }

    public static function auto_update(mixed $update, object $item): mixed
    {
        if (($item->plugin ?? '') !== self::basename()) {
            return $update;
        }
        if (defined('BLC_DISABLE_AUTO_UPDATE') && BLC_DISABLE_AUTO_UPDATE) {
            return false;
        }
        return self::is_dev_install() ? false : true;
    }
}
