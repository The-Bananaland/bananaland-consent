<?php

require dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}
if (!defined('BLC_VERSION')) {
    define('BLC_VERSION', '0.1.0');
    define('BLC_DIR', dirname(__DIR__) . '/');
    define('BLC_URL', 'https://example.test/wp-content/plugins/bananaland-consent/');
    define('BLC_FILE', BLC_DIR . 'bananaland-consent.php');
    define('BLC_REPO', 'The-Bananaland/bananaland-consent');
}

require dirname(__DIR__) . '/src/Autoloader.php';
\Bananaland\Consent\Autoloader::register();
