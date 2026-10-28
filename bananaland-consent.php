<?php
/**
 * Plugin Name:       Bananaland Consent
 * Plugin URI:        https://github.com/The-Bananaland/bananaland-consent
 * Description:       Region-aware cookie consent, Google Consent Mode v2, Global Privacy Control and consent records.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            The Bananaland
 * Author URI:        https://thebananaland.com
 * License:           GPL-2.0-or-later
 * Text Domain:       bananaland-consent
 * Domain Path:       /languages
 * Update URI:        https://github.com/The-Bananaland/bananaland-consent
 */

defined('ABSPATH') || exit;

define('BLC_VERSION', '0.1.0');
define('BLC_FILE', __FILE__);
define('BLC_DIR', plugin_dir_path(__FILE__));
define('BLC_URL', plugin_dir_url(__FILE__));
define('BLC_REPO', 'The-Bananaland/bananaland-consent');

require_once BLC_DIR . 'src/Autoloader.php';
\Bananaland\Consent\Autoloader::register();
require_once BLC_DIR . 'src/functions.php';

register_activation_hook(__FILE__, [\Bananaland\Consent\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\Bananaland\Consent\Plugin::class, 'deactivate']);

add_action('plugins_loaded', [\Bananaland\Consent\Plugin::class, 'boot']);
