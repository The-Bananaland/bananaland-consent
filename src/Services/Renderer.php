<?php

namespace Bananaland\Consent\Services;

use Bananaland\Consent\Config;

/**
 * Prints service snippets, holding anything that needs consent until consent.js releases it.
 */
final class Renderer
{
    private const JS_TYPES = ['', 'text/javascript', 'application/javascript', 'module'];

    /**
     * Neutralize scripts, iframes and images in $html so they only run once $category is granted.
     */
    public static function hold(string $html, string $category, string $uid = ''): string
    {
        if ($category === 'necessary' || $html === '') {
            return $html;
        }
        $extra = ' data-consent="' . esc_attr($category) . '"' . ($uid !== '' ? ' data-blc-service="' . esc_attr($uid) . '"' : '');

        $html = preg_replace_callback('/<script\b([^>]*)>/i', static function ($m) use ($extra) {
            $attrs = $m[1];
            if (str_contains($attrs, 'data-consent=')) {
                return $m[0];
            }
            $type = '';
            if (preg_match('/\stype\s*=\s*(["\']?)([^"\'\s>]*)\1/i', $attrs, $t)) {
                $type = strtolower($t[2]);
                $attrs = str_replace($t[0], '', $attrs);
            }
            if (!in_array($type, self::JS_TYPES, true)) {
                // JSON, templates and other data blocks are inert already.
                return $m[0];
            }
            $original = $type !== '' ? ' data-blc-type="' . esc_attr($type) . '"' : '';
            return '<script type="text/plain"' . $extra . $original . $attrs . '>';
        }, $html);

        $html = preg_replace_callback('/<(iframe|img)\b([^>]*)\ssrc\s*=\s*(["\'])(.*?)\3([^>]*)>/i', static function ($m) use ($extra) {
            if (str_contains($m[0], 'data-consent=')) {
                return $m[0];
            }
            return '<' . $m[1] . $m[2] . ' data-blc-src=' . $m[3] . $m[4] . $m[3] . $extra . $m[5] . '>';
        }, $html);

        return $html;
    }

    /**
     * Whether this service may load before consent (GTM, or Google tags in Advanced Consent Mode).
     */
    public static function is_live(array $service): bool
    {
        if ($service['category'] === 'necessary') {
            return true;
        }
        if ($service['google'] && Config::get('consent_mode.enabled') && Config::get('consent_mode.mode') === 'advanced') {
            return true;
        }
        return false;
    }

    public static function render(string $slot): string
    {
        $out = '';
        foreach (Registry::all() as $service) {
            $html = $service[$slot] ?? '';
            if ($html === '') {
                continue;
            }
            $out .= self::is_live($service) ? $html : self::hold($html, $service['category'], $service['uid']);
            $out .= "\n";
        }
        return $out;
    }
}
