<?php

declare(strict_types=1);

namespace RSS;

use RuntimeException;

/**
 * Configuration loader with support for local overrides and path normalization.
 */
final class Config
{
    /**
     * Load the base configuration and merge optional overrides.
     *
     * @param string $baseDir Project root directory.
     *
     * @return array<string, mixed>
     */
    public static function load(string $baseDir): array
    {
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);
        if ($baseDir === '') {
            throw new RuntimeException('Base directory for configuration cannot be empty.');
        }

        $config = self::requireConfigFile($baseDir . '/app/config.php');

        $localPath = $baseDir . '/app/config.local.php';
        if (is_file($localPath)) {
            $local = self::requireConfigFile($localPath);
            $config = self::mergeRecursive($config, $local);
        }

        $config['paths'] = self::normalizePaths($config['paths'] ?? [], $baseDir);
        $config['timezone'] = self::normalizeTimezone($config['timezone'] ?? 'UTC');

        date_default_timezone_set($config['timezone']);

        return $config;
    }

    /**
     * @param array<string, mixed> $paths
     * @param string               $baseDir
     *
     * @return array<string, string>
     */
    private static function normalizePaths(array $paths, string $baseDir): array
    {
        $base = self::resolvePath($paths['base'] ?? $baseDir, $baseDir);

        return [
            'base' => $base,
            'storage' => self::resolvePath($paths['storage'] ?? 'storage', $base),
            'backups' => self::resolvePath($paths['backups'] ?? 'storage/backups', $base),
            'logs' => self::resolvePath($paths['logs'] ?? 'logs', $base),
            'public' => self::resolvePath($paths['public'] ?? 'public', $base),
            'database' => self::resolvePath($paths['database'] ?? 'storage/index/database.sqlite', $base),
        ];
    }

    private static function normalizeTimezone(mixed $timezone): string
    {
        if (!is_string($timezone) || trim($timezone) === '') {
            return 'UTC';
        }

        $timezone = trim($timezone);

        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new RuntimeException(sprintf('Invalid timezone configured: %s', $timezone), 0, $e);
        }

        return $timezone;
    }

    /**
     * Recursively merge configuration arrays with the override taking precedence.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function mergeRecursive(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (array_key_exists($key, $base) && is_array($base[$key]) && is_array($value)) {
                $base[$key] = self::mergeRecursive($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param string $path
     *
     * @return array<string, mixed>
     */
    private static function requireConfigFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Configuration file not found: %s', $path));
        }

        $config = null;
        /** @var mixed $result */
        $result = require $path;

        if (is_array($result)) {
            return $result;
        }

        if (is_array($config)) {
            return $config;
        }

        throw new RuntimeException(sprintf('Configuration file %s must return an array.', $path));
    }

    private static function resolvePath(mixed $path, string $baseDir): string
    {
        if (!is_string($path) || $path === '') {
            return $baseDir;
        }

        if (self::isAbsolutePath($path)) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        return rtrim($baseDir . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return (bool) preg_match('#^[A-Za-z]:\\\\#', $path);
    }
}
