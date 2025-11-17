<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\Backup;
use RSS\BackupStatus;
use RSS\FeedRegistry;
use RSS\Lock;
use RSS\Metrics;

$args = array_slice($argv ?? [], 1);
$includeLogs = false;
$includeStatus = false;
$label = null;
$list = false;
$pruneKeep = null;
$noCreate = false;

foreach ($args as $arg) {
    if ($arg === '--help') {
        echo "Usage: php app/cli/backup.php [--label=NAME] [--include-logs] [--include-status] [--prune=N] [--list] [--no-create]\n";
        echo "\n";
        echo "Options:\n";
        echo "  --label=NAME       Optional human-friendly label to append to the backup directory.\n";
        echo "  --include-logs     Copy files from the logs directory into the backup snapshot.\n";
        echo "  --include-status   Copy generated files from public/status into the backup.\n";
        echo "  --prune=N          Keep only the newest N backups before creating a new snapshot.\n";
        echo "  --list             List existing backups after performing requested actions.\n";
        echo "  --no-create        Skip creating a new snapshot (useful with --prune or --list).\n";
        return;
    }

    if ($arg === '--include-logs') {
        $includeLogs = true;
        continue;
    }

    if ($arg === '--include-status') {
        $includeStatus = true;
        continue;
    }

    if ($arg === '--list') {
        $list = true;
        continue;
    }

    if ($arg === '--no-create') {
        $noCreate = true;
        continue;
    }

    if (str_starts_with($arg, '--label=')) {
        $label = substr($arg, 8);
        continue;
    }

    if (str_starts_with($arg, '--prune=')) {
        $value = substr($arg, 8);
        $pruneKeep = max(0, (int) $value);
        continue;
    }

    fwrite(STDERR, 'Unknown argument: ' . $arg . "\n");
    return 1;
}

$performCreate = !$noCreate;
$needsLock = $performCreate || $pruneKeep !== null;
$lock = null;
$backupService = new Backup($config['paths']);

if ($needsLock) {
    try {
        $lock = Lock::acquire('backup');
    } catch (\RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }
}

try {
    if ($pruneKeep !== null) {
        $result = $backupService->prune($pruneKeep);
        echo 'Pruned ' . (int) $result['removed'] . ' backup(s); kept ' . (int) $result['kept'] . "\n";
    }

    if ($performCreate) {
        $metadata = [];
        try {
            $registry = new FeedRegistry();
            $summary = $registry->getFeedStatusSummary();
            $totalFeeds = count($summary);
            $paused = 0;
            $private = 0;
            $totalItems = 0;
            $deadItems = 0;
            foreach ($summary as $feed) {
                if (!empty($feed['is_paused'])) {
                    $paused++;
                }
                if (!empty($feed['is_private'])) {
                    $private++;
                }
                $totalItems += (int) ($feed['total_items'] ?? 0);
                $deadItems += (int) ($feed['dead_items'] ?? 0);
            }

            $metadata['feeds'] = [
                'count' => $totalFeeds,
                'paused' => $paused,
                'private' => $private,
            ];
            $metadata['items'] = [
                'total' => $totalItems,
                'dead' => $deadItems,
            ];
            $metadata['metrics'] = Metrics::calculate($summary, $config);
        } catch (\Throwable $summaryError) {
            $metadata['note'] = 'Unable to capture feed metrics: ' . $summaryError->getMessage();
        }

        $result = $backupService->create([
            'label' => $label,
            'include_logs' => $includeLogs,
            'include_public_status' => $includeStatus,
        ], $metadata);

        $manifest = $result['manifest'];
        $stats = isset($manifest['stats']) && is_array($manifest['stats']) ? $manifest['stats'] : ['files' => 0, 'directories' => 0, 'bytes' => 0];
        echo 'Backup created: ' . $result['path'] . "\n";
        echo '  Files: ' . (int) ($stats['files'] ?? 0) . ', Directories: ' . (int) ($stats['directories'] ?? 0) . ', Size: ' . formatBytes((int) ($stats['bytes'] ?? 0)) . "\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Backup operation failed: ' . $e->getMessage() . "\n");
    return 1;
} finally {
    if ($lock instanceof Lock) {
        $lock->release();
    }
}

$summaryError = null;
try {
    BackupStatus::writeSummary($backupService, $config['paths']);
} catch (Throwable $summaryException) {
    $summaryError = $summaryException;
}

if ($list) {
    $backups = $backupService->listBackups();
    if (empty($backups)) {
        echo "No backups found.\n";
    } else {
        echo "\nExisting backups:\n";
        foreach ($backups as $backup) {
            $manifest = isset($backup['manifest']) && is_array($backup['manifest']) ? $backup['manifest'] : [];
            $stats = isset($manifest['stats']) && is_array($manifest['stats']) ? $manifest['stats'] : [];
            $createdAt = isset($backup['created_at']) && $backup['created_at'] !== null
                ? gmdate('Y-m-d H:i:s', (int) $backup['created_at']) . ' UTC'
                : 'Unknown';
            $labelInfo = isset($manifest['label']) && $manifest['label'] !== null && $manifest['label'] !== ''
                ? ' (label: ' . $manifest['label'] . ')'
                : '';
            echo ' - ' . $backup['name'] . $labelInfo . ' — ' . $createdAt;
            $size = isset($stats['bytes']) ? formatBytes((int) $stats['bytes']) : null;
            if ($size !== null) {
                echo ' — ' . $size;
            }
            echo "\n";
        }
    }
}

if ($summaryError instanceof Throwable) {
    fwrite(STDERR, 'Warning: unable to refresh backup summary: ' . $summaryError->getMessage() . "\n");
}

return 0;

/**
 * Format bytes into a human-friendly label.
 */
function formatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = (float) $bytes;
    foreach ($units as $unit) {
        $value /= 1024;
        if ($value < 1024) {
            return sprintf('%.2f %s', $value, $unit);
        }
    }

    return sprintf('%.2f %s', $value, 'PB');
}
