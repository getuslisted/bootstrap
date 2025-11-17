<?php

declare(strict_types=1);

namespace RSS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Inspect JSONL and SQLite contents for mismatches between stored data.
 */
class Integrity
{
    /**
     * Execute the integrity analysis.
     *
     * @param array<string, mixed> $config
     * @param bool $fullScan When true the entire JSONL history is scanned.
     * @param array<int, string>|null $feedFilter Optional feed ID filter.
     *
     * @return array{
     *     feeds_total: int,
     *     feeds_checked: int,
     *     feeds_missing_jsonl: int,
     *     feeds_unexpected_empty: int,
     *     feeds_mismatched_counts: int,
     *     feeds_missing_samples: int,
     *     feeds_orphan_samples: int,
     *     feeds_truncated_counts: int,
     *     items_total: int,
     *     jsonl_lines: int,
     *     sample_size: int,
     *     count_tolerance: int,
     *     max_lines: ?int,
     *     full_scan: bool,
     *     missing_jsonl_feeds: array<string>,
     *     mismatched_count_feeds: array<string, array{db: int, jsonl: int, truncated: bool}>,
     *     missing_sample_feeds: array<string, int>,
     *     orphan_sample_feeds: array<string, int>,
     *     truncated_feeds: array<string>,
     *     errors: array<string>
     * }
     */
    public static function analyze(array $config, bool $fullScan = false, ?array $feedFilter = null): array
    {
        $storageConfig = Storage::getConfig();
        $paths = $storageConfig['paths'] ?? [];
        $storagePath = is_array($paths) ? (string) ($paths['storage'] ?? '') : '';
        if ($storagePath === '') {
            throw new RuntimeException('Storage path is not configured.');
        }

        $options = [];
        if (isset($config['integrity']) && is_array($config['integrity'])) {
            $options = $config['integrity'];
        }

        $sampleSize = max(0, (int) ($options['sample_size'] ?? 25));
        $countTolerance = max(0, (int) ($options['count_tolerance'] ?? 5));
        $maxLines = $fullScan ? null : self::resolvePositiveInt($options['max_lines'] ?? 50000);

        $filterSet = null;
        if ($feedFilter !== null) {
            $filterSet = [];
            foreach ($feedFilter as $candidate) {
                if (!is_string($candidate) || $candidate === '') {
                    continue;
                }
                $filterSet[$candidate] = true;
            }
        }

        $pdo = Storage::getPdo();
        $feedStmt = $pdo->query('SELECT id, title FROM feeds ORDER BY id');
        if ($feedStmt === false) {
            throw new RuntimeException('Unable to enumerate feeds from the database.');
        }

        $stats = [
            'feeds_total' => 0,
            'feeds_checked' => 0,
            'feeds_missing_jsonl' => 0,
            'feeds_unexpected_empty' => 0,
            'feeds_mismatched_counts' => 0,
            'feeds_missing_samples' => 0,
            'feeds_orphan_samples' => 0,
            'feeds_truncated_counts' => 0,
            'items_total' => 0,
            'jsonl_lines' => 0,
            'sample_size' => $sampleSize,
            'count_tolerance' => $countTolerance,
            'max_lines' => $maxLines,
            'full_scan' => $fullScan,
            'missing_jsonl_feeds' => [],
            'mismatched_count_feeds' => [],
            'missing_sample_feeds' => [],
            'orphan_sample_feeds' => [],
            'truncated_feeds' => [],
            'errors' => [],
        ];

        while (($feed = $feedStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $feedId = isset($feed['id']) ? (string) $feed['id'] : '';
            if ($feedId === '') {
                continue;
            }
            $stats['feeds_total']++;

            if ($filterSet !== null && !isset($filterSet[$feedId])) {
                continue;
            }

            $stats['feeds_checked']++;

            $feedDir = $storagePath . '/feeds/' . $feedId;
            $jsonlPath = $feedDir . '/items.jsonl';

            $itemCount = self::countItems($pdo, $feedId);
            $stats['items_total'] += $itemCount;

            if (!is_file($jsonlPath)) {
                if ($itemCount > 0) {
                    $stats['feeds_missing_jsonl']++;
                    $stats['missing_jsonl_feeds'][] = $feedId;
                }
                continue;
            }

            if (!is_readable($jsonlPath)) {
                $stats['errors'][] = 'JSONL file not readable for feed ' . $feedId;
                continue;
            }

            $lineInfo = self::countJsonLines($jsonlPath, $maxLines);
            $lineCount = $lineInfo['count'];
            $stats['jsonl_lines'] += $lineCount;
            if ($lineInfo['truncated']) {
                $stats['feeds_truncated_counts']++;
                $stats['truncated_feeds'][] = $feedId;
            }

            if ($itemCount > 0 && $lineCount === 0) {
                $stats['feeds_unexpected_empty']++;
            }

            if (!$lineInfo['truncated']) {
                $difference = abs($lineCount - $itemCount);
                if ($difference > $countTolerance) {
                    $stats['feeds_mismatched_counts']++;
                    $stats['mismatched_count_feeds'][$feedId] = [
                        'db' => $itemCount,
                        'jsonl' => $lineCount,
                        'truncated' => false,
                    ];
                }
            } else {
                // Provide partial insight when truncated counts are all we have.
                if ($itemCount === 0 && $lineCount > 0) {
                    $stats['feeds_mismatched_counts']++;
                    $stats['mismatched_count_feeds'][$feedId] = [
                        'db' => $itemCount,
                        'jsonl' => $lineCount,
                        'truncated' => true,
                    ];
                }
            }

            $effectiveSampleSize = $sampleSize;
            if ($fullScan) {
                $effectiveSampleSize = 0;
            }

            if ($effectiveSampleSize > 0 && $itemCount > 0) {
                $dbSample = self::fetchSampleUids($pdo, $feedId, $effectiveSampleSize);
                if ($dbSample !== []) {
                    $jsonSample = Storage::loadItemsByUid($feedId, $dbSample);
                    $missing = array_diff($dbSample, array_keys($jsonSample));
                    $missingCount = count($missing);
                    if ($missingCount > 0) {
                        $stats['feeds_missing_samples']++;
                        $stats['missing_sample_feeds'][$feedId] = $missingCount;
                    }
                }
            }

            if ($effectiveSampleSize > 0) {
                $recentItems = Storage::loadRecentItems($feedId, $effectiveSampleSize);
                if ($recentItems !== []) {
                    $recentUids = [];
                    foreach ($recentItems as $item) {
                        $uid = $item['uid'] ?? null;
                        if (is_string($uid) && $uid !== '') {
                            $recentUids[] = $uid;
                        }
                    }

                    if ($recentUids !== []) {
                        $existing = self::fetchExistingUids($pdo, $recentUids);
                        $existingMap = array_fill_keys($existing, true);
                        $orphans = [];
                        foreach ($recentUids as $uid) {
                            if (!isset($existingMap[$uid])) {
                                $orphans[] = $uid;
                            }
                        }

                        if ($orphans !== []) {
                            $stats['feeds_orphan_samples']++;
                            $stats['orphan_sample_feeds'][$feedId] = count($orphans);
                        }
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * Produce a diagnostics-friendly result tuple.
     *
     * @param array<string, mixed> $config
     * @return array{name: string, status: string, details: string}
     */
    public static function diagnostic(array $config): array
    {
        try {
            $stats = self::analyze($config);
        } catch (Throwable $e) {
            return [
                'name' => 'Storage integrity',
                'status' => 'error',
                'details' => 'Integrity check failed: ' . $e->getMessage(),
            ];
        }

        $issues = [];
        $status = 'ok';

        if ($stats['feeds_missing_jsonl'] > 0) {
            $status = 'error';
            $issues[] = $stats['feeds_missing_jsonl'] . ' feed(s) missing JSONL files';
        }

        if ($stats['feeds_unexpected_empty'] > 0) {
            $status = $status === 'error' ? 'error' : 'warn';
            $issues[] = $stats['feeds_unexpected_empty'] . ' feed(s) empty on disk with database rows present';
        }

        if ($stats['feeds_mismatched_counts'] > 0) {
            $status = $status === 'error' ? 'error' : 'warn';
            $issues[] = $stats['feeds_mismatched_counts'] . ' feed(s) with mismatched record counts';
        }

        if ($stats['feeds_missing_samples'] > 0) {
            $status = $status === 'error' ? 'error' : 'warn';
            $issues[] = $stats['feeds_missing_samples'] . ' feed(s) missing sampled JSON payloads';
        }

        if ($stats['feeds_orphan_samples'] > 0) {
            $status = $status === 'error' ? 'error' : 'warn';
            $issues[] = $stats['feeds_orphan_samples'] . ' feed(s) with JSON records missing from SQLite';
        }

        if ($stats['feeds_truncated_counts'] > 0) {
            $status = $status === 'error' ? 'error' : 'warn';
            $issues[] = $stats['feeds_truncated_counts'] . ' feed(s) exceeded quick-scan line limits';
        }

        if ($issues === []) {
            $details = sprintf(
                'Checked %d feed(s) — counts aligned within tolerance (%d).',
                $stats['feeds_checked'],
                $stats['count_tolerance']
            );
        } else {
            $details = sprintf(
                'Checked %d feed(s): %s.',
                $stats['feeds_checked'],
                implode('; ', $issues)
            );
        }

        if ($stats['feeds_checked'] === 0) {
            $status = 'warn';
            $details = 'No feeds available to check. Add feeds or ensure the database is accessible.';
        }

        return [
            'name' => 'Storage integrity',
            'status' => $status,
            'details' => $details,
        ];
    }

    /**
     * Count JSONL lines up to the provided limit.
     *
     * @return array{count: int, truncated: bool}
     */
    private static function countJsonLines(string $path, ?int $maxLines): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['count' => 0, 'truncated' => false];
        }

        $count = 0;
        $truncated = false;
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $count++;
                if ($maxLines !== null && $maxLines > 0 && $count >= $maxLines) {
                    if (!feof($handle)) {
                        $truncated = true;
                    }
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return ['count' => $count, 'truncated' => $truncated];
    }

    /**
     * Fetch the number of items persisted for a feed.
     */
    private static function countItems(PDO $pdo, string $feedId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) as total FROM items WHERE feed_id = :feed_id');
        if ($stmt === false) {
            throw new RuntimeException('Unable to prepare feed count query.');
        }
        $stmt->execute(['feed_id' => $feedId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return 0;
        }

        $value = $row['total'] ?? 0;
        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Retrieve a sample of UIDs from SQLite for the provided feed.
     *
     * @return array<int, string>
     */
    private static function fetchSampleUids(PDO $pdo, string $feedId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $query = sprintf(
            'SELECT uid FROM items WHERE feed_id = :feed_id ORDER BY COALESCE(last_verified_ts, published_ts, 0) DESC LIMIT %d',
            $limit
        );
        $stmt = $pdo->prepare($query);
        if ($stmt === false) {
            throw new RuntimeException('Unable to prepare sample query.');
        }
        $stmt->execute(['feed_id' => $feedId]);

        $uids = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $uid = $row['uid'] ?? null;
            if (is_string($uid) && $uid !== '') {
                $uids[] = $uid;
            }
        }

        return $uids;
    }

    /**
     * Fetch UIDs that exist in SQLite for the provided identifiers.
     *
     * @param array<int, string> $uids
     * @return array<int, string>
     */
    private static function fetchExistingUids(PDO $pdo, array $uids): array
    {
        $filtered = [];
        foreach ($uids as $uid) {
            if (!is_string($uid) || $uid === '') {
                continue;
            }
            $filtered[$uid] = true;
        }

        if ($filtered === []) {
            return [];
        }

        $values = array_keys($filtered);
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $stmt = $pdo->prepare('SELECT uid FROM items WHERE uid IN (' . $placeholders . ')');
        if ($stmt === false) {
            throw new RuntimeException('Unable to prepare existence query.');
        }
        $stmt->execute($values);

        $found = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $uid = $row['uid'] ?? null;
            if (is_string($uid) && $uid !== '') {
                $found[] = $uid;
            }
        }

        return $found;
    }

    /**
     * Normalize positive integer options, falling back to null when not valid.
     */
    private static function resolvePositiveInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $candidate = (int) $value;
            return $candidate > 0 ? $candidate : null;
        }

        return null;
    }
}
