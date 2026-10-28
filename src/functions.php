<?php
/**
 * Public PHP API for themes and other plugins.
 */

use Bananaland\Consent\ConsentState;
use Bananaland\Consent\Embeds\Embeds;
use Bananaland\Consent\Services\ScriptTagger;
use Bananaland\Consent\Templates\Locator;
use Bananaland\Consent\Templates\View;

defined('ABSPATH') || exit;

/**
 * Hold an enqueued script until the visitor grants a category.
 *
 *     wp_enqueue_script('hotjar', ...);
 *     blc_require_consent('hotjar', 'analytics');
 */
function blc_require_consent(string $handle, string $category): void
{
    ScriptTagger::require($handle, $category);
}

/**
 * Server-side check of the stored choice. Only reliable on uncached responses.
 */
function blc_has_consent(string $category): bool
{
    return ConsentState::has($category);
}

/**
 * Wrap third-party iframe/embed HTML in a click-to-load placeholder.
 */
function blc_embed(string $html): string
{
    return Embeds::wrap_html($html);
}

/**
 * Link that reopens the preferences panel. In opt-out regions consent.js switches the label to
 * "Your Privacy Choices" and shows the toggle icon.
 */
function blc_preferences_link(array $args = []): string
{
    $view = View::instance();
    $args = wp_parse_args($args, ['class' => 'blc-link']);
    // Remembered (at most daily) so the Overview can confirm the site has the required link.
    if (!defined('BLC_E2E') && !is_admin() && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST) && (int) get_option('blc_link_seen') < time() - DAY_IN_SECONDS) {
        update_option('blc_link_seen', time(), false);
    }
    $icon = Locator::render('privacy-choices-icon');
    return sprintf(
        '<a href="#blc-preferences" class="%1$s" data-blc-action="open-preferences" data-blc="preferences-link" data-blc-label-default="%2$s" data-blc-label-opt-out="%3$s"><span class="blc-link__icon" hidden>%4$s</span><span class="blc-link__label">%2$s</span></a>',
        esc_attr($args['class']),
        esc_attr($view->text('links', 'preferences')),
        esc_attr($view->text('links', 'opt_out')),
        $icon
    );
}

/**
 * Render a consent template with theme override support.
 */
function blc_template(string $name, array $vars = []): string
{
    return Locator::render($name, $vars);
}
