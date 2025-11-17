<?php

declare(strict_types=1);

namespace RSS;

use Exception;

/**
 * Security helper utilities for IP allowlisting, CSRF tokens, and SSRF defense.
 */
class Security
{
    private const CSRF_SESSION_KEY = '_rss_csrf_token';

    /**
     * Ensure the current request IP is in the configured allowlist.
     *
     * Supports exact IPs, CIDR notation, and explicit start-end ranges.
     *
     * @param array<int, string> $allowed
     * @throws Exception
     */
    public static function enforceIpAllowlist(array $allowed): void
    {
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remoteIp === '') {
            throw new Exception('Unable to determine remote IP address.');
        }

        foreach ($allowed as $rule) {
            if (!is_string($rule) || $rule === '') {
                continue;
            }

            if (self::ipMatchesRule($remoteIp, $rule)) {
                return;
            }
        }

        throw new Exception('Access denied from IP ' . $remoteIp);
    }

    /**
     * Generate or return an existing CSRF token for the session.
     */
    public static function getCsrfToken(): string
    {
        self::ensureSession();
        if (empty($_SESSION[self::CSRF_SESSION_KEY])) {
            $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::CSRF_SESSION_KEY];
    }

    /**
     * Validate a submitted CSRF token.
     */
    public static function validateCsrfToken(string $token): bool
    {
        self::ensureSession();
        $stored = $_SESSION[self::CSRF_SESSION_KEY] ?? null;
        if (!is_string($stored)) {
            return false;
        }

        return hash_equals($stored, $token);
    }

    /**
     * Guard HTTP requests from SSRF by resolving host IPs and verifying they are public.
     *
     * @param string $url
     * @param array<int, string> $allowedSchemes
     *
     * @throws Exception
     */
    public static function assertSafeUrl(string $url, array $allowedSchemes): void
    {
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw new Exception('Invalid URL');
        }

        if (!in_array(strtolower($parsed['scheme']), $allowedSchemes, true)) {
            throw new Exception('Unsupported URL scheme');
        }

        $host = $parsed['host'];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!self::isPublicIp($host)) {
                throw new Exception('Blocked private IP address: ' . $host);
            }
            return;
        }

        $ips = self::resolveHostIps($host);
        if (empty($ips)) {
            throw new Exception('Unable to resolve host');
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new Exception('Blocked private IP address: ' . $ip);
            }
        }
    }

    /**
     * Determine if the provided IP is public.
     */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return !self::isPrivateIpv6($ip);
        }

        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        // Exclude private, loopback, and reserved IPv4 ranges
        $privateRanges = [
            ['0.0.0.0', '2.255.255.255'],
            ['10.0.0.0', '10.255.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.0.2.0', '192.0.2.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['198.18.0.0', '198.19.255.255'],
            ['224.0.0.0', '239.255.255.255'],
            ['240.0.0.0', '255.255.255.255'],
        ];

        foreach ($privateRanges as [$start, $end]) {
            $startLong = ip2long($start);
            $endLong = ip2long($end);
            if ($startLong === false || $endLong === false) {
                continue;
            }

            if ($long >= $startLong && $long <= $endLong) {
                return false;
            }
        }

        return true;
    }

    private static function isPrivateIpv6(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        $prefix = bin2hex(substr($packed, 0, 2));
        $firstByte = hexdec(substr($prefix, 0, 2));

        // fc00::/7 unique local, fe80::/10 link-local
        if (($firstByte & 0xfe) === 0xfc) {
            return true;
        }

        if ($firstByte === 0xfe) {
            $secondByte = hexdec(substr($prefix, 2, 2));
            if (($secondByte & 0xc0) === 0x80) {
                return true;
            }
        }

        // ::1 loopback
        return $ip === '::1';
    }

    /**
     * @return array<int, string>
     */
    private static function resolveHostIps(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    /**
     * Determine whether an IP matches an allowlist rule.
     */
    private static function ipMatchesRule(string $ip, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '') {
            return false;
        }

        if ($rule === $ip) {
            return true;
        }

        if (str_contains($rule, '/')) {
            return self::cidrMatch($ip, $rule);
        }

        if (str_contains($rule, '-')) {
            [$start, $end] = array_pad(explode('-', $rule, 2), 2, null);
            if ($start === null || $end === null) {
                return false;
            }

            return self::rangeMatch($ip, trim($start), trim($end));
        }

        return false;
    }

    /**
     * Evaluate a CIDR rule against the provided IP address.
     */
    private static function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($subnet === null || $mask === null) {
            return false;
        }

        $subnet = trim($subnet);
        $maskBits = (int) $mask;

        $ipPacked = inet_pton($ip);
        $subnetPacked = inet_pton($subnet);
        if ($ipPacked === false || $subnetPacked === false) {
            return false;
        }

        if (strlen($ipPacked) !== strlen($subnetPacked)) {
            return false;
        }

        $maxBits = strlen($ipPacked) * 8;
        if ($maskBits < 0 || $maskBits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($maskBits, 8);
        $remainingBits = $maskBits % 8;

        if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($subnetPacked, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $ipByte = ord($ipPacked[$fullBytes]);
        $subnetByte = ord($subnetPacked[$fullBytes]);
        $maskByte = (~((1 << (8 - $remainingBits)) - 1)) & 0xFF;

        return ($ipByte & $maskByte) === ($subnetByte & $maskByte);
    }

    /**
     * Evaluate an explicit start-end IP range.
     */
    private static function rangeMatch(string $ip, string $start, string $end): bool
    {
        $ipPacked = inet_pton($ip);
        $startPacked = inet_pton($start);
        $endPacked = inet_pton($end);
        if ($ipPacked === false || $startPacked === false || $endPacked === false) {
            return false;
        }

        if (strlen($ipPacked) !== strlen($startPacked) || strlen($ipPacked) !== strlen($endPacked)) {
            return false;
        }

        if (strcmp($startPacked, $endPacked) > 0) {
            [$startPacked, $endPacked] = [$endPacked, $startPacked];
        }

        return strcmp($ipPacked, $startPacked) >= 0 && strcmp($ipPacked, $endPacked) <= 0;
    }

    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => true,
                'use_cookies' => true,
            ]);
        }
    }
}
