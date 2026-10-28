<?php

namespace Bananaland\Consent\Embeds;

use Bananaland\Consent\Config;
use Bananaland\Consent\Templates\Locator;

/**
 * Turns third-party iframes into click-to-load placeholders.
 */
final class Embeds
{
    public static function register(): void
    {
        add_filter('embed_oembed_html', [self::class, 'filter_oembed'], 99);
        add_filter('the_content', [self::class, 'filter_content'], 99);
    }

    public static function active(): bool
    {
        if (!Config::get('embeds.enabled')) {
            return false;
        }
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed() || (function_exists('is_embed') && is_embed())) {
            return false;
        }
        return (bool) apply_filters('blc_embeds_enabled', true);
    }

    public static function filter_oembed(string $html): string
    {
        return self::active() ? self::wrap_html($html) : $html;
    }

    public static function filter_content(string $content): string
    {
        if (!self::active() || stripos($content, '<iframe') === false) {
            return $content;
        }
        // Protect embeds that are already wrapped.
        $saved = [];
        $content = preg_replace_callback('#<template data-blc="embed-source">.*?</template>#is', static function ($m) use (&$saved) {
            $key = '<!--blc-embed-' . count($saved) . '-->';
            $saved[$key] = $m[0];
            return $key;
        }, $content);

        $content = preg_replace_callback('#<iframe\b[^>]*>.*?</iframe>#is', static fn($m) => self::wrap_html($m[0]), $content);

        return strtr($content, $saved);
    }

    /**
     * Wrap HTML containing a third-party iframe. Same-site or unrecognized markup is returned unchanged.
     */
    public static function wrap_html(string $html): string
    {
        if (!preg_match('#<iframe\b[^>]*\ssrc\s*=\s*(["\'])(.*?)\1#is', $html, $m)) {
            return $html;
        }
        $src = html_entity_decode($m[2]);
        $provider = self::provider($src);
        if (!$provider) {
            return $html;
        }

        $new_src = $provider['src'] ?? $src;
        if ($new_src !== $src) {
            $html = str_replace($m[2], esc_url($new_src), $html);
        }

        $ratio = '16 / 9';
        if (preg_match('/\swidth\s*=\s*["\']?(\d+)/i', $html, $w) && preg_match('/\sheight\s*=\s*["\']?(\d+)/i', $html, $h) && (int) $h[1] > 0) {
            $ratio = (int) $w[1] . ' / ' . (int) $h[1];
        }

        return Locator::render('embed-placeholder', [
            'provider' => $provider['name'],
            'embed' => $html,
            'thumbnail' => $provider['thumbnail'] ?? '',
            'category' => apply_filters('blc_embed_category', 'preferences', $provider['name'], $src),
            'ratio' => $ratio,
        ]);
    }

    /**
     * @return array{name: string, src?: string, thumbnail?: string}|null
     */
    public static function provider(string $src): ?array
    {
        $host = strtolower((string) wp_parse_url($src, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }
        $host = preg_replace('/^www\./', '', $host);

        if (preg_match('#(?:youtube\.com|youtube-nocookie\.com)/embed/([A-Za-z0-9_-]{6,})#', $src, $m)) {
            $nocookie = preg_replace('#^https?://(?:www\.)?youtube\.com/#', 'https://www.youtube-nocookie.com/', $src);
            return ['name' => 'YouTube', 'src' => $nocookie, 'thumbnail' => Thumbnails::youtube($m[1])];
        }
        if ($host === 'player.vimeo.com' && preg_match('#/video/(\d+)#', $src, $m)) {
            return ['name' => 'Vimeo', 'src' => add_query_arg('dnt', '1', $src), 'thumbnail' => Thumbnails::vimeo($m[1])];
        }
        if (str_ends_with($host, 'google.com') && str_contains($src, '/maps')) {
            return ['name' => 'Google Maps'];
        }
        if ($host === 'open.spotify.com') {
            return ['name' => 'Spotify'];
        }

        $known = apply_filters('blc_embed_providers', []);
        foreach ((array) $known as $pattern => $name) {
            if (@preg_match($pattern, $src)) {
                return ['name' => (string) $name];
            }
        }

        if (!Config::get('embeds.generic_iframes')) {
            return null;
        }
        $site = preg_replace('/^www\./', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)));
        if ($host === $site || str_ends_with($host, '.' . $site)) {
            return null;
        }
        return ['name' => $host];
    }
}
