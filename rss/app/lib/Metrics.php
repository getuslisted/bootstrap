<?php

declare(strict_types=1);

namespace RSS;

/**
 * Aggregate feed and job data into machine-readable metrics.
 */
class Metrics
{
    /**
     * Build aggregated metrics for dashboards and status files.
     *
     * @param array<int, array<string, scalar|null>> $summary
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function calculate(array $summary, array $config): array
    {
        $now = time();
        $totalFeeds = count($summary);
        $paused = 0;
        $errors = 0;
        $overdue = 0;
        $neverFetched = 0;
        $totalItems = 0;
        $deadItems = 0;
        $latestItemTs = null;
        $oldestItemTs = null;
        $languageCounts = [];
        $languageLocked = 0;
        $withHttpAuth = 0;
        $withCustomHeaders = 0;
        $withNotes = 0;
        $privateFeeds = 0;
        $inBackoff = 0;
        $maxErrorStreak = 0;

        foreach ($summary as $feed) {
            $status = isset($feed['status']) ? strtolower((string) $feed['status']) : '';
            if ($status !== '' && (str_starts_with($status, 'error') || str_contains($status, 'fail'))) {
                $errors++;
            }

            if (!empty($feed['is_private'])) {
                $privateFeeds++;
            }

            $isPaused = !empty($feed['is_paused']);
            if ($isPaused) {
                $paused++;
            }

            $errorStreak = isset($feed['error_streak']) ? (int) $feed['error_streak'] : 0;
            if ($errorStreak > $maxErrorStreak) {
                $maxErrorStreak = $errorStreak;
            }

            $backoffUntil = isset($feed['backoff_until_ts']) ? (int) $feed['backoff_until_ts'] : 0;
            if (!$isPaused && $backoffUntil > $now) {
                $inBackoff++;
            }

            $lastFetch = $feed['last_fetch_ts'] ?? null;
            $nextFetch = $feed['next_fetch_ts'] ?? null;
            if ($lastFetch === null) {
                $neverFetched++;
                if (!$isPaused) {
                    $overdue++;
                }
            } elseif (!$isPaused && $nextFetch !== null && $now > (int) $nextFetch) {
                $overdue++;
            }

            $dead = (int) ($feed['dead_items'] ?? 0);
            $total = (int) ($feed['total_items'] ?? 0);
            $totalItems += $total;
            $deadItems += $dead;

            if (isset($feed['latest_item_ts'])) {
                $ts = (int) $feed['latest_item_ts'];
                if ($ts > 0) {
                    $latestItemTs = $latestItemTs === null ? $ts : max($latestItemTs, $ts);
                    $oldestItemTs = $oldestItemTs === null ? $ts : min($oldestItemTs, $ts);
                }
            }

            if (isset($feed['language']) && $feed['language'] !== null) {
                $language = FeedRegistry::normalizeLanguage((string) $feed['language']);
                if ($language !== null) {
                    $languageCounts[$language] = ($languageCounts[$language] ?? 0) + 1;
                }
            }

            if (!empty($feed['language_locked'])) {
                $languageLocked++;
            }

            if (!empty($feed['has_http_credentials'])) {
                $withHttpAuth++;
            }

            if (!empty($feed['has_custom_headers']) || (!empty($feed['custom_headers_count']) && (int) $feed['custom_headers_count'] > 0)) {
                $withCustomHeaders++;
            }

            if (!empty($feed['has_notes'])) {
                $withNotes++;
            } elseif (isset($feed['notes'])) {
                $noteValue = $feed['notes'];
                if (is_string($noteValue) && FeedRegistry::normalizeNotes($noteValue) !== null) {
                    $withNotes++;
                } elseif (is_scalar($noteValue)) {
                    $normalizedNote = FeedRegistry::normalizeNotes((string) $noteValue);
                    if ($normalizedNote !== null) {
                        $withNotes++;
                    }
                }
            }
        }

        $activeFeeds = max(0, $totalFeeds - $paused);
        $publicFeeds = max(0, $totalFeeds - $privateFeeds);
        $itemsActive = max(0, $totalItems - $deadItems);
        $averageItems = $totalFeeds > 0 ? round($totalItems / $totalFeeds, 2) : 0.0;

        $jobs = JobStatus::getPublicSummary();
        $alerts = self::summarizeAlerts($config);
        $disk = self::computeDiskMetrics($config);
        $backups = self::summarizeBackups($config);

        arsort($languageCounts);
        $mostCommonLanguage = $languageCounts === [] ? null : array_key_first($languageCounts);

        return [
            'generated_at' => $now,
            'feeds' => [
                'total' => $totalFeeds,
                'active' => $activeFeeds,
                'paused' => $paused,
                'private' => $privateFeeds,
                'public' => $publicFeeds,
                'errors' => $errors,
                'overdue' => $overdue,
                'never_fetched' => $neverFetched,
                'with_http_auth' => $withHttpAuth,
                'with_custom_headers' => $withCustomHeaders,
                'with_notes' => $withNotes,
                'in_backoff' => $inBackoff,
                'max_error_streak' => $maxErrorStreak,
            ],
            'items' => [
                'total' => $totalItems,
                'active' => $itemsActive,
                'dead' => $deadItems,
                'latest_ts' => $latestItemTs,
                'oldest_ts' => $oldestItemTs,
                'average_per_feed' => $averageItems,
            ],
            'jobs' => $jobs,
            'alerts' => $alerts,
            'languages' => [
                'unique' => count($languageCounts),
                'most_common' => $mostCommonLanguage,
                'distribution' => $languageCounts,
                'locked' => $languageLocked,
            ],
            'disk' => $disk,
            'backups' => $backups,
        ];
    }

    /**
     * Persist metrics to the public status directory and return the payload.
     *
     * @param array<int, array<string, scalar|null>> $summary
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function writePublicMetrics(array $summary, array $config): array
    {
        $metrics = self::calculate($summary, $config);
        if (!isset($config['paths']['public'])) {
            throw new \RuntimeException('Missing public path configuration.');
        }

        $path = rtrim((string) $config['paths']['public'], '/') . '/status/metrics.json';
        $json = json_encode($metrics, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        Storage::writeAtomic($path, $json);

        return $metrics;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function summarizeAlerts(array $config): array
    {
        $alertsConfig = $config['alerts'] ?? null;
        $alertsEnabled = false;
        $alertsRecipient = null;

        if (is_array($alertsConfig) && !empty($alertsConfig['enabled'])) {
            $candidateTo = trim((string) ($alertsConfig['email_to'] ?? ''));
            $candidateFrom = trim((string) ($alertsConfig['email_from'] ?? ''));
            if (
                $candidateTo !== ''
                && filter_var($candidateTo, FILTER_VALIDATE_EMAIL) !== false
                && $candidateFrom !== ''
                && filter_var($candidateFrom, FILTER_VALIDATE_EMAIL) !== false
            ) {
                $alertsEnabled = true;
                $alertsRecipient = $candidateTo;
            }
        }

        $activeAlerts = 0;
        $lastAlertTs = null;
        $historyCount = 0;

        if (!isset($config['paths']) || !is_array($config['paths'])) {
            return [
                'enabled' => $alertsEnabled,
                'recipient' => $alertsRecipient,
                'active' => 0,
                'last_sent_ts' => null,
                'history_count' => 0,
            ];
        }

        $state = AlertManager::loadState($config['paths']);
        if (isset($state['history']) && is_array($state['history'])) {
            $historyCount = count($state['history']);
        }

        if (isset($state['feeds']) && is_array($state['feeds'])) {
            foreach ($state['feeds'] as $feedState) {
                if (!is_array($feedState)) {
                    continue;
                }
                if (($feedState['last_type'] ?? '') === 'alert') {
                    $activeAlerts++;
                }
                if (isset($feedState['last_sent_ts'])) {
                    $ts = (int) $feedState['last_sent_ts'];
                    if ($ts > 0) {
                        $lastAlertTs = $lastAlertTs === null ? $ts : max($lastAlertTs, $ts);
                    }
                }
            }
        }

        return [
            'enabled' => $alertsEnabled,
            'recipient' => $alertsRecipient,
            'active' => $alertsEnabled ? $activeAlerts : 0,
            'last_sent_ts' => $alertsEnabled ? $lastAlertTs : null,
            'history_count' => $alertsEnabled ? $historyCount : 0,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private static function computeDiskMetrics(array $config): ?array
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
            return null;
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

        if (!is_dir($path)) {
            return [
                'path' => $path,
                'status' => 'missing',
                'free_bytes' => null,
                'total_bytes' => null,
                'used_bytes' => null,
                'free_percent' => null,
                'used_percent' => null,
                'warn_free_bytes' => $warnThreshold,
                'error_free_bytes' => $errorThreshold,
                'checked_at' => time(),
            ];
        }

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return [
                'path' => $path,
                'status' => 'unknown',
                'free_bytes' => null,
                'total_bytes' => null,
                'used_bytes' => null,
                'free_percent' => null,
                'used_percent' => null,
                'warn_free_bytes' => $warnThreshold,
                'error_free_bytes' => $errorThreshold,
                'checked_at' => time(),
            ];
        }

        $used = max(0, $total - $free);
        $freePercent = $total > 0 ? round(($free / $total) * 100, 2) : null;
        $usedPercent = $total > 0 ? round(($used / $total) * 100, 2) : null;

        $status = 'ok';
        if ($errorThreshold > 0 && $free <= $errorThreshold) {
            $status = 'error';
        } elseif ($warnThreshold > 0 && $free <= $warnThreshold) {
            $status = 'warn';
        }

        return [
            'path' => $path,
            'status' => $status,
            'free_bytes' => (int) $free,
            'total_bytes' => (int) $total,
            'used_bytes' => (int) $used,
            'free_percent' => $freePercent,
            'used_percent' => $usedPercent,
            'warn_free_bytes' => $warnThreshold,
            'error_free_bytes' => $errorThreshold,
            'free_human' => self::formatBytes((int) $free),
            'total_human' => self::formatBytes((int) $total),
            'checked_at' => time(),
        ];
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
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function summarizeBackups(array $config): array
    {
        if (!isset($config['paths']) || !is_array($config['paths'])) {
            return [
                'total' => 0,
                'with_logs' => 0,
                'with_status' => 0,
                'latest' => null,
                'oldest' => null,
            ];
        }

        try {
            $service = new Backup($config['paths']);
            $summary = BackupStatus::summarize($service);
        } catch (\Throwable $error) {
            return [
                'total' => 0,
                'with_logs' => 0,
                'with_status' => 0,
                'latest' => null,
                'oldest' => null,
                'error' => $error->getMessage(),
            ];
        }

        $result = [
            'generated_at' => isset($summary['generated_at']) ? (int) $summary['generated_at'] : time(),
            'total' => (int) ($summary['total'] ?? 0),
            'with_logs' => (int) ($summary['with_logs'] ?? 0),
            'with_status' => (int) ($summary['with_status'] ?? 0),
            'latest' => $summary['latest'] ?? null,
            'oldest' => $summary['oldest'] ?? null,
        ];

        return $result;
    }
}
