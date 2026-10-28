<?php

namespace Bananaland\Consent\Embeds;

/**
 * Fetches embed thumbnails server-side once and serves them locally,
 * so the provider never sees the visitor before they opt in.
 */
final class Thumbnails
{
    public static function youtube(string $id): string
    {
        return self::local('yt-' . $id, static fn() => 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/hqdefault.jpg');
    }

    public static function vimeo(string $id): string
    {
        return self::local('vimeo-' . $id, static function () use ($id) {
            $response = wp_remote_get('https://vimeo.com/api/oembed.json?url=' . rawurlencode('https://vimeo.com/' . $id), ['timeout' => 5]);
            $data = json_decode((string) wp_remote_retrieve_body($response), true);
            return is_array($data) && !empty($data['thumbnail_url']) ? (string) $data['thumbnail_url'] : '';
        });
    }

    private static function local(string $key, callable $remote_url): string
    {
        $key = sanitize_file_name($key);
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error'])) {
            return '';
        }
        $dir = trailingslashit($uploads['basedir']) . 'blc-thumbs';
        $path = $dir . '/' . $key . '.jpg';
        $url = trailingslashit($uploads['baseurl']) . 'blc-thumbs/' . $key . '.jpg';

        if (is_file($path)) {
            return $url;
        }
        if (get_transient('blc_thumb_fail_' . md5($key))) {
            return '';
        }

        $source = $remote_url();
        $response = $source ? wp_remote_get($source, ['timeout' => 5]) : null;
        $body = $response ? wp_remote_retrieve_body($response) : '';
        if (!$body || wp_remote_retrieve_response_code($response) !== 200 || !str_starts_with((string) wp_remote_retrieve_header($response, 'content-type'), 'image/')) {
            set_transient('blc_thumb_fail_' . md5($key), 1, DAY_IN_SECONDS);
            return '';
        }
        wp_mkdir_p($dir);
        file_put_contents($path, $body);
        return $url;
    }
}
