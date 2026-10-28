<?php

namespace Bananaland\Consent\Services;

/**
 * Holds enqueued scripts registered with blc_require_consent() until their category is granted.
 */
final class ScriptTagger
{
    /** @var array<string, string> handle => category */
    private static array $handles = [];

    public static function register(): void
    {
        add_filter('script_loader_tag', [self::class, 'filter'], 20, 2);
    }

    public static function require(string $handle, string $category): void
    {
        self::$handles[$handle] = $category;
    }

    public static function handles(): array
    {
        return self::$handles;
    }

    public static function filter(string $tag, string $handle): string
    {
        if (!isset(self::$handles[$handle])) {
            return $tag;
        }
        return Renderer::hold($tag, self::$handles[$handle], 'handle-' . sanitize_key($handle));
    }
}
