<?php

namespace Bananaland\Consent;

use Bananaland\Consent\Admin\Admin;
use Bananaland\Consent\ConsentLog\Repository;
use Bananaland\Consent\Embeds\Embeds;
use Bananaland\Consent\Frontend\Output;
use Bananaland\Consent\Rest\Controller;
use Bananaland\Consent\Services\ScriptTagger;
use Bananaland\Consent\Updater\GitHubUpdater;

final class Plugin
{
    public static function boot(): void
    {
        load_plugin_textdomain('bananaland-consent', false, dirname(plugin_basename(BLC_FILE)) . '/languages');

        Repository::maybe_upgrade();
        Output::register();
        ScriptTagger::register();
        Controller::register();
        Embeds::register();
        Declaration::register();
        GitHubUpdater::register();

        add_action('blc_purge_log', [Repository::class, 'purge']);
        add_action('update_option_' . Config::OPTION, static function () {
            Config::reset();
            Services\Registry::reset();
            Templates\View::reset();
        });

        if (is_admin()) {
            Admin::register();
        }
    }

    public static function activate(): void
    {
        require_once BLC_DIR . 'src/Autoloader.php';
        Autoloader::register();
        Repository::install();
        if (!wp_next_scheduled('blc_purge_log')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'blc_purge_log');
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('blc_purge_log');
    }
}
