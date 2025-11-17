<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RSS\Backup;
use RSS\BackupStatus;
use RSS\FeedRegistry;
use RSS\JobStatus;
use RSS\Lock;
use RSS\Publisher;
use RSS\Storage;

$pdo = Storage::getPdo();
$registry = new FeedRegistry();
$publisher = new Publisher($registry, $config);
$backupService = null;
$backupInitError = null;

$options = $argv ?? [];
$rebuild = in_array('--rebuild-index', $options, true);
$skipPrune = in_array('--skip-prune', $options, true);
$prunedItems = 0;
$prunedFeeds = 0;
$pruneDays = (int) ($config['maintenance']['prune_dead_after_days'] ?? 0);
$pruneBatch = max(50, (int) ($config['maintenance']['prune_batch_size'] ?? 500));
$backupsKeep = isset($config['maintenance']['backups_keep']) ? (int) $config['maintenance']['backups_keep'] : 0;
$backupsPruned = null;

try {
    $lock = Lock::acquire('maintenance');
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    return;
}

JobStatus::markStart('maintenance');

try {
    try {
        $backupService = new Backup($config['paths']);
    } catch (\Throwable $initError) {
        $backupInitError = $initError;
        fwrite(STDERR, 'Warning: backup service unavailable: ' . $initError->getMessage() . "\n");
    }

    if ($rebuild) {
        echo "Rebuilding SQLite index from JSONL...\n";
        $pdo->exec('DELETE FROM items');
        $pdo->exec('DELETE FROM feeds');

        $feedsDir = $config['paths']['storage'] . '/feeds';
        $directories = array_filter(glob($feedsDir . '/*'), 'is_dir');

        foreach ($directories as $dir) {
            $feedId = basename((string) $dir);
            $feedMetaPath = $dir . '/feed.json';
            $feedUrl = '';
            $feedTitle = null;
            $httpUsername = null;
            $httpPassword = null;
            if (is_file($feedMetaPath)) {
                $data = json_decode((string) file_get_contents($feedMetaPath), true);
                if (is_array($data)) {
                    $feedUrl = $data['url'] ?? '';
                    $feedTitle = $data['title'] ?? null;
                    if (isset($data['http_auth']) && is_array($data['http_auth'])) {
                        $candidateUser = trim((string) ($data['http_auth']['username'] ?? ''));
                        if ($candidateUser !== '') {
                            $httpUsername = $candidateUser;
                            if (array_key_exists('http_auth_password_b64', $data)) {
                                $encoded = $data['http_auth_password_b64'];
                                if ($encoded === '' || $encoded === null) {
                                    $httpPassword = $encoded === '' ? '' : null;
                                } elseif (is_string($encoded)) {
                                    $decoded = base64_decode($encoded, true);
                                    if ($decoded !== false) {
                                        $httpPassword = $decoded;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            if ($feedUrl === '') {
                continue;
            }
            $registry->upsertFeed([
                'id' => $feedId,
                'url' => $feedUrl,
                'title' => $feedTitle,
                'status' => 'recovered',
                'last_fetch_ts' => time(),
                'last_seen_at' => time(),
                'http_username' => $httpUsername,
                'http_password' => $httpPassword,
            ]);

            foreach (Storage::iterateJsonLines($feedId) as $item) {
                try {
                    $registry->upsertItem([
                        'uid' => $item['uid'],
                        'feed_id' => $feedId,
                        'url' => $item['url'],
                        'title' => $item['title'] ?? null,
                        'published_ts' => $item['published_ts'] ?? null,
                        'is_dead' => !empty($item['is_dead']) ? 1 : 0,
                        'last_verified_ts' => $item['last_verified_ts'] ?? null,
                        'fail_count' => $item['fail_count'] ?? 0,
                    ]);
                } catch (PDOException $dbException) {
                    if (!FeedRegistry::isUniqueUrlViolation($dbException)) {
                        throw $dbException;
                    }
                    // Skip duplicate URLs encountered during rebuild; they already exist in the index.
                }
            }
        }
    }

    $shouldPrune = !$skipPrune && $pruneDays > 0;
    if ($shouldPrune) {
        $threshold = time() - ($pruneDays * 86400);
        echo 'Pruning dead items older than ' . $pruneDays . " days...\n";
        $feedsTouched = [];

        while (true) {
            $batch = $registry->getDeadItemsOlderThan($threshold, $pruneBatch);
            if (empty($batch)) {
                break;
            }

            $uids = [];
            $grouped = [];
            foreach ($batch as $row) {
                $uid = (string) ($row['uid'] ?? '');
                $feedId = (string) ($row['feed_id'] ?? '');
                if ($uid === '' || $feedId === '') {
                    continue;
                }
                $uids[] = $uid;
                $grouped[$feedId][] = $uid;
            }

            if (empty($uids)) {
                break;
            }

            $deleted = $registry->deleteItemsByUid($uids);
            if ($deleted === 0) {
                break;
            }

            $prunedItems += $deleted;
            foreach ($grouped as $feedId => $feedUids) {
                $feedsTouched[$feedId] = true;
                try {
                    Storage::removeItemsFromJsonl($feedId, $feedUids);
                } catch (\Throwable $storageError) {
                    fwrite(STDERR, 'Failed to prune JSONL for ' . $feedId . ': ' . $storageError->getMessage() . "\n");
                }
            }

            if (count($batch) < $pruneBatch) {
                break;
            }
        }

        $prunedFeeds = count($feedsTouched);
        if ($prunedItems > 0) {
            echo 'Pruned ' . $prunedItems . ' dead items across ' . $prunedFeeds . " feeds\n";
        } else {
            echo "No dead items required pruning\n";
        }
    } elseif ($skipPrune) {
        echo "Skipping dead item pruning (--skip-prune)\n";
    }

    echo "Running VACUUM...\n";
    $pdo->exec('VACUUM');

    rotateLogs($config['paths']['logs'], $config['maintenance']['log_max_bytes'], $config['maintenance']['keep_log_backups']);

    if ($backupsKeep > 0) {
        if ($backupService !== null) {
            try {
                $result = $backupService->prune($backupsKeep);
                $backupsPruned = (int) ($result['removed'] ?? 0);
                echo 'Backups pruned: removed ' . (int) ($result['removed'] ?? 0) . ', kept ' . (int) ($result['kept'] ?? 0) . "\n";
            } catch (\Throwable $backupError) {
                fwrite(STDERR, 'Failed to prune backups: ' . $backupError->getMessage() . "\n");
            }
        } elseif ($backupInitError !== null) {
            fwrite(STDERR, 'Skipping backup pruning due to initialization failure.' . "\n");
        }
    }

    echo "Maintenance complete\n";
    JobStatus::markFinish('maintenance', 'ok', [
        'rebuild_index' => $rebuild ? 'yes' : 'no',
        'pruned_items' => $prunedItems,
        'feeds_pruned' => $prunedFeeds,
        'backups_pruned' => $backupsPruned,
    ]);
} catch (\Throwable $e) {
    JobStatus::markFinish('maintenance', 'error', ['message' => $e->getMessage()]);
    fwrite(STDERR, 'Maintenance failed: ' . $e->getMessage() . "\n");
} finally {
    try {
        $publisher->refreshHealthStatusFromRegistry();
    } catch (\Throwable $statusError) {
        fwrite(STDERR, 'Failed to refresh feed status: ' . $statusError->getMessage() . "\n");
    }
    try {
        JobStatus::writePublicStatus($config['paths']['public'] . '/status/jobs.json');
    } catch (\Throwable $statusError) {
        fwrite(STDERR, 'Failed to write job status file: ' . $statusError->getMessage() . "\n");
    }
    if ($backupService !== null) {
        try {
            BackupStatus::writeSummary($backupService, $config['paths']);
        } catch (\Throwable $summaryError) {
            fwrite(STDERR, 'Warning: unable to update backup summary: ' . $summaryError->getMessage() . "\n");
        }
    }
    $lock->release();
}

/**
 * @param string $dir
 */
function rotateLogs(string $dir, int $maxBytes, int $backups): void
{
    $files = glob(rtrim($dir, '/') . '/*.log');
    if ($files === false) {
        return;
    }

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        if (filesize($file) <= $maxBytes) {
            continue;
        }
        for ($i = $backups; $i >= 1; $i--) {
            $rotate = $file . '.' . $i;
            $next = $file . '.' . ($i + 1);
            if ($i === $backups && file_exists($rotate)) {
                @unlink($rotate);
            }
            if (file_exists($rotate)) {
                rename($rotate, $next);
            }
        }
        $first = $file . '.1';
        rename($file, $first);
        touch($file);
    }
}
