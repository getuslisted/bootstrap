<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\Backup;
use RSS\BackupStatus;
use RSS\Lock;

$args = array_slice($argv ?? [], 1);
$backupName = null;
$target = null;
$overwrite = false;
$withConfig = false;
$withLogs = false;
$withStatus = false;
$skipStorage = false;
$inPlace = false;
$force = false;
$jsonOutput = false;

$positionals = [];

foreach ($args as $arg) {
    if ($arg === '--help') {
        echo "Usage: php app/cli/restore_backup.php [BACKUP_NAME] [options]\n";
        echo "\n";
        echo "Options:\n";
        echo "  --name=NAME        Explicit backup directory name to restore.\n";
        echo "  --target=PATH      Extract backup contents to PATH (defaults to storage/tmp/restore-*).\n";
        echo "  --overwrite        Allow replacing existing files/directories at the target.\n";
        echo "  --with-config      Restore configuration files (config.php, config.local.php).\n";
        echo "  --with-logs        Restore log directory contents if present.\n";
        echo "  --with-status      Restore public/status files if present.\n";
        echo "  --skip-storage     Skip restoring the storage directory.\n";
        echo "  --in-place         Restore directly into live paths (storage, app, etc.).\n";
        echo "  --force            Required acknowledgement for --in-place operations.\n";
        echo "  --json             Emit result metadata as JSON.\n";
        echo "  --help             Show this message.\n";
        return;
    }

    if ($arg === '--overwrite') {
        $overwrite = true;
        continue;
    }

    if ($arg === '--with-config') {
        $withConfig = true;
        continue;
    }

    if ($arg === '--with-logs') {
        $withLogs = true;
        continue;
    }

    if ($arg === '--with-status') {
        $withStatus = true;
        continue;
    }

    if ($arg === '--skip-storage') {
        $skipStorage = true;
        continue;
    }

    if ($arg === '--in-place') {
        $inPlace = true;
        continue;
    }

    if ($arg === '--force') {
        $force = true;
        continue;
    }

    if ($arg === '--json') {
        $jsonOutput = true;
        continue;
    }

    if (str_starts_with($arg, '--name=')) {
        $backupName = substr($arg, 7);
        continue;
    }

    if (str_starts_with($arg, '--target=')) {
        $target = substr($arg, 9);
        continue;
    }

    if (str_starts_with($arg, '--')) {
        fwrite(STDERR, 'Unknown argument: ' . $arg . "\n");
        return 1;
    }

    $positionals[] = $arg;
}

if ($backupName === null && $positionals !== []) {
    $backupName = $positionals[0];
}

if ($backupName === null || trim($backupName) === '') {
    fwrite(STDERR, "A backup name is required. Pass it positionally or via --name.\n");
    return 1;
}

$backupService = new Backup($config['paths']);

$lock = null;
if ($inPlace) {
    try {
        $lock = Lock::acquire('backup');
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }
}

try {
    if ($inPlace && !$force) {
        throw new RuntimeException('In-place restore requested without --force confirmation.');
    }

    $options = [
        'overwrite' => $overwrite,
    ];

    if ($inPlace) {
        $options['in_place'] = true;
        $options['force'] = true;
        $options['restore_storage'] = !$skipStorage;
        $options['restore_config'] = $withConfig;
        $options['restore_logs'] = $withLogs;
        $options['restore_public_status'] = $withStatus;
    } else {
        if ($target !== null) {
            $options['target_base'] = $target;
        }
        $options['copy_storage'] = !$skipStorage;
        $options['copy_config'] = $withConfig;
        $options['copy_logs'] = $withLogs;
        $options['copy_public_status'] = $withStatus;
    }

    $result = $backupService->restore($backupName, $options);

    $summaryWarning = null;
    try {
        BackupStatus::writeSummary($backupService, $config['paths']);
    } catch (Throwable $summaryError) {
        $summaryWarning = $summaryError->getMessage();
        $result['warnings'] = $result['warnings'] ?? [];
        $result['warnings'][] = 'Unable to refresh backup summary: ' . $summaryWarning;
    }

    if ($jsonOutput) {
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
    } else {
        $targetPath = isset($result['target']) && is_string($result['target']) ? $result['target'] : null;

        if ($result['mode'] === 'in_place') {
            $label = $targetPath !== null ? $targetPath : $config['paths']['base'];
            echo 'Restored backup ' . $backupName . ' into live paths (' . $label . ').' . "\n";
        } else {
            $label = $targetPath !== null ? $targetPath : 'unknown target';
            echo 'Extracted backup ' . $backupName . ' to ' . $label . "\n";
        }

        if (!empty($result['copied'])) {
            echo 'Copied segments: ' . implode(', ', $result['copied']) . "\n";
        }

        if (!empty($result['warnings'])) {
            foreach ($result['warnings'] as $warning) {
                echo 'Warning: ' . $warning . "\n";
            }
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Restore failed: ' . $e->getMessage() . "\n");
    return 1;
} finally {
    if ($lock instanceof Lock) {
        $lock->release();
    }
}

return 0;
