<?php

namespace Bananaland\Consent;

/**
 * Server-side view of the visitor's stored choice (the blc_consent cookie written by consent.js).
 *
 * Only meaningful on uncached responses. Cached pages must rely on consent.js.
 */
final class ConsentState
{
    public const COOKIE = 'blc_consent';

    /**
     * @return array{id: string, pv: string, c: array<int, string>, m: string, t: int, g: int}|null
     */
    public static function current(): ?array
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;
        return is_string($raw) ? self::parse(wp_unslash($raw)) : null;
    }

    public static function parse(string $raw): ?array
    {
        $data = json_decode(rawurldecode($raw), true);
        if (!is_array($data) || !isset($data['id'], $data['pv'], $data['c']) || !is_array($data['c'])) {
            return null;
        }
        if ((string) $data['pv'] !== (string) Config::get('policy_version')) {
            return null;
        }
        $data['c'] = array_values(array_intersect($data['c'], Config::CATEGORIES));
        return $data;
    }

    public static function has(string $category): bool
    {
        if ($category === 'necessary') {
            return true;
        }
        $state = self::current();
        return $state !== null && in_array($category, $state['c'], true);
    }
}
