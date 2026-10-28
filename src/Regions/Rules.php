<?php

namespace Bananaland\Consent\Regions;

use Bananaland\Consent\Config;

/**
 * Maps a location to a consent model: opt-in, opt-out or notice.
 */
final class Rules
{
    private static ?array $data = null;

    public static function data(): array
    {
        if (self::$data === null) {
            $json = file_get_contents(BLC_DIR . 'data/regions.json');
            self::$data = json_decode($json, true) ?: [];
        }
        return self::$data;
    }

    public static function mode(?string $country, ?string $region): string
    {
        if (!$country) {
            return self::valid(Config::get('regions.unknown_mode'), 'opt-in');
        }

        $country = strtoupper($country);
        $subdivision = $region ? $country . '-' . strtoupper($region) : null;

        $overrides = (array) Config::get('regions.overrides', []);
        if ($subdivision && isset($overrides[$subdivision])) {
            return self::valid($overrides[$subdivision], 'opt-in');
        }
        if (isset($overrides[$country])) {
            return self::valid($overrides[$country], 'opt-in');
        }

        $data = self::data();
        foreach (['opt-in' => 'opt_in', 'opt-out' => 'opt_out'] as $mode => $key) {
            if ($subdivision && in_array($subdivision, $data[$key]['subdivisions'] ?? [], true)) {
                return $mode;
            }
        }
        foreach (['opt-in' => 'opt_in', 'opt-out' => 'opt_out'] as $mode => $key) {
            if (in_array($country, $data[$key]['countries'] ?? [], true)) {
                return $mode;
            }
        }
        return self::valid($data['default'] ?? 'notice', 'notice');
    }

    private static function valid(mixed $mode, string $fallback): string
    {
        return in_array($mode, Config::MODES, true) ? $mode : $fallback;
    }
}
