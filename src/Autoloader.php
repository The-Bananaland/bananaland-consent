<?php

namespace Bananaland\Consent;

/**
 * PSR-4 autoloader for the plugin's own classes, so the plugin ships without Composer.
 */
final class Autoloader
{
    private const PREFIX = 'Bananaland\\Consent\\';

    public static function register(): void
    {
        spl_autoload_register([self::class, 'load']);
    }

    public static function load(string $class): void
    {
        if (strncmp($class, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return;
        }
        $relative = substr($class, strlen(self::PREFIX));
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
}
