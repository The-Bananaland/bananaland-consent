<?php

namespace Bananaland\Consent\Admin;

use Bananaland\Consent\Config;
use Bananaland\Consent\ConsentLog\Repository;
use Bananaland\Consent\Kit;
use Bananaland\Consent\Regions\Resolver;
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
        'dashboard' => 'Overview',
        'services' => 'Services',
        'banner' => 'Banner',
        'text' => 'Text',
        'settings' => 'Settings',
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
                'bumped' => __('Done. Every visitor will see the banner again.', 'bananaland-consent'),
                'imported' => __('Settings imported.', 'bananaland-consent'),
                'import_failed' => __('That file is not a valid settings export.', 'bananaland-consent'),
                'checked' => __('Checked GitHub for updates.', 'bananaland-consent'),
            ];
            if (isset($messages[$notice])) {
                $class = $notice === 'import_failed' ? 'notice-error' : 'notice-success';
                echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
            }
        }
        if (array_filter(Locator::overrides(), static fn($o) => $o['outdated'])) {
            echo '<div class="notice notice-warning"><p>' . wp_kses_post(sprintf(__('Some of the theme\'s banner templates are older than the plugin\'s. <a href="%s">See which</a>.', 'bananaland-consent'), esc_url(self::url('tools')))) . '</p></div>';
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

    /**
     * Value the theme's consent/config.php sets for this path, or null when the theme leaves it alone.
     */
    private static function theme_value(string $path): mixed
    {
        $value = Config::theme();
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    /**
     * Fields the theme sets are shown read-only with the theme's value; the saved value rides along hidden.
     */
    private static function locked(string $path, string $field): string
    {
        if (self::theme_value($path) === null) {
            return $field;
        }
        $saved = self::value($path);
        $hidden = is_scalar($saved) || $saved === null ? sprintf('<input type="hidden" name="%s" value="%s">', esc_attr(self::name($path)), esc_attr((string) $saved)) : '';
        $field = preg_replace('/\sname="[^"]*"/', '', $field);
        $field = preg_replace('/<(input|select|textarea)\b/', '<$1 disabled', $field);
        return $hidden . $field . ' <span class="blc-badge" title="' . esc_attr__('Set in the theme\'s consent/config.php', 'bananaland-consent') . '">' . esc_html__('Set by theme', 'bananaland-consent') . '</span>';
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

    private static function shown(string $path): mixed
    {
        return self::theme_value($path) ?? self::value($path);
    }

    private static function text(string $path, string $class = 'regular-text'): string
    {
        return self::locked($path, sprintf('<input type="text" id="%s" name="%s" value="%s" class="%s">', esc_attr(self::id($path)), esc_attr(self::name($path)), esc_attr((string) self::shown($path)), esc_attr($class)));
    }

    private static function textarea(string $path, int $rows = 3): string
    {
        return self::locked($path, sprintf('<textarea id="%s" name="%s" rows="%d" class="large-text">%s</textarea>', esc_attr(self::id($path)), esc_attr(self::name($path)), $rows, esc_textarea((string) self::shown($path))));
    }

    private static function number(string $path, int $min, int $max): string
    {
        return self::locked($path, sprintf('<input type="number" id="%s" name="%s" value="%d" min="%d" max="%d" class="small-text">', esc_attr(self::id($path)), esc_attr(self::name($path)), (int) self::shown($path), $min, $max));
    }

    private static function checkbox(string $path, string $label): string
    {
        if (self::theme_value($path) !== null) {
            return self::locked($path, sprintf('<label><input type="checkbox" id="%s" value="1"%s> %s</label>', esc_attr(self::id($path)), checked((bool) self::shown($path), true, false), esc_html($label)));
        }
        return sprintf('<label><input type="hidden" name="%2$s" value="0"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> %4$s</label>', esc_attr(self::id($path)), esc_attr(self::name($path)), checked((bool) self::value($path), true, false), esc_html($label));
    }

    private static function select(string $path, array $options): string
    {
        $html = sprintf('<select id="%s" name="%s">', esc_attr(self::id($path)), esc_attr(self::name($path)));
        foreach ($options as $value => $label) {
            $html .= sprintf('<option value="%s"%s>%s</option>', esc_attr($value), selected((string) self::shown($path), (string) $value, false), esc_html($label));
        }
        return self::locked($path, $html . '</select>');
    }

    private static function page(string $path): string
    {
        return self::locked($path, (string) wp_dropdown_pages([
            'name' => self::name($path),
            'id' => self::id($path),
            'selected' => (int) self::shown($path),
            'show_option_none' => __('— Select —', 'bananaland-consent'),
            'option_none_value' => '0',
            'echo' => false,
        ]));
    }

    private static function color(string $path): string
    {
        return self::locked($path, sprintf('<input type="color" id="%s" name="%s" value="%s">', esc_attr(self::id($path)), esc_attr(self::name($path)), esc_attr((string) self::shown($path))));
    }

    private static function section(string $title, string $intro = ''): void
    {
        echo '<h2 class="blc-section-title">' . esc_html($title) . '</h2>';
        if ($intro) {
            echo '<p class="blc-section-intro">' . wp_kses_post($intro) . '</p>';
        }
    }

    /**
     * Collapsible group for settings most sites never touch.
     */
    private static function details_open(string $summary, bool $open = false): void
    {
        echo '<details class="blc-details"' . ($open ? ' open' : '') . '><summary>' . esc_html($summary) . '</summary><div class="blc-details__body">';
    }

    private static function details_close(): void
    {
        echo '</div></details>';
    }

    private static function mode_options(): array
    {
        return [
            'opt-in' => __('Ask first: nothing loads until they choose (opt-in)', 'bananaland-consent'),
            'opt-out' => __('Allow, with an easy way to turn things off (opt-out)', 'bananaland-consent'),
            'notice' => __('Just let them know (notice)', 'bananaland-consent'),
        ];
    }

    /* ---------- Tabs ---------- */

    private static function tab_dashboard(): void
    {
        $privacy = (int) Config::get('privacy_page') ?: (int) get_option('wp_page_for_privacy_policy');
        $services = Registry::all();
        $location = Resolver::resolve();
        $theme_services = self::theme_value('services') !== null;

        echo '<div class="blc-cards">';

        // Status
        echo '<section class="blc-card"><h2>' . esc_html__('Status', 'bananaland-consent') . '</h2><ul class="blc-status">';
        if ($privacy) {
            self::status(true, __('Privacy policy', 'bananaland-consent'), sprintf('<a href="%s">%s</a>', esc_url((string) get_permalink($privacy)), esc_html(get_the_title($privacy))));
        } else {
            self::status(false, __('Privacy policy', 'bananaland-consent'), sprintf('<a href="%s">%s</a>', esc_url(self::url('settings')), esc_html__('Choose the page', 'bananaland-consent')));
        }

        $configured = count(Config::get('services', []));
        if (!Registry::runs_here() && $configured) {
            self::status(null, __('Services', 'bananaland-consent'), sprintf('<a href="%s">%s</a>', esc_url(self::url('services')), esc_html__('Set to run on the live site only, so none load here.', 'bananaland-consent')));
        } elseif ($services) {
            $names = implode(', ', array_map(static fn($s) => $s['label'], $services));
            self::status(true, __('Services', 'bananaland-consent'), sprintf('<a href="%s">%s</a>', esc_url(self::url('services')), esc_html($names)));
        } elseif ($theme_services) {
            self::status(true, __('Services', 'bananaland-consent'), sprintf('<a href="%s">%s</a>', esc_url(self::url('services')), esc_html__('The theme adds its services on the live site only. Add others here.', 'bananaland-consent')));
        } else {
            self::status(false, __('Services', 'bananaland-consent'), sprintf('<a href="%s">%s</a>', esc_url(self::url('services')), esc_html__('Add the trackers this site uses', 'bananaland-consent')));
        }

        $sources = ['wpengine' => 'WP Engine GeoTarget', 'cloudflare' => 'Cloudflare', 'test' => __('test override', 'bananaland-consent')];
        if ($location['country']) {
            self::status(true, __('Visitor location', 'bananaland-consent'), esc_html(sprintf(
                /* translators: 1: provider, 2: country/region code */
                __('Detected by %1$s (you appear to be in %2$s).', 'bananaland-consent'),
                $sources[$location['source']] ?? $location['source'],
                $location['country'] . ($location['region'] ? '-' . $location['region'] : '')
            )));
        } else {
            self::status(null, __('Visitor location', 'bananaland-consent'), esc_html__('This server doesn\'t provide it (normal on a local site). Visitors are placed by their browser\'s time zone; anyone who can\'t be placed gets the "ask first" banner. On WP Engine, turn on GeoTarget, or use Cloudflare.', 'bananaland-consent'));
        }
        echo '</ul>';
        echo '<p class="blc-muted">' . wp_kses_post(__('Every page needs a link that reopens these choices, usually in the footer: <code>&lt;?php echo blc_preferences_link(); ?&gt;</code> or the <code>[blc_preferences_link]</code> shortcode.', 'bananaland-consent')) . '</p>';
        echo '</section>';

        // Ask again
        echo '<section class="blc-card"><h2>' . esc_html__('Ask everyone again', 'bananaland-consent') . '</h2>';
        echo '<p>' . esc_html__('Shows the banner to every visitor again, including people who already chose. Do this after you change the privacy policy or start using cookies for something new.', 'bananaland-consent') . '</p>';
        echo '<p class="blc-muted">' . esc_html__('Adding a service to a category someone already accepted asks them again automatically.', 'bananaland-consent') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="blc_bump_version">';
        wp_nonce_field('blc_bump_version');
        submit_button(__('Ask everyone again', 'bananaland-consent'), 'secondary', 'submit', false, ['onclick' => "return confirm('" . esc_js(__('Every visitor will see the banner again. Continue?', 'bananaland-consent')) . "');"]);
        echo ' <span class="blc-muted">' . esc_html(sprintf(__('Policy version %s', 'bananaland-consent'), Config::get('policy_version'))) . '</span>';
        echo '</form></section>';

        // Choices
        $labels = [
            'accept-all' => __('Accepted all', 'bananaland-consent'),
            'reject-all' => __('Rejected all', 'bananaland-consent'),
            'custom' => __('Chose some', 'bananaland-consent'),
            'opt-out' => __('Opted out of advertising', 'bananaland-consent'),
            'gpc' => __('Opted out by browser signal (GPC)', 'bananaland-consent'),
            'embed' => __('Allowed an embed', 'bananaland-consent'),
        ];
        echo '<section class="blc-card"><h2>' . esc_html__('Choices in the last 30 days', 'bananaland-consent') . '</h2>';
        $counts = Config::get('log.enabled') ? Repository::counts(30) : [];
        if (!$counts) {
            echo '<p class="blc-muted">' . esc_html__('No choices recorded yet. Visitors who just keep browsing in the U.S. or elsewhere don\'t count as a choice.', 'bananaland-consent') . '</p>';
        } else {
            echo '<table class="blc-counts"><tbody>';
            foreach ($counts as $method => $total) {
                echo '<tr><td>' . esc_html($labels[$method] ?? $method) . '</td><td>' . esc_html(number_format_i18n((int) $total)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p><a href="' . esc_url(self::url('log')) . '">' . esc_html__('Look up a visitor\'s record', 'bananaland-consent') . '</a></p>';
        echo '</section>';

        echo '</div>';
    }

    private static function status(?bool $ok, string $label, string $html): void
    {
        $icon = $ok === true ? 'dashicons-yes-alt' : ($ok === false ? 'dashicons-warning' : 'dashicons-info-outline');
        $class = $ok === true ? 'is-ok' : ($ok === false ? 'is-todo' : 'is-info');
        printf('<li class="%s"><span class="dashicons %s" aria-hidden="true"></span><span><strong>%s</strong><br>%s</span></li>', esc_attr($class), esc_attr($icon), esc_html($label), $html); // phpcs:ignore WordPress.Security.EscapeOutput -- $html is escaped by callers.
    }

    private static function tab_banner(): void
    {
        self::form_open('banner');
        self::section(__('Layout', 'bananaland-consent'));
        echo '<table class="form-table">';
        self::row(__('Style', 'bananaland-consent'), self::select('banner.layout', ['bar' => __('Bar across the page', 'bananaland-consent'), 'box' => __('Box in a corner', 'bananaland-consent'), 'modal' => __('Pop-up in the middle (EU/UK visitors only; a bar for everyone else)', 'bananaland-consent')]), '', self::id('banner.layout'));
        self::row(__('Position', 'bananaland-consent'), self::select('banner.position', ['bottom' => __('Bottom', 'bananaland-consent'), 'top' => __('Top (bar only)', 'bananaland-consent'), 'bottom-left' => __('Bottom left (box only)', 'bananaland-consent'), 'bottom-right' => __('Bottom right (box only)', 'bananaland-consent')]), '', self::id('banner.position'));
        self::row(__('Floating button', 'bananaland-consent'), self::checkbox('banner.floating_button', __('After a choice, keep a small cookie button on screen that reopens the choices', 'bananaland-consent')) . '<br>' . self::select('banner.floating_position', ['bottom-left' => __('Bottom left', 'bananaland-consent'), 'bottom-right' => __('Bottom right', 'bananaland-consent')]), __('Only needed if the footer has no privacy choices link.', 'bananaland-consent'));
        echo '</table>';

        self::section(__('U.S. visitors', 'bananaland-consent'));
        echo '<table class="form-table">';
        self::row(
            __('Opt-out button', 'bananaland-consent'),
            self::checkbox('banner.opt_out_button', __('Add an "Opt out of sale, sharing & targeted ads" button to the U.S. banner', 'bananaland-consent')),
            __('Leave this off unless the site runs advertising or retargeting pixels (Meta, LinkedIn, Google Ads). Without it, U.S. visitors see <strong>OK</strong> and <strong>Customize</strong>, can turn advertising off under Customize or from the footer link, and browser privacy signals (GPC) are still honored automatically.', 'bananaland-consent')
        );
        echo '</table>';

        self::section(__('Colors', 'bananaland-consent'));
        if (Kit::file('consent.css') || Kit::file('tokens.css')) {
            echo '<p class="blc-section-intro">' . esc_html__('This theme styles the banner itself (consent/tokens.css), so colors are set there rather than here.', 'bananaland-consent') . '</p>';
        } else {
            echo '<table class="form-table">';
            $labels = [
                'background' => __('Background', 'bananaland-consent'),
                'text' => __('Text', 'bananaland-consent'),
                'primary_bg' => __('Main buttons', 'bananaland-consent'),
                'primary_text' => __('Main button text', 'bananaland-consent'),
                'secondary_bg' => __('Other buttons', 'bananaland-consent'),
                'secondary_text' => __('Other button text', 'bananaland-consent'),
                'accent' => __('Switches and focus outline', 'bananaland-consent'),
            ];
            foreach ($labels as $key => $label) {
                self::row($label, self::color("colors.$key"), '', self::id("colors.$key"));
            }
            echo '</table>';
            $pairs = [
                [__('Text', 'bananaland-consent'), 'text', 'background', 4.5],
                [__('Main buttons', 'bananaland-consent'), 'primary_text', 'primary_bg', 4.5],
                [__('Other buttons', 'bananaland-consent'), 'secondary_text', 'secondary_bg', 4.5],
                [__('Switches', 'bananaland-consent'), 'accent', 'background', 3.0],
            ];
            foreach ($pairs as [$label, $fg, $bg, $min]) {
                $ratio = Settings::contrast((string) self::value("colors.$fg"), (string) self::value("colors.$bg"));
                if ($ratio < $min) {
                    echo '<div class="notice notice-warning inline"><p>' . esc_html(sprintf(__('%1$s: contrast is %2$s:1, below the %3$s:1 accessibility minimum.', 'bananaland-consent'), $label, $ratio, $min)) . '</p></div>';
                }
            }
        }
        self::form_close();
    }

    private static function tab_text(): void
    {
        self::form_open('text');
        echo '<p class="blc-section-intro">' . esc_html__('The defaults are written to be compliant. Change them if you need to, and have the client\'s counsel review any edits.', 'bananaland-consent') . '</p>';

        $labels = [
            'title' => __('Heading', 'bananaland-consent'),
            'body' => __('Message', 'bananaland-consent'),
            'accept' => __('Accept button', 'bananaland-consent'),
            'reject' => __('Reject button', 'bananaland-consent'),
            'customize' => __('Customize button', 'bananaland-consent'),
            'opt_out' => __('Opt-out button (if turned on under Banner)', 'bananaland-consent'),
            'intro' => __('Introduction', 'bananaland-consent'),
            'save' => __('Save button', 'bananaland-consent'),
            'close' => __('Close button (screen readers)', 'bananaland-consent'),
            'always_active' => __('"Always on" label', 'bananaland-consent'),
            'services' => __('"Services" label', 'bananaland-consent'),
            'gpc_notice' => __('Message when the browser sends a privacy signal (GPC)', 'bananaland-consent'),
            'consent_id' => __('"Consent ID" label', 'bananaland-consent'),
            'policy_link' => __('Privacy policy link', 'bananaland-consent'),
            'cookie_link' => __('Cookie list link', 'bananaland-consent'),
            'load_once' => __('"Load once" button', 'bananaland-consent'),
            'always' => __('"Always allow" button', 'bananaland-consent'),
        ];
        $groups = [
            'opt-in' => __('Banner for EU, UK and similar visitors (ask first)', 'bananaland-consent'),
            'opt-out' => __('Banner for U.S. visitors', 'bananaland-consent'),
            'notice' => __('Banner for everyone else', 'bananaland-consent'),
            'preferences' => __('Customize panel', 'bananaland-consent'),
            'links' => __('Footer link', 'bananaland-consent'),
            'embed' => __('Video and map placeholders', 'bananaland-consent'),
        ];
        $first = true;
        foreach ($groups as $group => $heading) {
            self::details_open($heading, $first);
            $first = false;
            echo '<table class="form-table">';
            foreach (array_keys(Config::defaults()['texts'][$group]) as $key) {
                $path = "texts.$group.$key";
                $label = $labels[$key] ?? $key;
                $help = '';
                if ($group === 'links') {
                    $label = $key === 'preferences' ? __('Link text', 'bananaland-consent') : __('Link text for U.S. visitors when advertising services are used', 'bananaland-consent');
                }
                if ($group === 'embed' && $key === 'body') {
                    $help = __('%s becomes the provider name, e.g. YouTube.', 'bananaland-consent');
                }
                $field = in_array($key, ['body', 'intro', 'gpc_notice'], true) ? self::textarea($path) : self::text($path, 'large-text');
                self::row($label, $field, $help, self::id($path));
            }
            echo '</table>';
            self::details_close();
        }

        self::details_open(__('Categories', 'bananaland-consent'));
        echo '<table class="form-table">';
        foreach (Config::CATEGORIES as $category) {
            self::row((string) self::value("categories.$category.label"), self::text("categories.$category.label") . '<br><br>' . self::textarea("categories.$category.description"), '', self::id("categories.$category.label"));
        }
        echo '</table>';
        self::details_close();
        self::form_close();
    }

    private static function tab_services(): void
    {
        $presets = Presets::all();
        $theme_rows = Config::theme_services();
        if ($theme_rows) {
            self::section(__('Added by the theme', 'bananaland-consent'), __('These are set in the theme\'s <code>consent/config.php</code> and deploy with the code, so they\'re edited there. Add anything else below.', 'bananaland-consent'));
            echo '<table class="widefat striped blc-theme-services"><thead><tr><th>' . esc_html__('Service', 'bananaland-consent') . '</th><th>' . esc_html__('ID', 'bananaland-consent') . '</th><th>' . esc_html__('Category', 'bananaland-consent') . '</th></tr></thead><tbody>';
            foreach ($theme_rows as $row) {
                $preset = $presets[$row['preset'] ?? ''] ?? null;
                $category = (string) ($row['category'] ?? ($preset['category'] ?? ''));
                printf(
                    '<tr><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
                    esc_html((string) (($row['label'] ?? '') ?: ($preset['label'] ?? ($row['preset'] ?? '')))),
                    esc_html((string) ($row['settings']['id'] ?? '')),
                    esc_html((string) Config::get("categories.$category.label", $category))
                );
            }
            echo '</tbody></table>';
        }

        self::form_open('services');
        $rows = (array) self::value('services');
        $can_html = current_user_can('unfiltered_html');

        if ($theme_rows) {
            self::section(__('Added here', 'bananaland-consent'));
        }
        echo '<p class="blc-section-intro">' . esc_html__('Add each tracker or marketing tag the site uses: pick a type and paste its ID, or choose Custom to paste any code snippet. Each one only loads once the visitor allows its category.', 'bananaland-consent') . '</p>';
        echo '<table class="form-table">';
        self::row(
            __('Where they run', 'bananaland-consent'),
            self::checkbox('services_live_only', __('Only on the live site', 'bananaland-consent')),
            sprintf(
                /* translators: %s: environment type */
                __('Keeps local and staging copies, which share this database, from sending visits to analytics and ad accounts. This environment is <code>%s</code>.', 'bananaland-consent'),
                esc_html(wp_get_environment_type())
            )
        );
        echo '</table>';
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

    private static function tab_settings(): void
    {
        self::form_open('settings');

        self::section(__('Pages', 'bananaland-consent'));
        echo '<table class="form-table">';
        self::row(__('Privacy policy', 'bananaland-consent'), self::page('privacy_page'), __('Linked from the banner. Defaults to the page chosen under Settings → Privacy.', 'bananaland-consent'), self::id('privacy_page'));
        self::row(__('Cookie list', 'bananaland-consent'), self::page('cookie_page'), __('Optional. Privacy laws expect you to list the cookies the site uses. Add the <strong>Cookie declaration</strong> block (or <code>[blc_cookie_declaration]</code>) to your privacy policy or a separate page and it builds that list from your services automatically. If it\'s a separate page, choose it here so the Customize panel links to it.', 'bananaland-consent'), self::id('cookie_page'));
        echo '</table>';

        self::section(__('Which banner visitors see', 'bananaland-consent'), __('Based on where the visitor is. The defaults match what each law requires.', 'bananaland-consent'));
        echo '<table class="form-table">';
        self::row(__('EU, EEA, UK, Switzerland, Quebec, Brazil', 'bananaland-consent'), '<p class="blc-fixed">' . esc_html__('Ask first (opt-in). Required by law, so it can\'t be changed.', 'bananaland-consent') . '</p>');
        self::row(__('United States', 'bananaland-consent'), self::select('regions.us', self::mode_options()), __('State privacy laws allow tracking by default as long as visitors can turn it off and browser privacy signals are honored.', 'bananaland-consent'), self::id('regions.us'));
        self::row(__('Everywhere else', 'bananaland-consent'), self::select('regions.other', self::mode_options()), '', self::id('regions.other'));
        self::row(__('Location unknown', 'bananaland-consent'), self::select('regions.unknown_mode', self::mode_options()), __('When neither the server nor the browser\'s time zone tells us where someone is. "Ask first" is the safe choice.', 'bananaland-consent'), self::id('regions.unknown_mode'));
        echo '</table>';

        self::details_open(__('Exceptions for specific countries or states', 'bananaland-consent'));
        $overrides = (array) (self::theme_value('regions.overrides') ?? self::value('regions.overrides'));
        $lines = implode("\n", array_map(static fn($code, $mode) => "$code = $mode", array_keys($overrides), $overrides));
        echo '<table class="form-table">';
        self::row(
            __('Exceptions', 'bananaland-consent'),
            self::locked('regions.overrides', sprintf('<textarea id="%s" name="%s" rows="4" class="large-text code" placeholder="CA = opt-in&#10;US-TX = notice">%s</textarea>', esc_attr(self::id('regions.overrides')), esc_attr(self::name('regions.overrides')), esc_textarea($lines))),
            __('Rarely needed. Give one country or state a different banner, one per line: a country or state code, <code>=</code>, then <code>opt-in</code>, <code>opt-out</code> or <code>notice</code>. Example: <code>CA = opt-in</code> asks all of Canada first; <code>US-TX = notice</code> gives Texas a plain notice. Exceptions can\'t loosen the EU/UK rule above.', 'bananaland-consent'),
            self::id('regions.overrides')
        );
        self::row(__('Time zone fallback', 'bananaland-consent'), self::checkbox('regions.timezone_fallback', __('If the server can\'t tell, use the browser\'s time zone (Europe → ask first, U.S. → opt-out)', 'bananaland-consent')));
        echo '</table>';
        self::details_close();

        self::section(__('Embedded videos and maps', 'bananaland-consent'));
        echo '<table class="form-table">';
        self::row(__('Click to load', 'bananaland-consent'), self::checkbox('embeds.enabled', __('Show a placeholder for YouTube, Vimeo, Google Maps and Spotify until the visitor allows them', 'bananaland-consent')), __('These services set their own cookies, so EU/UK visitors must agree before they load. YouTube switches to its privacy-enhanced mode either way.', 'bananaland-consent'));
        echo '</table>';

        self::section(__('Consent records', 'bananaland-consent'));
        echo '<table class="form-table">';
        self::row(__('Keep a record', 'bananaland-consent'), self::checkbox('log.enabled', __('Save each choice so you can prove consent if asked', 'bananaland-consent')), __('Stores the choice, date, page and an anonymized IP. Nothing that identifies the visitor without their consent ID.', 'bananaland-consent'));
        self::row(__('Keep records for', 'bananaland-consent'), self::number('log.retention_days', 30, 3650) . ' ' . esc_html__('days', 'bananaland-consent'));
        echo '</table>';

        self::details_open(__('Advanced', 'bananaland-consent'));
        echo '<h3>' . esc_html__('Google Consent Mode', 'bananaland-consent') . '</h3><table class="form-table">';
        self::row(__('Consent Mode', 'bananaland-consent'), self::checkbox('consent_mode.enabled', __('Tell Google tags what the visitor allowed', 'bananaland-consent')));
        self::row(__('Before consent', 'bananaland-consent'), self::select('consent_mode.mode', ['basic' => __('Don\'t load Google tags (Basic, recommended)', 'bananaland-consent'), 'advanced' => __('Load them without cookies (Advanced)', 'bananaland-consent')]), __('Advanced lets Google estimate visits from people who decline. Check that the privacy policy covers it.', 'bananaland-consent'), self::id('consent_mode.mode'));
        self::row(__('Ad click data', 'bananaland-consent'), self::checkbox('consent_mode.ads_data_redaction', __('Strip ad click IDs when advertising is declined', 'bananaland-consent')) . '<br>' . self::checkbox('consent_mode.url_passthrough', __('Pass ad click info through links instead of cookies', 'bananaland-consent')));
        echo '</table>';

        echo '<h3>' . esc_html__('Other iframes', 'bananaland-consent') . '</h3><table class="form-table">';
        self::row(__('All third-party iframes', 'bananaland-consent'), self::checkbox('embeds.generic_iframes', __('Also put a placeholder in front of any other outside iframe in page content', 'bananaland-consent')), __('Off by default because it can catch forms and widgets that don\'t set cookies.', 'bananaland-consent'));
        echo '</table>';

        echo '<h3>' . esc_html__('How long a choice lasts', 'bananaland-consent') . '</h3><table class="form-table">';
        self::row(__('Ask-first regions', 'bananaland-consent'), self::number('expiry.opt-in', 1, 395) . ' ' . esc_html__('days', 'bananaland-consent'), __('About six months is the usual EU guidance.', 'bananaland-consent'));
        self::row(__('U.S.', 'bananaland-consent'), self::number('expiry.opt-out', 1, 395) . ' ' . esc_html__('days', 'bananaland-consent'));
        self::row(__('Everywhere else', 'bananaland-consent'), self::number('expiry.notice', 1, 395) . ' ' . esc_html__('days', 'bananaland-consent'));
        echo '</table>';

        echo '<h3>' . esc_html__('Uninstall', 'bananaland-consent') . '</h3><table class="form-table">';
        self::row(__('Data', 'bananaland-consent'), self::checkbox('uninstall.delete_data', __('Delete settings and consent records when the plugin is deleted', 'bananaland-consent')));
        echo '</table>';
        self::details_close();

        self::form_close();
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
        self::section(__('Check the site', 'bananaland-consent'), __('Loads a few pages as a brand-new visitor who hasn\'t chosen anything and lists any cookies or outside requests that happen anyway. Anything flagged should be added under Services or removed.', 'bananaland-consent'));
        echo '<p><button type="button" class="button button-primary" data-blc-scan>' . esc_html__('Run check', 'bananaland-consent') . '</button></p>';
        echo '<div data-blc-scan-results aria-live="polite"></div>';

        self::section(__('Copy settings to another site', 'bananaland-consent'));
        echo '<div class="blc-tools-row">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="blc_export">';
        wp_nonce_field('blc_export');
        submit_button(__('Download settings', 'bananaland-consent'), 'secondary', '', false);
        echo '</form>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="blc_import">';
        wp_nonce_field('blc_import');
        echo '<label for="blc-import-file" class="screen-reader-text">' . esc_html__('Settings file', 'bananaland-consent') . '</label><input type="file" id="blc-import-file" name="settings" accept="application/json,.json" required> ';
        submit_button(__('Import (replaces current settings)', 'bananaland-consent'), 'secondary', '', false);
        echo '</form></div>';

        self::section(__('Plugin updates', 'bananaland-consent'));
        if (GitHubUpdater::is_dev_install()) {
            echo '<p>' . esc_html(sprintf(__('Version %s. This is a development copy (symlink or git checkout), so automatic updates are off.', 'bananaland-consent'), BLC_VERSION)) . '</p>';
        } else {
            $release = GitHubUpdater::latest_release();
            echo '<p>' . esc_html(sprintf(__('Version %1$s installed; latest release %2$s. Updates install automatically.', 'bananaland-consent'), BLC_VERSION, $release['version'] ?? __('unknown', 'bananaland-consent'))) . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="blc_check_updates">';
            wp_nonce_field('blc_check_updates');
            submit_button(__('Check now', 'bananaland-consent'), 'secondary', 'submit', false);
            echo '</form>';
        }

        $overrides = Locator::overrides();
        if ($overrides) {
            self::section(__('Theme templates', 'bananaland-consent'), __('The theme replaces these banner templates with its own copies (in its consent/ folder).', 'bananaland-consent'));
            echo '<ul class="ul-disc">';
            foreach ($overrides as $name => $info) {
                echo '<li><code>' . esc_html($name) . '.php</code> ';
                echo $info['outdated']
                    ? '<span class="blc-badge blc-badge--warn">' . esc_html(sprintf(__('needs updating: theme copy %1$s, plugin %2$s', 'bananaland-consent'), $info['theme_version'] ?: '?', $info['plugin_version'])) . '</span>'
                    : '<span class="blc-badge">' . esc_html__('up to date', 'bananaland-consent') . '</span>';
                echo '</li>';
            }
            echo '</ul>';
        }
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
        wp_safe_redirect(self::url($section, ['blc_notice' => 'saved']));
        exit;
    }

    public static function bump_version(): void
    {
        self::guard('blc_bump_version');
        $saved = Config::saved();
        $current = (string) ($saved['policy_version'] ?? '1');
        $saved['policy_version'] = ctype_digit($current) ? (string) ((int) $current + 1) : $current . '.1';
        update_option(Config::OPTION, $saved, true);
        wp_safe_redirect(self::url('dashboard', ['blc_notice' => 'bumped']));
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
        wp_safe_redirect(self::url('tools', ['blc_notice' => 'checked']));
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
