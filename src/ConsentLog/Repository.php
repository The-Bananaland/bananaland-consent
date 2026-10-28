<?php

namespace Bananaland\Consent\ConsentLog;

use Bananaland\Consent\Config;

/**
 * Proof-of-consent records in {prefix}blc_consent_log.
 */
final class Repository
{
    public const DB_VERSION = '1';

    public const METHODS = ['accept-all', 'reject-all', 'custom', 'opt-out', 'gpc', 'embed'];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'blc_consent_log';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            consent_id char(36) NOT NULL,
            categories varchar(191) NOT NULL DEFAULT '',
            policy_version varchar(32) NOT NULL DEFAULT '',
            mode varchar(16) NOT NULL DEFAULT '',
            method varchar(16) NOT NULL DEFAULT '',
            gpc tinyint(1) NOT NULL DEFAULT 0,
            region varchar(8) NOT NULL DEFAULT '',
            ip_hash char(64) NOT NULL DEFAULT '',
            ua_hash char(64) NOT NULL DEFAULT '',
            url varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY consent_id (consent_id),
            KEY created_at (created_at)
        ) {$charset};");
        update_option('blc_db_version', self::DB_VERSION, false);
    }

    public static function maybe_upgrade(): void
    {
        if (get_option('blc_db_version') !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function insert(array $record): bool
    {
        global $wpdb;
        return (bool) $wpdb->insert(self::table(), [
            'consent_id' => $record['consent_id'],
            'categories' => implode(',', $record['categories']),
            'policy_version' => substr((string) $record['policy_version'], 0, 32),
            'mode' => $record['mode'],
            'method' => $record['method'],
            'gpc' => $record['gpc'] ? 1 : 0,
            'region' => substr((string) ($record['region'] ?? ''), 0, 8),
            'ip_hash' => $record['ip_hash'],
            'ua_hash' => $record['ua_hash'],
            'url' => substr((string) $record['url'], 0, 255),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function find(string $consent_id): array
    {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE consent_id = %s ORDER BY id DESC LIMIT 200", $consent_id), ARRAY_A) ?: [];
    }

    /**
     * Counts by method for the last N days (aggregate only).
     */
    public static function counts(int $days = 30): array
    {
        global $wpdb;
        $table = self::table();
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT method, COUNT(*) AS total FROM {$table} WHERE created_at >= %s GROUP BY method", $since), ARRAY_A) ?: [];
        return wp_list_pluck($rows, 'total', 'method');
    }

    public static function purge(): int
    {
        global $wpdb;
        $days = max(30, (int) Config::get('log.retention_days', 1095));
        $table = self::table();
        $before = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $before));
    }

    /**
     * Stream rows as CSV in batches.
     */
    public static function export_csv($handle, ?string $from = null, ?string $to = null): void
    {
        global $wpdb;
        $table = self::table();
        fputcsv($handle, ['id', 'consent_id', 'categories', 'policy_version', 'mode', 'method', 'gpc', 'region', 'ip_hash', 'ua_hash', 'url', 'created_at_utc']);
        $last = 0;
        $from = $from ?: '1970-01-01';
        $to = $to ?: '2999-12-31';
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id > %d AND created_at >= %s AND created_at < %s ORDER BY id ASC LIMIT 1000",
                $last,
                $from . ' 00:00:00',
                $to . ' 23:59:59'
            ), ARRAY_A) ?: [];
            foreach ($rows as $row) {
                fputcsv($handle, array_values($row));
                $last = (int) $row['id'];
            }
        } while (count($rows) === 1000);
    }

    public static function drop(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::table()); // phpcs:ignore WordPress.DB.PreparedSQL
        delete_option('blc_db_version');
    }
}
