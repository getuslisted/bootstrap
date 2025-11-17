<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;
use RSS\Publisher;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php app/cli/reschedule_feed.php <feed-id> [new-interval]" . PHP_EOL);
    exit(1);
}

$feedId = trim($argv[1]);
if ($feedId === '') {
    fwrite(STDERR, "Feed ID is required." . PHP_EOL);
    exit(1);
}

$newInterval = null;
if (isset($argv[2])) {
    $parsed = (int) $argv[2];
    if ($parsed > 0) {
        $newInterval = $parsed;
    }
}

$registry = new FeedRegistry();
$feed = $registry->getFeed($feedId);
if ($feed === null) {
    fwrite(STDERR, "Feed not found: " . $feedId . PHP_EOL);
    exit(1);
}

if (!empty($feed['is_paused'])) {
    fwrite(STDERR, "Warning: feed is currently paused. Resume it to allow fetching." . PHP_EOL);
}

if ($newInterval !== null) {
    $registry->updateFetchInterval($feedId, $newInterval);
    echo 'Updated fetch interval to ' . max(300, $newInterval) . ' seconds.' . PHP_EOL;
}

$registry->resetLastFetch($feedId, 'queued');
echo 'Feed has been queued for immediate fetching.' . PHP_EOL;

$publisher = new Publisher($registry, $config);
try {
    $publisher->refreshHealthStatusFromRegistry();
} catch (Throwable $e) {
    fwrite(STDERR, 'Warning: failed to refresh status files: ' . $e->getMessage() . PHP_EOL);
}
