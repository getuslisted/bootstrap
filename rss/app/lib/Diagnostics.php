<?php

declare(strict_types=1);

namespace RSS;

use PDOException;

/**
 * Run environment diagnostics for the RSS importer stack.
 */
class Diagnostics
{
    /**
     * Execute all diagnostic checks and return the results.
     *
     * @param array<string, mixed> $config
     * @return array<int, array{name: string, status: string, details: string}>
     */
    public static function runChecks(array $config): array
    {
        $results = [];
        $results[] = self::checkPhpVersion();
        $results[] = self::checkExtensions();
        $results[] = self::checkComposer($config);

        $paths = $config['paths'] ?? [];
        if (is_array($paths)) {
            $results[] = self::checkDirectory((string) ($paths['storage'] ?? ''), 'Storage directory', true);
            $results[] = self::checkDirectory((string) ($paths['storage'] ?? '') . '/feeds', 'Storage feeds directory', true);
            $results[] = self::checkDirectory((string) ($paths['storage'] ?? '') . '/index', 'Storage index directory', true);
            $results[] = self::checkDirectory((string) ($paths['storage'] ?? '') . '/tmp', 'Storage tmp directory', true);
            $results[] = self::checkDirectory((string) ($paths['backups'] ?? ((string) ($paths['storage'] ?? '') . '/backups')), 'Storage backups directory', true);
            $results[] = self::checkDirectory((string) ($paths['logs'] ?? ''), 'Logs directory', true);
            $results[] = self::checkDirectory((string) ($paths['public'] ?? ''), 'Public directory', true);
            $results[] = self::checkDirectory((string) ($paths['public'] ?? '') . '/feeds', 'Public feeds directory', true);
            $results[] = self::checkDirectory((string) ($paths['public'] ?? '') . '/status', 'Public status directory', true);
            $results[] = self::checkHtaccess((string) ($paths['storage'] ?? '') . '/.htaccess', 'Storage .htaccess');
            $results[] = self::checkHtaccess((string) ($paths['logs'] ?? '') . '/.htaccess', 'Logs .htaccess');
        } else {
            $results[] = self::result('Configured paths', 'error', 'Configuration paths array is missing.');
        }

        $results[] = self::checkDiskSpace($config);
        $results[] = self::checkDatabase();
        $results[] = self::checkAdminIpAllowlist($config);
        $results[] = self::checkJobStatusFile($config);
        $results[] = self::checkMetricsHistory($config);
        $results[] = self::checkAlertConfiguration($config);
        $results[] = Integrity::diagnostic($config);

        return $results;
    }

    /**
     * Inspect disk space for the configured storage path.
     *
     * @param array<string, mixed> $config
     * @return array{name: string, status: string, details: string}
     */
    private static function checkDiskSpace(array $config): array
    {
        $diskConfig = [];
        if (isset($config['diagnostics']) && is_array($config['diagnostics'])) {
            $candidate = $config['diagnostics']['disk'] ?? [];
            if (is_array($candidate)) {
                $diskConfig = $candidate;
            }
        }

        $paths = $config['paths'] ?? [];
        $path = null;
        if (isset($diskConfig['path']) && is_string($diskConfig['path']) && trim($diskConfig['path']) !== '') {
            $path = trim((string) $diskConfig['path']);
        } elseif (is_array($paths) && isset($paths['storage'])) {
            $path = (string) $paths['storage'];
        } elseif (is_array($paths) && isset($paths['base'])) {
            $path = (string) $paths['base'];
        }

        if ($path === null || $path === '') {
            return self::result('Disk space', 'warn', 'Storage path is not configured.');
        }

        if (!is_dir($path)) {
            return self::result('Disk space', 'error', 'Storage path does not exist: ' . $path);
        }

        $warnThreshold = isset($diskConfig['warn_free_bytes']) ? (int) $diskConfig['warn_free_bytes'] : 500 * 1024 * 1024;
        $errorThreshold = isset($diskConfig['error_free_bytes']) ? (int) $diskConfig['error_free_bytes'] : 200 * 1024 * 1024;

        if ($warnThreshold > 0 && $errorThreshold > $warnThreshold) {
            $warnThreshold = $errorThreshold;
        }

        if ($errorThreshold < 0) {
            $errorThreshold = 0;
        }

        if ($warnThreshold < 0) {
            $warnThreshold = 0;
        }

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return self::result('Disk space', 'warn', 'Unable to determine disk usage for ' . $path);
        }

        $status = 'ok';
        if ($errorThreshold > 0 && $free <= $errorThreshold) {
            $status = 'error';
        } elseif ($warnThreshold > 0 && $free <= $warnThreshold) {
            $status = 'warn';
        }

        $details = sprintf(
            'Free %s of %s (%.1f%% free) at %s',
            self::formatBytes((int) $free),
            self::formatBytes((int) $total),
            $total > 0 ? ($free / $total) * 100 : 0,
            $path
        );

