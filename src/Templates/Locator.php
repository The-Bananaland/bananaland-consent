<?php

namespace Bananaland\Consent\Templates;

use Bananaland\Consent\Kit;

/**
 * Finds templates in the theme kit first, then the plugin, WooCommerce-style.
 */
final class Locator
{
    public const TEMPLATES = ['banner', 'preferences', 'category', 'service-row', 'floating-button', 'embed-placeholder', 'declaration', 'privacy-choices-icon'];

    public static function locate(string $name): string
    {
        $name = sanitize_file_name($name) . '.php';
        $kit = Kit::file($name);
        $path = $kit ? $kit['path'] : BLC_DIR . 'templates/' . $name;
        return apply_filters('blc_template_path', $path, $name);
    }

    public static function render(string $name, array $vars = []): string
    {
        $path = self::locate($name);
        if (!is_file($path)) {
            return '';
        }
        $vars['consent'] ??= View::instance();
        ob_start();
        (static function (string $__path, array $__vars) {
            extract($__vars, EXTR_SKIP);
            include $__path;
        })($path, $vars);
        return (string) ob_get_clean();
    }

    /**
     * Theme overrides with their @version headers, flagging ones older than the plugin's copy.
     *
     * @return array<string, array{path: string, theme_version: ?string, plugin_version: ?string, outdated: bool}>
     */
    public static function overrides(): array
    {
        $out = [];
        foreach (self::TEMPLATES as $name) {
            $kit = Kit::file($name . '.php');
            if (!$kit) {
                continue;
            }
            $theme_version = self::version($kit['path']);
            $plugin_version = self::version(BLC_DIR . 'templates/' . $name . '.php');
            $out[$name] = [
                'path' => $kit['path'],
                'theme_version' => $theme_version,
                'plugin_version' => $plugin_version,
                'outdated' => !$theme_version || ($plugin_version && version_compare($theme_version, $plugin_version, '<')),
            ];
        }
        return $out;
    }

    public static function version(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $head = (string) file_get_contents($path, false, null, 0, 2048);
        return preg_match('/@version\s+([0-9][0-9.]*)/', $head, $m) ? $m[1] : null;
    }
}
