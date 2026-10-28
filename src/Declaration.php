<?php

namespace Bananaland\Consent;

use Bananaland\Consent\Templates\Locator;

/**
 * [blc_cookie_declaration], [blc_preferences_link] and the matching block.
 */
final class Declaration
{
    public static function register(): void
    {
        add_shortcode('blc_cookie_declaration', [self::class, 'render']);
        add_shortcode('blc_preferences_link', static fn($atts) => blc_preferences_link((array) $atts));
        add_action('init', static function () {
            register_block_type(BLC_DIR . 'blocks/declaration', ['render_callback' => [self::class, 'render']]);
        });
    }

    public static function render(): string
    {
        return Locator::render('declaration');
    }
}
