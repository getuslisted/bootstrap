<?php

declare(strict_types=1);

namespace RSS;

use JsonException;
use RuntimeException;

/**
 * Persist rolling metrics history snapshots for trend analysis.
 */
class MetricsHistory
{
    /**
     * Record the latest metrics snapshot and prune old entries.
     *
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $config
     */
    public static function record(array $metrics, array $config): void
    {
        $historyConfig = self::resolveHistoryConfig($config);
        if (!$historyConfig['enabled']) {
            return;
        }

        $paths = $config['paths'] ?? null;
        if (!is_array($paths) || empty($paths['storage'])) {
            throw new RuntimeException('Storage paths are not configured.');
        }

        $path = rtrim((string) $paths['storage'], '/') . '/index/metrics_history.jsonl';
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create metrics history directory: ' . $dir);
        }

        $lock = Lock::acquire('metrics-history');
        try {
            $entry = self::sanitizeMetrics($metrics);
            $entry['recorded_at'] = $entry['generated_at'];

            $encoded = json_encode($entry, JSON_THROW_ON_ERROR);
            $handle = fopen($path, 'ab');
            if ($handle === false) {
                throw new RuntimeException('Unable to open metrics history file for append: ' . $path);
            }

            try {
                if (!flock($handle, LOCK_EX)) {
                    throw new RuntimeException('Unable to lock metrics history file: ' . $path);
                }

                if (fwrite($handle, $encoded . "\n") === false) {
                    throw new RuntimeException('Failed to write metrics history entry.');
                }

                fflush($handle);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }

            self::pruneHistory($path, $historyConfig['retain_seconds'], $historyConfig['max_entries']);
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to encode metrics history entry: ' . $e->getMessage(), 0, $e);
        } finally {
            $lock->release();
        }
    }

    /**
     * Load the newest metrics history entries.
     *
     * @param array<string, mixed> $config
     * @return array<int, array<string, mixed>>
     */
    public static function loadRecent(array $config, int $limit = 10): array
    {
        if ($limit <= 0) {
            return [];
        }

        $paths = $config['paths'] ?? null;
        if (!is_array($paths) || empty($paths['storage'])) {
            return [];
        }

        $path = rtrim((string) $paths['storage'], '/') . '/index/metrics_history.jsonl';
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            return [];
        }

        $lines = array_slice($lines, -$limit);
        $entries = [];
        foreach ($lines as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                continue;
            }

            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return array_reverse($entries);
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private static function sanitizeMetrics(array $metrics): array
    {
        $generatedAt = isset($metrics['generated_at']) ? (int) $metrics['generated_at'] : time();
        $feeds = is_array($metrics['feeds'] ?? null) ? $metrics['feeds'] : [];
        $items = is_array($metrics['items'] ?? null) ? $metrics['items'] : [];
        $alerts = is_array($metrics['alerts'] ?? null) ? $metrics['alerts'] : [];
        $jobs = is_array($metrics['jobs'] ?? null) ? $metrics['jobs'] : [];

        $jobSummary = [];
        foreach ($jobs as $name => $info) {
            if (!is_string($name) || !is_array($info)) {
                continue;
            }

            $jobSummary[$name] = [
                'status' => $info['status'] ?? null,
                'last_finished_at' => $info['last_finished_at'] ?? null,
                'duration_sec' => $info['duration_sec'] ?? null,
            ];
        }

        return [
            'generated_at' => $generatedAt,
            'feeds' => [
                'total' => (int) ($feeds['total'] ?? 0),
                'active' => (int) ($feeds['active'] ?? 0),
                'paused' => (int) ($feeds['paused'] ?? 0),
                'errors' => (int) ($feeds['errors'] ?? 0),
                'overdue' => (int) ($feeds['overdue'] ?? 0),
                'never_fetched' => (int) ($feeds['never_fetched'] ?? 0),
            ],
            'items' => [
                'total' => (int) ($items['total'] ?? 0),
                'active' => (int) ($items['active'] ?? 0),
                'dead' => (int) ($items['dead'] ?? 0),
                'latest_ts' => $items['latest_ts'] ?? null,
                'oldest_ts' => $items['oldest_ts'] ?? null,
                'average_per_feed' => isset($items['average_per_feed']) ? (float) $items['average_per_feed'] : 0.0,
            ],
            'alerts' => [
                'enabled' => !empty($alerts['enabled']),
                'active' => !empty($alerts['enabled']) ? (int) ($alerts['active'] ?? 0) : 0,
                'last_sent_ts' => !empty($alerts['enabled']) ? ($alerts['last_sent_ts'] ?? null) : null,
            ],
            'jobs' => $jobSummary,
        ];
    }

    private static function pruneHistory(string $path, int $retainSeconds, int $maxEntries): void
    {
        $contents = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($contents === false || $contents === []) {
            return;
        }

        $threshold = $retainSeconds > 0 ? (time() - $retainSeconds) : null;
        $entries = [];
        foreach ($contents as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                continue;
            }

            if (!is_array($decoded)) {
                continue;
            }

            $recordedAt = isset($decoded['recorded_at']) ? (int) $decoded['recorded_at'] : ($decoded['generated_at'] ?? 0);
            if ($threshold !== null && $recordedAt !== null && $recordedAt > 0 && $recordedAt < $threshold) {
                continue;
            }

            $entries[] = $decoded;
        }

        if ($maxEntries > 0 && count($entries) > $maxEntries) {
            $entries = array_slice($entries, -$maxEntries);
        }

        $newLines = [];
        foreach ($entries as $entry) {
            try {
                $newLines[] = json_encode($entry, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                continue;
            }
        }

        Storage::writeAtomic($path, implode("\n", $newLines) . (empty($newLines) ? '' : "\n"));
    }

    /**
     * @param array<string, mixed> $config
     * @return array{enabled: bool, retain_seconds: int, max_entries: int}
     */
    private static function resolveHistoryConfig(array $config): array
    {
        $defaults = [
            'enabled' => true,
            'retain_days' => 30,
            'max_entries' => 5000,
        ];

        $history = $defaults;
        if (isset($config['metrics']) && is_array($config['metrics'])) {
            $metricsConfig = $config['metrics']['history'] ?? [];
            if (is_array($metricsConfig)) {
                $history = array_merge($history, $metricsConfig);
            }
        }

        $retainDays = (int) ($history['retain_days'] ?? 30);
        $maxEntries = (int) ($history['max_entries'] ?? 5000);

        return [
            'enabled' => !empty($history['enabled']),
            'retain_seconds' => max(0, $retainDays) * 86400,
            'max_entries' => $maxEntries > 0 ? $maxEntries : 0,
        ];
    }
}
