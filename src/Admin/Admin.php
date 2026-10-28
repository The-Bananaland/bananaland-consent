<?php

namespace Bananaland\Consent\Admin;

use Bananaland\Consent\Config;
use Bananaland\Consent\ConsentLog\Repository;
use Bananaland\Consent\Kit;
use Bananaland\Consent\Regions\Rules;
use Bananaland\Consent\Services\Presets;
use Bananaland\Consent\Services\Registry;
use Bananaland\Consent\Templates\Locator;
use Bananaland\Consent\Updater\GitHubUpdater;

/**
 * Consent menu: dashboard, settings tabs, consent log and tools.
 */
final class Admin
{
    public const SLUG = 'blc';

    public const CAP = 'manage_options';

    private const TABS = [
        'dashboard' => 'Dashboard',
        'general' => 'General',
        'banner' => 'Banner',
        'categories' => 'Categories',
        'services' => 'Services',
        'regions' => 'Regions',
        'log' => 'Consent log',
        'tools' => 'Tools',
    ];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_blc_save', [self::class, 'save']);
        add_action('admin_post_blc_bump_version', [self::class, 'bump_version']);
        add_action('admin_post_blc_export', [self::class, 'export']);
        add_action('admin_post_blc_import', [self::class, 'import']);
        add_action('admin_post_blc_export_log', [self::class, 'export_log']);
        add_action('admin_post_blc_check_updates', [self::class, 'check_updates']);
        add_filter('plugin_action_links_' . plugin_basename(BLC_FILE), static function ($links) {
            array_unshift($links, '<a href="' . esc_url(self::url()) . '">' . esc_html__('Settings', 'bananaland-consent') . '</a>');
            return $links;
        });
    }

    public static function url(string $tab = 'dashboard', array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG, 'tab' => $tab], $args), admin_url('admin.php'));
    }

    public static function menu(): void
    {
        add_menu_page(__('Consent', 'bananaland-consent'), __('Consent', 'bananaland-consent'), self::CAP, self::SLUG, [self::class, 'render'], 'dashicons-shield', 81);
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_style('blc-admin', BLC_URL . 'assets/admin.css', [], BLC_VERSION);
        wp_enqueue_script('blc-admin', BLC_URL . 'assets/admin.js', [], BLC_VERSION, ['in_footer' => true]);
        wp_localize_script('blc-admin', 'blcAdmin', [
            'presets' => array_map(static fn($p) => ['category' => $p['category'], 'fields' => $p['fields'] ? array_map(static fn($f) => $f['label'], $p['fields']) : []], Presets::all()),
            'declared' => self::declared_hosts(),
            'home' => home_url('/'),
            'scanUrls' => self::scan_urls(),
            'i18n' => [
                'scanning' => __('Scanning…', 'bananaland-consent'),
                'clean' => __('No undeclared cookies or third-party requests before consent.', 'bananaland-consent'),
                'remove' => __('Remove this service?', 'bananaland-consent'),
            ],
        ]);
    }

    private static function tab(): string
    {
        $tab = sanitize_key($_GET['tab'] ?? 'dashboard'); // phpcs:ignore WordPress.Security.NonceVerification
        return isset(self::TABS[$tab]) ? $tab : 'dashboard';
    }

    /* ---------- Page ---------- */

    public static function render(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }
        Config::reset();
        Registry::reset();
        $tab = self::tab();
        echo '<div class="wrap blc-admin">';
        echo '<h1>' . esc_html__('Consent', 'bananaland-consent') . ' <span class="blc-admin__version">v' . esc_html(BLC_VERSION) . '</span></h1>';

        if (isset($_GET['blc_notice'])) { // phpcs:ignore WordPress.Security.NonceVerification
            $notice = sanitize_key($_GET['blc_notice']); // phpcs:ignore
            $messages = [
                'saved' => __('Settings saved.', 'bananaland-consent'),
                'bumped' => __('Policy version bumped. Every visitor will be asked again.', 'bananaland-consent'),
                'imported' => __('Settings imported.', 'bananaland-consent'),
                'import_failed' => __('That file is not a valid settings export.', 'bananaland-consent'),
                'checked' => __('Checked GitHub for updates.', 'bananaland-consent'),
            ];
            if (isset($messages[$notice])) {
                $class = $notice === 'import_failed' ? 'notice-error' : 'notice-success';
                echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
            }
        }
        foreach (Registry::errors() as $error) {
            echo '<div class="notice notice-warning"><p>' . esc_html($error) . '</p></div>';
        }

        echo '<nav class="nav-tab-wrapper">';
        foreach (self::TABS as $key => $label) {
            printf('<a href="%s" class="nav-tab%s">%s</a>', esc_url(self::url($key)), $key === $tab ? ' nav-tab-active' : '', esc_html__($label, 'bananaland-consent')); // phpcs:ignore WordPress.WP.I18n
        }
        echo '</nav>';

        $method = 'tab_' . $tab;
        self::$method();
        echo '</div>';
    }

    private static function form_open(string $section): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="blc-form">';
        echo '<input type="hidden" name="action" value="blc_save">';
        echo '<input type="hidden" name="section" value="' . esc_attr($section) . '">';
        wp_nonce_field('blc_save_' . $section);
        $locked = array_intersect(Settings::SECTIONS[$section] ?? [], array_keys(Config::theme()));
        if ($locked) {
            echo '<div class="notice notice-info inline"><p>' . esc_html(sprintf(
                /* translators: %s: list of setting groups */
                __('The theme sets these in consent/config.php, and they override what you save here: %s', 'bananaland-consent'),
                implode(', ', $locked)
            )) . '</p></div>';
        }
    }

    private static function form_close(): void
    {
        submit_button();
        echo '</form>';
    }

    /* ---------- Field helpers ---------- */

    private static function name(string $path): string
    {
        $parts = explode('.', $path);
        return 'blc[' . implode('][', $parts) . ']';
    }

    private static function value(string $path): mixed
    {
        // Show the saved value where there is one, so theme overrides don't get saved back into the database.
        $saved = Config::merge(Config::defaults(), Config::saved());
        foreach (explode('.', $path) as $key) {
            if (!is_array($saved) || !array_key_exists($key, $saved)) {
                return null;
            }
            $saved = $saved[$key];
        }
        return $saved;
    }

    private static function row(string $label, string $field, string $help = '', string $for = ''): void
    {
        echo '<tr><th scope="row">' . ($for ? '<label for="' . esc_attr($for) . '">' . esc_html($label) . '</label>' : esc_html($label)) . '</th><td>';
        echo $field; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped helpers.
        if ($help) {
            echo '<p class="description">' . wp_kses_post($help) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function id(string $path): string
    {
        return 'blc-' . str_replace(['.', '_'], '-', $path);
    }

    private static function text(string $path, string $class = 'regular-text'): string
    {
        return sprintf('<input type="text" id="%s" name="%s" value="%s" class="%s">', esc_attr(self::id($path)), esc_attr(self::name($path)), esc_attr((string) self::value($path)), esc_attr($class));
    }

    private static function textarea(string $path, int $rows = 3): string
    {
        return sprintf('<textarea id="%s" name="%s" rows="%d" class="large-text">%s</textarea>', esc_attr(self::id($path)), esc_attr(self::name($path)), $rows, esc_textarea((string) self::value($path)));
    }

    private static function number(string $path, int $min, int $max): string
    {
        return sprintf('<input type="number" id="%s" name="%s" value="%d" min="%d" max="%d" class="small-text">', esc_attr(self::id($path)), esc_attr(self::name($path)), (int) self::value($path), $min, $max);
    }

    private static function checkbox(string $path, string $label): string
    {
        return sprintf('<label><input type="hidden" name="%2$s" value="0"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> %4$s</label>', esc_attr(self::id($path)), esc_attr(self::name($path)), checked((bool) self::value($path), true, false), esc_html($label));
    }

    private static function select(string $path, array $options): string
    {
        $html = sprintf('<select id="%s" name="%s">', esc_attr(self::id($path)), esc_attr(self::name($path)));
        foreach ($options as $value => $label) {
            $html .= sprintf('<option value="%s"%s>%s</option>', esc_attr($value), selected((string) self::value($path), (string) $value, false), esc_html($label));
        }
        return $html . '</select>';
    }

    private static function page(string $path): string
    {
        return (string) wp_dropdown_pages([
            'name' => self::name($path),
            'id' => self::id($path),
            'selected' => (int) self::value($path),
            'show_option_none' => __('— Select —', 'bananaland-consent'),
            'option_none_value' => '0',
            'echo' => false,
        ]);
    }

    private static function color(string $path): string
    {
        return sprintf('<input type="color" id="%s" name="%s" value="%s">', esc_attr(self::id($path)), esc_attr(self::name($path)), esc_attr((string) self::value($path)));
    }

    /* ---------- Tabs ---------- */

    private static function tab_dashboard(): void
    {
        $privacy = (int) Config::get('privacy_page') ?: (int) get_option('wp_page_for_privacy_policy');
        $services = Registry::all();
        $geo = get_option('blc_geo_source', '');
        $declaration = $privacy ? has_shortcode((string) get_post_field('post_content', $privacy), 'blc_cookie_declaration') || has_block('bananaland/cookie-declaration', $privacy) : false;
        $cookie_page = (int) Config::get('cookie_page');
        if (!$declaration && $cookie_page) {
            $content = (string) get_post_field('post_content', $cookie_page);
            $declaration = has_shortcode($content, 'blc_cookie_declaration') || has_block('bananaland/cookie-declaration', $cookie_page);
        }

        $checks = [
            [$privacy > 0, __('Privacy policy page is set.', 'bananaland-consent'), self::url('general')],
            [count($services) > 0, sprintf(_n('%d service is declared.', '%d services are declared.', count($services), 'bananaland-consent'), count($services)), self::url('services')],
            [$declaration, __('The cookie declaration ([blc_cookie_declaration] or the Cookie declaration block) is on the privacy or cookie page.', 'bananaland-consent'), self::url('general')],
            [in_array($geo, ['wpengine', 'cloudflare'], true), $geo === '' ? __('No visitor has been located yet. Load the site once to check geolocation.', 'bananaland-consent') : sprintf(__('Geolocation source: %s.', 'bananaland-consent'), $geo === 'none' ? __('none (enable WP Engine GeoTarget or Cloudflare location headers; until then visitors get the unknown-region model and the timezone fallback)', 'bananaland-consent') : $geo), self::url('regions')],
            [!Registry::errors(), __('All services have valid IDs.', 'bananaland-consent'), self::url('services')],
        ];

        echo '<div class="blc-cards">';
        echo '<section class="blc-card"><h2>' . esc_html__('Setup checklist', 'bananaland-consent') . '</h2><ul class="blc-checklist">';
        foreach ($checks as [$ok, $label, $link]) {
            printf('<li class="%s"><span class="dashicons %s" aria-hidden="true"></span> <a href="%s">%s</a></li>', $ok ? 'is-ok' : 'is-todo', $ok ? 'dashicons-yes-alt' : 'dashicons-warning', esc_url($link), esc_html($label));
        }
        echo '<li class="is-info"><span class="dashicons dashicons-info" aria-hidden="true"></span> <span>' . wp_kses_post(__('Put a link that reopens preferences in the footer: <code>&lt;?php echo blc_preferences_link(); ?&gt;</code>, the <code>[blc_preferences_link]</code> shortcode, or a menu link to <code>#blc-preferences</code>.', 'bananaland-consent')) . '</span></li>';
        echo '</ul></section>';

        echo '<section class="blc-card"><h2>' . esc_html__('Choices, last 30 days', 'bananaland-consent') . '</h2>';
        $counts = Config::get('log.enabled') ? Repository::counts(30) : [];
        if (!$counts) {
            echo '<p>' . esc_html__('No choices recorded yet.', 'bananaland-consent') . '</p>';
        } else {
            echo '<table class="widefat striped"><tbody>';
            foreach ($counts as $method => $total) {
                echo '<tr><td>' . esc_html($method) . '</td><td>' . esc_html(number_format_i18n((int) $total)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';

        echo '<section class="blc-card"><h2>' . esc_html__('Theme kit', 'bananaland-consent') . '</h2>';
        $dirs = array_filter(Kit::dirs(), static fn($d) => is_dir($d['path']));
        if (!$dirs) {
            echo '<p>' . esc_html__('No consent/ folder in the theme. The plugin\'s default templates and styles are in use.', 'bananaland-consent') . '</p>';
        } else {
            echo '<p>' . esc_html__('Kit folder:', 'bananaland-consent') . ' <code>' . esc_html(reset($dirs)['path']) . '</code></p><ul>';
            foreach (['config.php', 'tokens.css', 'consent.css'] as $file) {
                if (Kit::file($file)) {
                    echo '<li><code>' . esc_html($file) . '</code></li>';
                }
            }
            foreach (Locator::overrides() as $name => $info) {
                printf(
                    '<li><code>%s.php</code> %s</li>',
                    esc_html($name),
                    $info['outdated']
                        ? '<span class="blc-badge blc-badge--warn">' . esc_html(sprintf(__('outdated: theme %1$s, plugin %2$s', 'bananaland-consent'), $info['theme_version'] ?: '?', $info['plugin_version'])) . '</span>'
                        : '<span class="blc-badge">' . esc_html($info['theme_version']) . '</span>'
                );
            }
            echo '</ul>';
        }
        echo '</section>';

        echo '<section class="blc-card"><h2>' . esc_html__('Updates', 'bananaland-consent') . '</h2>';
        if (GitHubUpdater::is_dev_install()) {
            echo '<p>' . esc_html__('Development install (symlink or git checkout): automatic updates are off.', 'bananaland-consent') . '</p>';
        } else {
            $release = GitHubUpdater::latest_release();
            echo '<p>' . esc_html(sprintf(__('Installed %1$s. Latest release: %2$s.', 'bananaland-consent'), BLC_VERSION, $release['version'] ?? __('unknown', 'bananaland-consent'))) . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="blc_check_updates">';
            wp_nonce_field('blc_check_updates');
            submit_button(__('Check now', 'bananaland-consent'), 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</section>';
        echo '</div>';
    }

    private static function tab_general(): void
    {
        self::form_open('general');
        echo '<h2>' . esc_html__('Policy', 'bananaland-consent') . '</h2><table class="form-table">';
        self::row(__('Policy version', 'bananaland-consent'), self::text('policy_version', 'small-text'), __('Changing this asks every visitor again. Use the button below after a material change to your privacy policy or cookie use.', 'bananaland-consent'), self::id('policy_version'));
        self::row(__('Privacy policy page', 'bananaland-consent'), self::page('privacy_page'), __('Falls back to the page set in Settings → Privacy.', 'bananaland-consent'), self::id('privacy_page'));
        self::row(__('Cookie page', 'bananaland-consent'), self::page('cookie_page'), __('Optional page holding the [blc_cookie_declaration] list.', 'bananaland-consent'), self::id('cookie_page'));
        echo '</table>';

        echo '<h2>' . esc_html__('How long choices last', 'bananaland-consent') . '</h2><table class="form-table">';
        self::row(__('Opt-in regions (days)', 'bananaland-consent'), self::number('expiry.opt-in', 1, 395), __('Six months (182 days) is the common EU guidance; 13 months is the usual maximum.', 'bananaland-consent'));
        self::row(__('Opt-out regions (days)', 'bananaland-consent'), self::number('expiry.opt-out', 1, 395));
        self::row(__('Notice-only regions (days)', 'bananaland-consent'), self::number('expiry.notice', 1, 395));
        echo '</table>';

        echo '<h2>' . esc_html__('Google Consent Mode v2', 'bananaland-consent') . '</h2><table class="form-table">';
        self::row(__('Consent Mode', 'bananaland-consent'), self::checkbox('consent_mode.enabled', __('Send consent defaults and updates to Google tags', 'bananaland-consent')));
        self::row(__('Implementation', 'bananaland-consent'), self::select('consent_mode.mode', ['basic' => __('Basic: Google tags load only after consent', 'bananaland-consent'), 'advanced' => __('Advanced: Google tags load before consent and send cookieless pings', 'bananaland-consent')]), __('Basic is the stricter choice. Advanced lets Google model conversions for visitors who decline; check that your privacy policy covers it.', 'bananaland-consent'), self::id('consent_mode.mode'));
        self::row(__('Ads data redaction', 'bananaland-consent'), self::checkbox('consent_mode.ads_data_redaction', __('Redact ad click identifiers when ad storage is denied', 'bananaland-consent')));
        self::row(__('URL passthrough', 'bananaland-consent'), self::checkbox('consent_mode.url_passthrough', __('Pass ad click information through URLs when cookies are denied', 'bananaland-consent')));
        echo '</table>';

        echo '<h2>' . esc_html__('Embeds', 'bananaland-consent') . '</h2><table class="form-table">';
        self::row(__('Click-to-load', 'bananaland-consent'), self::checkbox('embeds.enabled', __('Replace YouTube, Vimeo, Maps and Spotify embeds with a placeholder until allowed', 'bananaland-consent')));
        self::row(__('Other iframes', 'bananaland-consent'), self::checkbox('embeds.generic_iframes', __('Also hold any other third-party iframe in post content', 'bananaland-consent')));
        echo '</table>';

        echo '<h2>' . esc_html__('Consent records', 'bananaland-consent') . '</h2><table class="form-table">';
        self::row(__('Record choices', 'bananaland-consent'), self::checkbox('log.enabled', __('Store each choice as proof of consent (consent ID, categories, hashed and truncated IP)', 'bananaland-consent')));
        self::row(__('Keep records for (days)', 'bananaland-consent'), self::number('log.retention_days', 30, 3650));
        self::row(__('On uninstall', 'bananaland-consent'), self::checkbox('uninstall.delete_data', __('Delete settings and consent records when the plugin is deleted', 'bananaland-consent')));
        echo '</table>';
        self::form_close();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="blc_bump_version">';
        wp_nonce_field('blc_bump_version');
        submit_button(__('Bump policy version and ask everyone again', 'bananaland-consent'), 'secondary');
        echo '</form>';
    }

    private static function tab_banner(): void
    {
        self::form_open('banner');
        echo '<h2>' . esc_html__('Layout', 'bananaland-consent') . '</h2><table class="form-table">';
        self::row(__('Layout', 'bananaland-consent'), self::select('banner.layout', ['bar' => __('Bar', 'bananaland-consent'), 'box' => __('Box (corner)', 'bananaland-consent'), 'modal' => __('Modal (opt-in regions only; a bar elsewhere)', 'bananaland-consent')]), '', self::id('banner.layout'));
        self::row(__('Position', 'bananaland-consent'), self::select('banner.position', ['bottom' => __('Bottom', 'bananaland-consent'), 'top' => __('Top (bar)', 'bananaland-consent'), 'bottom-left' => __('Bottom left (box)', 'bananaland-consent'), 'bottom-right' => __('Bottom right (box)', 'bananaland-consent')]), '', self::id('banner.position'));
        self::row(__('Floating button', 'bananaland-consent'), self::checkbox('banner.floating_button', __('Show a small button that reopens preferences after a choice', 'bananaland-consent')) . ' ' . self::select('banner.floating_position', ['bottom-left' => __('Bottom left', 'bananaland-consent'), 'bottom-right' => __('Bottom right', 'bananaland-consent')]));
        echo '</table>';

        echo '<h2>' . esc_html__('Colors', 'bananaland-consent') . '</h2>';
        if (Kit::file('consent.css') || Kit::file('tokens.css')) {
            echo '<p class="description">' . esc_html__('The theme kit styles the banner, so these colors may have no effect.', 'bananaland-consent') . '</p>';
        }
        echo '<table class="form-table">';
        $labels = [
            'background' => __('Background', 'bananaland-consent'),
            'text' => __('Text', 'bananaland-consent'),
            'primary_bg' => __('Primary button', 'bananaland-consent'),
            'primary_text' => __('Primary button text', 'bananaland-consent'),
            'secondary_bg' => __('Secondary button', 'bananaland-consent'),
            'secondary_text' => __('Secondary button text', 'bananaland-consent'),
            'accent' => __('Accent (switches, focus)', 'bananaland-consent'),
        ];
        foreach ($labels as $key => $label) {
            self::row($label, self::color("colors.$key"), '', self::id("colors.$key"));
        }
        echo '</table>';
        $pairs = [
            [__('Text on background', 'bananaland-consent'), 'text', 'background', 4.5],
            [__('Primary button', 'bananaland-consent'), 'primary_text', 'primary_bg', 4.5],
            [__('Secondary button', 'bananaland-consent'), 'secondary_text', 'secondary_bg', 4.5],
            [__('Accent on background', 'bananaland-consent'), 'accent', 'background', 3.0],
        ];
        foreach ($pairs as [$label, $fg, $bg, $min]) {
            $ratio = Settings::contrast((string) self::value("colors.$fg"), (string) self::value("colors.$bg"));
            if ($ratio < $min) {
                echo '<div class="notice notice-warning inline"><p>' . esc_html(sprintf(__('%1$s contrast is %2$s:1; WCAG AA needs %3$s:1.', 'bananaland-consent'), $label, $ratio, $min)) . '</p></div>';
            }
        }

        $groups = [
            'opt-in' => __('Opt-in regions (EU, UK, etc.)', 'bananaland-consent'),
            'opt-out' => __('Opt-out regions (U.S. states)', 'bananaland-consent'),
            'notice' => __('Notice-only regions', 'bananaland-consent'),
            'preferences' => __('Preferences panel', 'bananaland-consent'),
            'links' => __('Links', 'bananaland-consent'),
            'embed' => __('Embed placeholders', 'bananaland-consent'),
        ];
        foreach ($groups as $group => $heading) {
            echo '<h2>' . esc_html($heading) . '</h2><table class="form-table">';
            foreach (array_keys(Config::defaults()['texts'][$group]) as $key) {
                $path = "texts.$group.$key";
                $field = in_array($key, ['body', 'intro', 'gpc_notice'], true) ? self::textarea($path) : self::text($path, 'large-text');
                self::row(ucwords(str_replace('_', ' ', $key)), $field, $group === 'embed' && $key === 'body' ? __('%s is replaced with the provider name.', 'bananaland-consent') : '', self::id($path));
            }
            echo '</table>';
        }
        self::form_close();
    }

    private static function tab_categories(): void
    {
        self::form_open('categories');
        echo '<p>' . esc_html__('Category keys are fixed so services and Google Consent Mode stay mapped correctly. You can rename and describe them.', 'bananaland-consent') . '</p>';
        echo '<table class="form-table">';
        foreach (Config::CATEGORIES as $category) {
            self::row(
                sprintf('%s (%s)', (string) self::value("categories.$category.label"), $category),
                self::text("categories.$category.label") . '<br><br>' . self::textarea("categories.$category.description"),
                '',
                self::id("categories.$category.label")
            );
        }
        echo '</table>';
        self::form_close();
    }

    private static function tab_services(): void
    {
        self::form_open('services');
        $presets = Presets::all();
        $rows = (array) self::value('services');
        $can_html = current_user_can('unfiltered_html');

        echo '<p>' . esc_html__('Add each tracker or third-party tool the site uses. Scripts load only after the visitor allows their category.', 'bananaland-consent') . '</p>';
        if (!$can_html) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('Your account cannot save raw HTML, so custom service code is read-only.', 'bananaland-consent') . '</p></div>';
        }
        echo '<div class="blc-services-list" data-blc-services>';
        foreach (array_values($rows) as $index => $row) {
            self::service_row((string) $index, is_array($row) ? $row : [], $presets, $can_html);
        }
        echo '</div>';
        echo '<template data-blc-service-template>';
        self::service_row('__INDEX__', ['preset' => 'ga4', 'enabled' => true], $presets, $can_html);
        echo '</template>';
        echo '<p><button type="button" class="button" data-blc-add-service>' . esc_html__('Add service', 'bananaland-consent') . '</button></p>';
        echo '<p class="description">' . esc_html__('Theme and plugin scripts can be held too: blc_require_consent( $handle, $category ) or <script type="text/plain" data-consent="analytics">.', 'bananaland-consent') . '</p>';
        self::form_close();
    }

    private static function service_row(string $index, array $row, array $presets, bool $can_html): void
    {
        $base = "blc[services][$index]";
        $preset_id = (string) ($row['preset'] ?? 'ga4');
        $cookies = implode("\n", array_map(static fn($c) => implode(' | ', [$c['name'] ?? '', $c['duration'] ?? '', $c['purpose'] ?? '']), (array) ($row['cookies'] ?? [])));
        $field_id = static fn($key) => esc_attr("blc-service-$index-$key");
        ?>
        <fieldset class="blc-service-row" data-blc-service-row data-preset="<?php echo esc_attr($preset_id); ?>">
            <legend class="screen-reader-text"><?php esc_html_e('Service', 'bananaland-consent'); ?></legend>
            <input type="hidden" name="<?php echo esc_attr($base); ?>[uid]" value="<?php echo esc_attr((string) ($row['uid'] ?? '')); ?>">
            <div class="blc-service-row__head">
                <label><input type="hidden" name="<?php echo esc_attr($base); ?>[enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr($base); ?>[enabled]" value="1" <?php checked(!isset($row['enabled']) || $row['enabled']); ?>> <?php esc_html_e('Enabled', 'bananaland-consent'); ?></label>
                <label for="<?php echo $field_id('preset'); ?>"><?php esc_html_e('Type', 'bananaland-consent'); ?></label>
                <select id="<?php echo $field_id('preset'); ?>" name="<?php echo esc_attr($base); ?>[preset]" data-blc-preset>
                    <?php foreach ($presets as $id => $preset) : ?>
                        <option value="<?php echo esc_attr($id); ?>" <?php selected($preset_id, $id); ?>><?php echo esc_html($preset['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="<?php echo $field_id('category'); ?>"><?php esc_html_e('Category', 'bananaland-consent'); ?></label>
                <select id="<?php echo $field_id('category'); ?>" name="<?php echo esc_attr($base); ?>[category]" data-blc-category>
                    <?php foreach (Config::CATEGORIES as $category) : ?>
                        <option value="<?php echo esc_attr($category); ?>" <?php selected((string) ($row['category'] ?? ($presets[$preset_id]['category'] ?? 'analytics')), $category); ?>><?php echo esc_html((string) Config::get("categories.$category.label", $category)); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button-link button-link-delete" data-blc-remove-service><?php esc_html_e('Remove', 'bananaland-consent'); ?></button>
            </div>
            <div class="blc-service-row__grid">
                <p data-blc-preset-field>
                    <label for="<?php echo $field_id('id'); ?>" data-blc-id-label><?php echo esc_html($presets[$preset_id]['fields']['id']['label'] ?? __('ID', 'bananaland-consent')); ?></label>
                    <input type="text" id="<?php echo $field_id('id'); ?>" name="<?php echo esc_attr($base); ?>[settings][id]" value="<?php echo esc_attr((string) ($row['settings']['id'] ?? '')); ?>" class="regular-text code">
                </p>
                <p>
                    <label for="<?php echo $field_id('label'); ?>"><?php esc_html_e('Display name (optional)', 'bananaland-consent'); ?></label>
                    <input type="text" id="<?php echo $field_id('label'); ?>" name="<?php echo esc_attr($base); ?>[label]" value="<?php echo esc_attr((string) ($row['label'] ?? '')); ?>" class="regular-text">
                </p>
                <p data-blc-custom-field>
                    <label for="<?php echo $field_id('provider'); ?>"><?php esc_html_e('Provider', 'bananaland-consent'); ?></label>
                    <input type="text" id="<?php echo $field_id('provider'); ?>" name="<?php echo esc_attr($base); ?>[provider]" value="<?php echo esc_attr((string) ($row['provider'] ?? '')); ?>" class="regular-text">
                </p>
                <p data-blc-custom-field>
                    <label for="<?php echo $field_id('privacy'); ?>"><?php esc_html_e('Provider privacy policy URL', 'bananaland-consent'); ?></label>
                    <input type="url" id="<?php echo $field_id('privacy'); ?>" name="<?php echo esc_attr($base); ?>[privacy_url]" value="<?php echo esc_attr((string) ($row['privacy_url'] ?? '')); ?>" class="regular-text">
                </p>
                <p class="blc-wide" data-blc-custom-field>
                    <label for="<?php echo $field_id('head'); ?>"><?php esc_html_e('Code for <head>', 'bananaland-consent'); ?></label>
                    <textarea id="<?php echo $field_id('head'); ?>" name="<?php echo esc_attr($base); ?>[settings][head]" rows="4" class="large-text code" <?php disabled(!$can_html); ?>><?php echo esc_textarea((string) ($row['settings']['head'] ?? '')); ?></textarea>
                </p>
                <p class="blc-wide" data-blc-custom-field>
                    <label for="<?php echo $field_id('body'); ?>"><?php esc_html_e('Code for the page body', 'bananaland-consent'); ?></label>
                    <textarea id="<?php echo $field_id('body'); ?>" name="<?php echo esc_attr($base); ?>[settings][body]" rows="4" class="large-text code" <?php disabled(!$can_html); ?>><?php echo esc_textarea((string) ($row['settings']['body'] ?? '')); ?></textarea>
                </p>
                <p class="blc-wide">
                    <label for="<?php echo $field_id('desc'); ?>"><?php esc_html_e('Description shown to visitors (optional)', 'bananaland-consent'); ?></label>
                    <input type="text" id="<?php echo $field_id('desc'); ?>" name="<?php echo esc_attr($base); ?>[description]" value="<?php echo esc_attr((string) ($row['description'] ?? '')); ?>" class="large-text">
                </p>
                <p class="blc-wide">
                    <label for="<?php echo $field_id('cookies'); ?>"><?php esc_html_e('Cookies (one per line: name | duration | purpose). Leave empty to use the preset list.', 'bananaland-consent'); ?></label>
                    <textarea id="<?php echo $field_id('cookies'); ?>" name="<?php echo esc_attr($base); ?>[cookies]" rows="3" class="large-text code"><?php echo esc_textarea($cookies); ?></textarea>
                </p>
            </div>
            <?php if (!empty($presets[$preset_id]['note'])) : ?>
                <p class="description blc-service-row__note"><?php echo esc_html($presets[$preset_id]['note']); ?></p>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    private static function tab_regions(): void
    {
        self::form_open('regions');
        $data = Rules::data();
        $overrides = (array) self::value('regions.overrides');
        $lines = implode("\n", array_map(static fn($code, $mode) => "$code = $mode", array_keys($overrides), $overrides));
        $modes = ['opt-in' => __('Opt-in (consent required first)', 'bananaland-consent'), 'opt-out' => __('Opt-out (on by default, easy opt-out, GPC honored)', 'bananaland-consent'), 'notice' => __('Notice only', 'bananaland-consent')];

        echo '<table class="form-table">';
        self::row(__('Unknown location', 'bananaland-consent'), self::select('regions.unknown_mode', $modes), __('Used when the host provides no location and the timezone gives no clear answer. Opt-in is the safe default.', 'bananaland-consent'), self::id('regions.unknown_mode'));
        self::row(__('Timezone fallback', 'bananaland-consent'), self::checkbox('regions.timezone_fallback', __('When the host provides no location, use the browser timezone (Europe → opt-in, U.S. → opt-out)', 'bananaland-consent')));
        self::row(
            __('Overrides', 'bananaland-consent'),
            sprintf('<textarea id="%s" name="%s" rows="5" class="large-text code">%s</textarea>', esc_attr(self::id('regions.overrides')), esc_attr(self::name('regions.overrides')), esc_textarea($lines)),
            __('One per line, e.g. <code>US = opt-in</code> or <code>CA-ON = opt-in</code>. Subdivisions use ISO 3166-2 codes.', 'bananaland-consent'),
            self::id('regions.overrides')
        );
        echo '</table>';
        self::form_close();

        echo '<h2>' . esc_html__('Built-in rules', 'bananaland-consent') . '</h2>';
        echo '<p>' . esc_html(sprintf(__('Rules updated %s. Plugin updates keep them current.', 'bananaland-consent'), $data['updated'] ?? '')) . '</p>';
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th>' . esc_html__('Opt-in', 'bananaland-consent') . '</th><td><code>' . esc_html(implode(' ', array_merge($data['opt_in']['countries'] ?? [], $data['opt_in']['subdivisions'] ?? []))) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('Opt-out', 'bananaland-consent') . '</th><td><code>' . esc_html(implode(' ', $data['opt_out']['countries'] ?? [])) . '</code> ' . esc_html__('(every U.S. state gets the opt-out model; states with comprehensive privacy laws:', 'bananaland-consent') . ' <code>' . esc_html(implode(' ', $data['us_privacy_states'] ?? [])) . '</code>)</td></tr>';
        echo '<tr><th>' . esc_html__('Everywhere else', 'bananaland-consent') . '</th><td>' . esc_html($data['default'] ?? 'notice') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Geolocation source', 'bananaland-consent') . '</th><td>' . esc_html(get_option('blc_geo_source', '') ?: __('not seen yet', 'bananaland-consent')) . '</td></tr>';
        echo '</tbody></table>';
    }

    private static function tab_log(): void
    {
        $lookup = sanitize_text_field(wp_unslash($_GET['consent_id'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        echo '<form method="get" class="blc-lookup"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><input type="hidden" name="tab" value="log">';
        echo '<label for="blc-lookup">' . esc_html__('Look up a consent ID', 'bananaland-consent') . '</label> ';
        echo '<input type="text" id="blc-lookup" name="consent_id" value="' . esc_attr($lookup) . '" class="regular-text code" placeholder="xxxxxxxx-xxxx-4xxx-xxxx-xxxxxxxxxxxx"> ';
        submit_button(__('Search', 'bananaland-consent'), 'secondary', '', false);
        echo '</form>';

        if ($lookup !== '') {
            $rows = Repository::find($lookup);
            if (!$rows) {
                echo '<p>' . esc_html__('No records for that ID.', 'bananaland-consent') . '</p>';
            } else {
                echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('When (UTC)', 'bananaland-consent') . '</th><th>' . esc_html__('Method', 'bananaland-consent') . '</th><th>' . esc_html__('Categories', 'bananaland-consent') . '</th><th>' . esc_html__('Model', 'bananaland-consent') . '</th><th>GPC</th><th>' . esc_html__('Region', 'bananaland-consent') . '</th><th>' . esc_html__('Policy', 'bananaland-consent') . '</th><th>' . esc_html__('Page', 'bananaland-consent') . '</th></tr></thead><tbody>';
                foreach ($rows as $row) {
                    printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html($row['created_at']), esc_html($row['method']), esc_html($row['categories']), esc_html($row['mode']), $row['gpc'] ? '✓' : '', esc_html($row['region']), esc_html($row['policy_version']), esc_html($row['url']));
                }
                echo '</tbody></table>';
            }
        }

        echo '<h2>' . esc_html__('Export', 'bananaland-consent') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="blc_export_log">';
        wp_nonce_field('blc_export_log');
        echo '<label>' . esc_html__('From', 'bananaland-consent') . ' <input type="date" name="from"></label> <label>' . esc_html__('To', 'bananaland-consent') . ' <input type="date" name="to"></label> ';
        submit_button(__('Download CSV', 'bananaland-consent'), 'secondary', '', false);
        echo '</form>';
        echo '<p class="description">' . esc_html(sprintf(__('Records are kept for %d days. IP addresses are truncated and hashed before storage; the log cannot identify a visitor without their consent ID.', 'bananaland-consent'), (int) Config::get('log.retention_days'))) . '</p>';
    }

    private static function tab_tools(): void
    {
        echo '<h2>' . esc_html__('Scan the site', 'bananaland-consent') . '</h2>';
        echo '<p>' . esc_html__('Loads a few pages as a first-time visitor who has not chosen anything, then lists cookies and third-party requests. Anything here loads before consent and should be declared as a service or removed.', 'bananaland-consent') . '</p>';
        echo '<p><button type="button" class="button button-primary" data-blc-scan>' . esc_html__('Run scan', 'bananaland-consent') . '</button></p>';
        echo '<div data-blc-scan-results aria-live="polite"></div>';

        echo '<h2>' . esc_html__('Export settings', 'bananaland-consent') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="blc_export">';
        wp_nonce_field('blc_export');
        submit_button(__('Download settings JSON', 'bananaland-consent'), 'secondary', '', false);
        echo '</form>';

        echo '<h2>' . esc_html__('Import settings', 'bananaland-consent') . '</h2>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="blc_import">';
        wp_nonce_field('blc_import');
        echo '<input type="file" name="settings" accept="application/json,.json" required> ';
        submit_button(__('Import (replaces current settings)', 'bananaland-consent'), 'secondary', '', false);
        echo '</form>';
    }

    /* ---------- Handlers ---------- */

    private static function guard(string $nonce): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('You are not allowed to do that.', 'bananaland-consent'), 403);
        }
        check_admin_referer($nonce);
    }

    public static function save(): void
    {
        $section = sanitize_key($_POST['section'] ?? '');
        if (!isset(Settings::SECTIONS[$section])) {
            wp_die('Unknown section', 400);
        }
        self::guard('blc_save_' . $section);
        $input = wp_unslash((array) ($_POST['blc'] ?? []));
        if ($section === 'services' && !isset($input['services'])) {
            $input['services'] = [];
        }
        $clean = Settings::sanitize($input, [$section]);
        $saved = Config::saved();
        foreach (Settings::SECTIONS[$section] as $key) {
            if (array_key_exists($key, $clean)) {
                $saved[$key] = $clean[$key];
            }
        }
        update_option(Config::OPTION, $saved, true);
        wp_safe_redirect(self::url($section === 'general' ? 'general' : $section, ['blc_notice' => 'saved']));
        exit;
    }

    public static function bump_version(): void
    {
        self::guard('blc_bump_version');
        $saved = Config::saved();
        $current = (string) ($saved['policy_version'] ?? '1');
        $saved['policy_version'] = ctype_digit($current) ? (string) ((int) $current + 1) : $current . '.1';
        update_option(Config::OPTION, $saved, true);
        wp_safe_redirect(self::url('general', ['blc_notice' => 'bumped']));
        exit;
    }

    public static function export(): void
    {
        self::guard('blc_export');
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="consent-settings-' . sanitize_file_name((string) wp_parse_url(home_url(), PHP_URL_HOST)) . '-' . gmdate('Y-m-d') . '.json"');
        echo wp_json_encode(['plugin' => 'bananaland-consent', 'version' => BLC_VERSION, 'settings' => Config::saved()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function import(): void
    {
        self::guard('blc_import');
        $file = $_FILES['settings']['tmp_name'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $data = $file && is_uploaded_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data) || ($data['plugin'] ?? '') !== 'bananaland-consent' || !is_array($data['settings'] ?? null)) {
            wp_safe_redirect(self::url('tools', ['blc_notice' => 'import_failed']));
            exit;
        }
        $clean = Settings::sanitize($data['settings'], array_keys(Settings::SECTIONS));
        update_option(Config::OPTION, $clean, true);
        wp_safe_redirect(self::url('tools', ['blc_notice' => 'imported']));
        exit;
    }

    public static function export_log(): void
    {
        self::guard('blc_export_log');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['from'] ?? '')) ? $_POST['from'] : null; // phpcs:ignore
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['to'] ?? '')) ? $_POST['to'] : null; // phpcs:ignore
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="consent-log-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        Repository::export_csv($out, $from, $to);
        fclose($out);
        exit;
    }

    public static function check_updates(): void
    {
        self::guard('blc_check_updates');
        GitHubUpdater::latest_release(true);
        delete_site_transient('update_plugins');
        wp_safe_redirect(self::url('dashboard', ['blc_notice' => 'checked']));
        exit;
    }

    /* ---------- Scan helpers ---------- */

    /**
     * Hosts the registry expects, so the scan only flags undeclared ones.
     */
    private static function declared_hosts(): array
    {
        $hosts = [];
        foreach (Registry::all() as $service) {
            if (preg_match_all('#https?://([a-z0-9.-]+)#i', $service['head'] . $service['body'], $m)) {
                $hosts = array_merge($hosts, $m[1]);
            }
        }
        $cookies = [];
        foreach (Registry::cookie_names_by_category() as $names) {
            $cookies = array_merge($cookies, $names);
        }
        return ['hosts' => array_values(array_unique($hosts)), 'cookies' => array_values(array_unique(array_merge($cookies, ['blc_consent', 'blc_region'])))];
    }

    private static function scan_urls(): array
    {
        $urls = [home_url('/')];
        $posts = get_posts(['post_type' => 'any', 'post_status' => 'publish', 'numberposts' => 4, 'orderby' => 'modified', 'fields' => 'ids']);
        foreach ($posts as $id) {
            $urls[] = get_permalink($id);
        }
        return array_values(array_unique(array_map(static fn($u) => add_query_arg('blc_scan', '1', $u), array_filter($urls))));
    }
}
