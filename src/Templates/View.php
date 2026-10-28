<?php

namespace Bananaland\Consent\Templates;

use Bananaland\Consent\Config;
use Bananaland\Consent\Kit;
use Bananaland\Consent\Services\Registry;

/**
 * The $consent object every template receives.
 */
final class View
{
    private static ?View $instance = null;

    public static function instance(): View
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Plain text for a mode/key, e.g. text('opt-in', 'title').
     */
    public function text(string $group, string $key): string
    {
        return (string) Config::get("texts.$group.$key", '');
    }

    public function modes(): array
    {
        return Config::MODES;
    }

    /**
     * @return array<string, array{key: string, label: string, description: string, services: array}>
     */
    public function categories(): array
    {
        $out = [];
        foreach (Config::CATEGORIES as $key) {
            $out[$key] = [
                'key' => $key,
                'label' => (string) Config::get("categories.$key.label", $key),
                'description' => (string) Config::get("categories.$key.description", ''),
                'services' => array_values(Registry::by_category($key)),
            ];
        }
        return apply_filters('blc_view_categories', $out);
    }

    public function layout(): string
    {
        $layout = (string) Config::get('banner.layout', 'bar');
        return in_array($layout, ['bar', 'box', 'modal'], true) ? $layout : 'bar';
    }

    public function position(): string
    {
        $position = (string) Config::get('banner.position', 'bottom');
        return in_array($position, ['bottom', 'top', 'bottom-left', 'bottom-right'], true) ? $position : 'bottom';
    }

    public function floating_button(): bool
    {
        return (bool) Config::get('banner.floating_button', false);
    }

    public function floating_position(): string
    {
        return Config::get('banner.floating_position') === 'bottom-right' ? 'bottom-right' : 'bottom-left';
    }

    public function policy_url(): string
    {
        $id = (int) Config::get('privacy_page') ?: (int) get_option('wp_page_for_privacy_policy');
        return $id ? (string) get_permalink($id) : '';
    }

    public function cookie_url(): string
    {
        $id = (int) Config::get('cookie_page');
        return $id ? (string) get_permalink($id) : '';
    }

    /**
     * True when the theme kit supplies its own stylesheet.
     */
    public function is_kit_styled(): bool
    {
        return Kit::file('consent.css') !== null;
    }

    /**
     * Render a sub-template (used by templates to include each other).
     */
    public function part(string $name, array $vars = []): void
    {
        echo Locator::render($name, $vars + ['consent' => $this]); // phpcs:ignore WordPress.Security.EscapeOutput -- templates escape their own output.
    }
}
