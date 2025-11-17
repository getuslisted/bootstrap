<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RSS\FeedRegistry;
use RSS\Lock;
use RSS\JobStatus;
use RSS\Publisher;

$registry = new FeedRegistry();
$publisher = new Publisher($registry, $config);
try {
    $lock = Lock::acquire('publish');
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    return;
}

$args = array_slice($argv ?? [], 1);
$feedIds = [];
$skipCombined = false;
$skipStatus = false;
$requested = false;

foreach ($args as $arg) {
    if ($arg === '--help') {
        echo "Usage: php run_publish.php [--feed=ID] [--url=URL] [--skip-combined] [--skip-status]\n";
        echo "  --feed=ID        Publish only the specified feed (repeatable).\n";
        echo "  --url=URL        Publish only the feed registered for the URL.\n";
        echo "  --skip-combined  Do not regenerate the combined feeds.\n";
        echo "  --skip-status    Skip refreshing status, health, and OPML outputs.\n";
        $lock->release();
        return;
    }

    if (str_starts_with($arg, '--feed=')) {
        $feedIds[] = substr($arg, 7);
        $requested = true;
        continue;
    }

    if (str_starts_with($arg, '--url=')) {
        $url = substr($arg, 6);
        if ($url !== '') {
            $requested = true;
            $feed = $registry->getFeedByUrl($url);
            if ($feed === null) {
                fwrite(STDERR, 'No feed registered for URL ' . $url . "\n");
            } else {
                $feedIds[] = $feed['id'];
            }
        }
        continue;
    }

    if ($arg === '--skip-combined') {
        $skipCombined = true;
        continue;
    }

    if ($arg === '--skip-status') {
        $skipStatus = true;
        continue;
    }
}

$feedIds = array_values(array_unique(array_filter($feedIds, static function ($id): bool {
    return is_string($id) && $id !== '';
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

if ($requested && $feedIds === []) {
    fwrite(STDERR, "No matching feeds found for the provided filters.\n");
    JobStatus::markStart('publish');
    JobStatus::markFinish('publish', 'idle', ['message' => 'No matching feeds']);
    JobStatus::writePublicStatus($config['paths']['public'] . '/status/jobs.json');
    $lock->release();
    return;
}

JobStatus::markStart('publish');

try {
    $result = $publisher->publishAll($feedIds === [] ? null : $feedIds, !$skipCombined, !$skipStatus);
    $context = [
        'feeds_published' => $result['feeds'],
        'items_processed' => $result['total_items'],
        'combined_items' => $result['combined_items'],
    ];
    if (isset($result['tag_trends']) && is_array($result['tag_trends'])) {
        $tagTrends = $result['tag_trends'];
        if (!empty($tagTrends['enabled'])) {
            $context['tags_written'] = (int) ($tagTrends['tags_written'] ?? 0);
            if (isset($tagTrends['total_tags'])) {
                $context['tags_total'] = (int) $tagTrends['total_tags'];
            }
            if (isset($tagTrends['items_considered'])) {
                $context['tags_items_analyzed'] = (int) $tagTrends['items_considered'];
            }
        }
    }
    if (isset($result['latest_items']) && is_array($result['latest_items'])) {
        $latest = $result['latest_items'];
        if (!empty($latest['enabled'])) {
            $context['latest_items_written'] = (int) ($latest['items_written'] ?? 0);
            if (isset($latest['feeds_with_items'])) {
                $context['latest_feeds'] = (int) $latest['feeds_with_items'];
            }
            if (!empty($latest['truncated'])) {
                $context['latest_truncated'] = (int) $latest['truncated'];
            }
        }
    }
    if (!empty($result['private_skipped'])) {
        $context['private_skipped'] = $result['private_skipped'];
        echo 'Skipped ' . $result['private_skipped'] . ' private feed' . ($result['private_skipped'] === 1 ? '' : 's') . "\n";
    }
    JobStatus::markFinish('publish', 'ok', $context);
    echo "Publish complete\n";
} catch (\Throwable $e) {
    JobStatus::markFinish('publish', 'error', ['message' => $e->getMessage()]);
    fwrite(STDERR, 'Publish failed: ' . $e->getMessage() . "\n");
    if (!$skipStatus) {
        try {
            $publisher->refreshHealthStatusFromRegistry();
        } catch (\Throwable $healthError) {
            fwrite(STDERR, 'Failed to update health status: ' . $healthError->getMessage() . "\n");
        }
    }
} finally {
    try {
        JobStatus::writePublicStatus($config['paths']['public'] . '/status/jobs.json');
    } catch (\Throwable $statusError) {
        fwrite(STDERR, 'Failed to write job status file: ' . $statusError->getMessage() . "\n");
    }
    $lock->release();
}
