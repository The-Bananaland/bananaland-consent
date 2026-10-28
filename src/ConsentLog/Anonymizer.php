<?php

namespace Bananaland\Consent\ConsentLog;

/**
 * Truncates and hashes identifiers before they are stored.
 */
final class Anonymizer
{
    /**
     * Zero the last octet of IPv4 / the last 80 bits of IPv6.
     */
    public static function truncate_ip(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            $packed = substr($packed, 0, 6) . str_repeat("\0", 10);
            return inet_ntop($packed);
        }
        return '';
    }

    public static function hash(string $value, string $salt): string
    {
        return $value === '' ? '' : hash_hmac('sha256', $value, $salt);
    }
}