        if ($status !== 'ok') {
            $details .= '. Consider pruning old data or expanding storage.';
        }

        return self::result('Disk space', $status, $details);
    }

    /**
     * Determine if any checks reported an error.
     *
     * @param array<int, array{name: string, status: string, details: string}> $checks
     */
    public static function hasErrors(array $checks): bool
    {
        foreach ($checks as $check) {
            if (isset($check['status']) && $check['status'] === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * Produce a short summary of diagnostic outcomes.
     *
     * @param array<int, array{name: string, status: string, details: string}> $checks
     * @return array{ok: int, warn: int, error: int}
     */
    public static function summarize(array $checks): array
    {
        $summary = ['ok' => 0, 'warn' => 0, 'error' => 0];
        foreach ($checks as $check) {
            $status = $check['status'] ?? 'ok';
            if (!isset($summary[$status])) {
                $summary[$status] = 0;
            }
            $summary[$status]++;
        }

        return $summary;
    }

    /**
     * Render a CLI friendly table of diagnostic output.
     *
     * @param array<int, array{name: string, status: string, details: string}> $checks
     */
    public static function formatCli(array $checks): string
    {
        $maxName = 5; // length of "Check"
        foreach ($checks as $check) {
            $maxName = max($maxName, strlen((string) $check['name']));
        }

        $lines = [];
        $lines[] = sprintf('%-' . $maxName . 's  %-5s  %s', 'Check', 'State', 'Details');
        $lines[] = str_repeat('-', $maxName + 2 + 5 + 2 + 40);
        foreach ($checks as $check) {
            $lines[] = sprintf(
                '%-' . $maxName . 's  %-5s  %s',
                (string) $check['name'],
                strtoupper((string) $check['status']),
                (string) $check['details']
            );
        }

        return implode("\n", $lines) . "\n";
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;
        $unitIndex = 0;
        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return sprintf('%.2f %s', $value, $units[$unitIndex]);
    }

    /**
     * Check that PHP satisfies the minimum version requirement.
     *
     * @return array{name: string, status: string, details: string}
     */
    private static function checkPhpVersion(): array
    {
        $version = PHP_VERSION;
        if (version_compare($version, '8.0.0', '>=')) {
            return self::result('PHP version', 'ok', 'Running PHP ' . $version);
        }

        return self::result('PHP version', 'error', 'PHP 8.0 or higher is required (detected ' . $version . ')');
    }

    /**
     * Ensure required PHP extensions are present.
     *
     * @return array{name: string, status: string, details: string}
     */
    private static function checkExtensions(): array
    {
        $required = ['pdo', 'pdo_sqlite', 'json', 'mbstring', 'curl', 'dom', 'libxml'];
        $missing = [];
        foreach ($required as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        if (empty($missing)) {
            return self::result('PHP extensions', 'ok', 'All required extensions are enabled.');
        }

        return self::result('PHP extensions', 'error', 'Missing extensions: ' . implode(', ', $missing));
    }

    /**
     * Validate Composer dependencies.
     *
     * @param array<string, mixed> $config
     * @return array{name: string, status: string, details: string}
     */
    private static function checkComposer(array $config): array
    {
        $base = (string) ($config['paths']['base'] ?? getcwd());
        $autoload = $base . '/vendor/autoload.php';
        if (is_file($autoload)) {
            return self::result('Composer autoload', 'ok', 'vendor/autoload.php is present.');
        }

        return self::result('Composer autoload', 'warn', 'vendor/autoload.php is missing. Run composer install.');
    }

    /**
     * Check that a directory exists and is writable if required.
     *
     * @return array{name: string, status: string, details: string}
     */
    private static function checkDirectory(string $path, string $label, bool $requireWritable): array
    {
        if ($path === '') {
            return self::result($label, 'error', 'Path is not configured.');
        }

        if (!is_dir($path)) {
            return self::result($label, 'error', 'Directory not found: ' . $path);
        }

        if ($requireWritable && !is_writable($path)) {
            return self::result($label, 'error', 'Directory is not writable: ' . $path);
        }

        return self::result($label, 'ok', 'Directory ready: ' . $path);
    }

    /**
     * Ensure private directories have .htaccess protection.
     *
     * @return array{name: string, status: string, details: string}
     */
    private static function checkHtaccess(string $path, string $label): array
    {
        if ($path === '' || !is_file($path)) {
            return self::result($label, 'error', '.htaccess file is missing.');
        }

        $contents = (string) file_get_contents($path);
        $normalized = strtolower($contents);
        if (str_contains($normalized, 'deny from all') || str_contains($normalized, 'require all denied')) {
            return self::result($label, 'ok', '.htaccess denies public access.');
        }

        return self::result($label, 'warn', 'Unable to confirm restrictive .htaccess rules.');
    }

    /**
     * Validate email alert configuration.
     *
     * @param array<string, mixed> $config
     * @return array{name: string, status: string, details: string}
     */
    private static function checkAlertConfiguration(array $config): array
    {
        $alerts = $config['alerts'] ?? null;
        if (!is_array($alerts) || empty($alerts['enabled'])) {
            return self::result(
                'Email alerts',
                'warn',
                'Email alerts are disabled. Configure alerts.enabled and recipients in app/config.php.'
            );
        }

        $emailTo = trim((string) ($alerts['email_to'] ?? ''));
        $emailFrom = trim((string) ($alerts['email_from'] ?? ''));

        if ($emailTo === '' || filter_var($emailTo, FILTER_VALIDATE_EMAIL) === false) {
            return self::result('Email alerts', 'error', 'alerts.email_to is missing or invalid.');
        }

        if ($emailFrom === '' || filter_var($emailFrom, FILTER_VALIDATE_EMAIL) === false) {
            return self::result('Email alerts', 'error', 'alerts.email_from is missing or invalid.');
        }

        $cooldown = (int) ($alerts['cooldown_minutes'] ?? 0);
        if ($cooldown < 1) {
            return self::result('Email alerts', 'warn', 'alerts.cooldown_minutes should be at least 1 minute.');
        }

        return self::result('Email alerts', 'ok', 'Alerts enabled for ' . $emailTo . '.');
    }

    /**
     * Attempt to query the SQLite database.
     *
     * @return array{name: string, status: string, details: string}
     */
    private static function checkDatabase(): array
    {
        try {
            $pdo = Storage::getPdo();
            $pdo->query('SELECT 1');
        } catch (PDOException $e) {
            return self::result('SQLite database', 'error', 'Database error: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return self::result('SQLite database', 'error', 'Database unavailable: ' . $e->getMessage());
        }

        return self::result('SQLite database', 'ok', 'Database connection healthy.');
    }

    /**
     * Ensure the admin IP allowlist has at least one entry.
     *
     * @param array<string, mixed> $config
     * @return array{name: string, status: string, details: string}
     */
    private static function checkAdminIpAllowlist(array $config): array
    {
        $ips = $config['admin_allowed_ips'] ?? [];
        if (is_array($ips) && !empty($ips)) {
            return self::result(
                'Admin IP allowlist',
                'ok',
                'Configured rules: ' . implode(', ', $ips) . ' (supports single IPs, CIDR blocks, or ranges).'
            );
        }

        return self::result('Admin IP allowlist', 'warn', 'No IPs configured. Admin UI will be accessible to all authenticated users.');
    }

    /**
     * Check the public job status export is writable.
     *
     * @param array<string, mixed> $config
     * @return array{name: string, status: string, details: string}
     */
    private static function checkJobStatusFile(array $config): array
    {
        $publicDir = (string) ($config['paths']['public'] ?? '');
        if ($publicDir === '') {
            return self::result('Job status file', 'error', 'Public directory not configured.');
        }

        $statusFile = $publicDir . '/status/jobs.json';
        $dir = dirname($statusFile);
        if (!is_dir($dir)) {
            return self::result('Job status file', 'error', 'Status directory missing: ' . $dir);
        }

        if (is_file($statusFile) && !is_writable($statusFile)) {
            return self::result('Job status file', 'error', 'jobs.json is not writable.');
        }

        if (!is_writable($dir)) {
            return self::result('Job status file', 'error', 'Status directory is not writable: ' . $dir);
        }

        return self::result('Job status file', 'ok', 'jobs.json can be written.');
    }

    /**
     * Check that metrics history can be recorded to storage.
     *
     * @param array<string, mixed> $config
     * @return array{name: string, status: string, details: string}
     */
    private static function checkMetricsHistory(array $config): array
    {
        $historyConfig = $config['metrics']['history'] ?? [];
        if (is_array($historyConfig) && empty($historyConfig['enabled'])) {
            return self::result('Metrics history', 'warn', 'Metrics history is disabled. Enable metrics.history.enabled to record trends.');
        }

        $paths = $config['paths'] ?? [];
        if (!is_array($paths) || empty($paths['storage'])) {
            return self::result('Metrics history', 'error', 'Storage path is not configured.');
        }

        $historyFile = rtrim((string) $paths['storage'], '/') . '/index/metrics_history.jsonl';
        $dir = dirname($historyFile);
        if (!is_dir($dir)) {
            return self::result('Metrics history', 'error', 'History directory missing: ' . $dir);
        }

        if (is_file($historyFile) && !is_writable($historyFile)) {
            return self::result('Metrics history', 'error', 'History file is not writable: ' . $historyFile);
        }

        if (!is_writable($dir)) {
            return self::result('Metrics history', 'error', 'History directory is not writable: ' . $dir);
        }

        return self::result('Metrics history', 'ok', 'History entries will be written to ' . $historyFile);
    }

    /**
     * Helper to build a result tuple.
     *
     * @return array{name: string, status: string, details: string}
     */
    private static function result(string $name, string $status, string $details): array
    {
        return [
            'name' => $name,
            'status' => $status,
            'details' => $details,
        ];
    }
}
