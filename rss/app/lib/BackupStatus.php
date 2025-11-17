<?php

declare(strict_types=1);

namespace RSS;

use JsonException;
use RuntimeException;

/**
 * Publish sanitized backup summaries for public status dashboards.
 */
final class BackupStatus
{
    /**
     * Build a normalized summary for the available backups.
     *
     * @param array<int, array<string, mixed>>|null $backups
     *
     * @return array<string, mixed>
     */
    public static function summarize(Backup $service, ?array $backups = null): array
    {
        $records = $backups ?? $service->listBackups();
        $now = time();
        $entries = [];
        $withLogs = 0;
        $withStatus = 0;

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $manifest = isset($record['manifest']) && is_array($record['manifest'])
                ? $record['manifest']
                : [];
            $options = isset($manifest['options']) && is_array($manifest['options'])
                ? $manifest['options']
                : [];
            $stats = isset($manifest['stats']) && is_array($manifest['stats'])
                ? $manifest['stats']
                : [];

            $created = null;
            if (isset($record['created_at'])) {
                $candidate = (int) $record['created_at'];
                if ($candidate > 0) {
                    $created = $candidate;
                }
            } elseif (isset($manifest['created_at'])) {
                $candidate = (int) $manifest['created_at'];
                if ($candidate > 0) {
                    $created = $candidate;
                }
            }

            $includeLogs = !empty($options['include_logs']);
            $includeStatus = !empty($options['include_public_status']);

            $entry = [
                'name' => isset($record['name']) ? (string) $record['name'] : '',
                'label' => isset($manifest['label']) && $manifest['label'] !== '' ? (string) $manifest['label'] : null,
                'created_at' => $created,
                'age_seconds' => $created !== null ? max(0, $now - $created) : null,
                'size_bytes' => isset($stats['bytes']) ? (int) $stats['bytes'] : null,
                'files' => isset($stats['files']) ? (int) $stats['files'] : null,
                'directories' => isset($stats['directories']) ? (int) $stats['directories'] : null,
                'include_logs' => $includeLogs,
                'include_public_status' => $includeStatus,
            ];

            if ($includeLogs) {
                $withLogs++;
            }
            if ($includeStatus) {
                $withStatus++;
            }

            $entries[] = $entry;
        }

        $latest = $entries[0] ?? null;
        $oldest = $entries !== [] ? $entries[array_key_last($entries)] : null;
        $lastRestore = self::readLastRestore();

        return [
            'generated_at' => $now,
            'total' => count($entries),
            'with_logs' => $withLogs,
            'with_status' => $withStatus,
            'latest' => $latest,
            'oldest' => $oldest,
            'last_restore' => $lastRestore,
            'backups' => $entries,
        ];
    }

    /**
     * Persist the backup summary to the public status directory.
     *
     * @param array<string, string> $paths
     *
     * @return array<string, mixed>
     */
    public static function writeSummary(Backup $service, array $paths): array
    {
        if (!isset($paths['public'])) {
            throw new RuntimeException('Missing public path configuration for backup summary.');
        }

        $summary = self::summarize($service);
        $target = rtrim($paths['public'], '/') . '/status/backups.json';
        $json = json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        Storage::writeAtomic($target, $json);

        return $summary;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readLastRestore(): ?array
    {
        try {
            $config = Storage::getConfig();
        } catch (RuntimeException $e) {
            return null;
        }

        if (!isset($config['paths']['storage'])) {
            return null;
        }

        $path = rtrim((string) $config['paths']['storage'], '/') . '/index/backup_restore.json';
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $copied = [];
        if (isset($decoded['copied']) && is_array($decoded['copied'])) {
            foreach ($decoded['copied'] as $item) {
                if (is_string($item) && $item !== '') {
                    $copied[] = $item;
                }
            }
        }

        $warnings = [];
        if (isset($decoded['warnings']) && is_array($decoded['warnings'])) {
            foreach ($decoded['warnings'] as $warning) {
                if (is_string($warning) && $warning !== '') {
                    $warnings[] = $warning;
                }
            }
        }

        return [
            'name' => isset($decoded['name']) ? (string) $decoded['name'] : null,
            'mode' => isset($decoded['mode']) ? (string) $decoded['mode'] : null,
            'restored_at' => isset($decoded['restored_at']) ? (int) $decoded['restored_at'] : null,
            'target' => isset($decoded['target']) && $decoded['target'] !== null ? (string) $decoded['target'] : null,
            'copied' => $copied,
            'warnings' => $warnings,
        ];
    }
}
