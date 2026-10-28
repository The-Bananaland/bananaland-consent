<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

$blc_settings = get_option('blc_settings', []);
if (!empty($blc_settings['uninstall']['delete_data'])) {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}blc_consent_log"); // phpcs:ignore
    delete_option('blc_settings');
    delete_option('blc_db_version');
    delete_option('blc_geo_source');
    delete_option('blc_link_seen');
}
