<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RSS\FeedRegistry;
use RSS\Http;
use RSS\JobStatus;
use RSS\Lock;
use RSS\Publisher;

$registry = new FeedRegistry();
$http = new Http($config['http']);
$publisher = new Publisher($registry, $config);

try {
    $lock = Lock::acquire('verify');
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    return;
}

$args = array_slice($argv ?? [], 1);
$feedIds = [];
$itemUids = [];
$limit = 100;
$includeDead = false;
$force = false;
$requested = false;

foreach ($args as $arg) {
    if ($arg === '--help') {
        echo "Usage: php run_verify.php [--feed=ID] [--uid=UID] [--limit=N] [--include-dead] [--force]\n";
        echo "  --feed=ID       Verify items for a specific feed (repeatable).\n";
        echo "  --uid=UID       Verify the item with the provided UID (repeatable).\n";
        echo "  --limit=N       Limit verification batch size (default 100).\n";
        echo "  --include-dead  Include items already marked as dead.\n";
        echo "  --force         Ignore feed pause state when selecting items.\n";
        $lock->release();
        return;
    }

    if (str_starts_with($arg, '--feed=')) {
        $feedIds[] = substr($arg, 7);
        $requested = true;
        continue;
    }

    if (str_starts_with($arg, '--uid=')) {
        $itemUids[] = substr($arg, 6);
        $requested = true;
        continue;
    }

    if (str_starts_with($arg, '--limit=')) {
        $value = (int) substr($arg, 8);
        if ($value > 0) {
            $limit = $value;
        }
        continue;
    }

    if ($arg === '--include-dead') {
        $includeDead = true;
        continue;
    }

    if ($arg === '--force') {
        $force = true;
        continue;
    }
}

$feedIds = array_values(array_unique(array_filter($feedIds, static function ($id): bool {
    return is_string($id) && $id !== '';
})));

$itemUids = array_values(array_unique(array_filter($itemUids, static function ($uid): bool {
    return is_string($uid) && $uid !== '';
})));

if ($feedIds !== []) {
    $existing = $registry->listFeeds($feedIds);
    $existingIds = [];
    foreach ($existing as $feed) {
        $existingIds[] = $feed['id'];
    }
    $missing = array_diff($feedIds, $existingIds);
    foreach ($missing as $missingId) {
        fwrite(STDERR, 'No feed found for ID ' . $missingId . "\n");
    }
    $feedIds = $existingIds;
}

if ($requested && $feedIds === [] && $itemUids === []) {
    fwrite(STDERR, "No matching feeds or items found for the provided filters.\n");
    JobStatus::markStart('verify');
    JobStatus::markFinish('verify', 'idle', ['message' => 'No matching items']);
    JobStatus::writePublicStatus($config['paths']['public'] . '/status/jobs.json');
    $lock->release();
    return;
}

$items = [];

JobStatus::markStart('verify');

try {
    if ($itemUids !== []) {
        $items = $registry->getItemsByUid($itemUids);
        if (empty($items)) {
            JobStatus::markFinish('verify', 'idle', ['message' => 'Selected items not found']);
            echo "No matching items for verification\n";
            return;
        }
    } else {
        $items = $registry->getItemsDueForVerification($limit, $feedIds === [] ? null : $feedIds, $includeDead, $force);
    }
    if (empty($items)) {
        JobStatus::markFinish('verify', 'idle', ['message' => 'No items to verify']);
        echo "No items due for verification\n";
        return;
    }

    echo 'Verifying ' . count($items) . " items\n";

    $okCount = 0;
    $failCount = 0;

    foreach ($items as $item) {
        $url = $item['url'];
        $uid = $item['uid'];
        try {
            $response = $http->head($url);
            $status = $response->getStatusCode();
            if ($status === 405 || $status === 501) {
                $response = $http->get($url, ['Range' => 'bytes=0-128']);
                $status = $response->getStatusCode();
            }
            if ($status >= 200 && $status < 400) {
                $registry->markItemVerification($uid, true);
                echo "OK: {$url}\n";
                $okCount++;
            } else {
                $registry->markItemVerification($uid, false);
                echo "Fail({$status}): {$url}\n";
                $failCount++;
            }
        } catch (\Throwable $e) {
            $registry->markItemVerification($uid, false);
            fwrite(STDERR, 'Verify failed for ' . $url . ': ' . $e->getMessage() . "\n");
            $failCount++;
        }
    }

    JobStatus::markFinish('verify', 'ok', [
        'checked_items' => count($items),
        'succeeded' => $okCount,
        'failed' => $failCount,
    ]);
} catch (\Throwable $e) {
    JobStatus::markFinish('verify', 'error', ['message' => $e->getMessage()]);
    fwrite(STDERR, 'Verifier failed: ' . $e->getMessage() . "\n");
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
    $lock->release();
}
