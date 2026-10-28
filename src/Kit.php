<?php

namespace Bananaland\Consent;

/**
 * Locates a theme's consent kit: the `consent/` folder in the child theme, then the parent theme.
 */
final class Kit
{
    /**
     * @return array<int, array{path: string, url: string}>
     */
    public static function dirs(): array
    {
        $dirs = [
            ['path' => get_stylesheet_directory() . '/consent', 'url' => get_stylesheet_directory_uri() . '/consent'],
        ];
        if (get_template_directory() !== get_stylesheet_directory()) {
            $dirs[] = ['path' => get_template_directory() . '/consent', 'url' => get_template_directory_uri() . '/consent'];
        }

        /**
         * Filters where the plugin looks for a theme consent kit, in priority order.
         *
         * @param array<int, array{path: string, url: string}> $dirs
         */
        return apply_filters('blc_kit_dirs', $dirs);
    }

    /**
     * First kit file with this name, or null.
     *
     * @return array{path: string, url: string}|null
     */
    public static function file(string $name): ?array
    {
        foreach (self::dirs() as $dir) {
            $path = rtrim($dir['path'], '/') . '/' . $name;
            if (is_file($path)) {
                return ['path' => $path, 'url' => rtrim($dir['url'], '/') . '/' . $name];
            }
        }
        return null;
    }
}
