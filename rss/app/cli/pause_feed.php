<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;
use RSS\Publisher;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php app/cli/pause_feed.php <feed-id>" . PHP_EOL);
    exit(1);
}

$feedId = trim($argv[1]);
if ($feedId === '') {
    fwrite(STDERR, "Feed ID is required." . PHP_EOL);
    exit(1);
}

$registry = new FeedRegistry();
$feed = $registry->getFeed($feedId);
if ($feed === null) {
    fwrite(STDERR, "Feed not found: " . $feedId . PHP_EOL);
    exit(1);
}

if (!empty($feed['is_paused'])) {
    echo "Feed is already paused." . PHP_EOL;
    exit(0);
}

$registry->pauseFeed($feedId);
echo "Feed paused successfully." . PHP_EOL;

$publisher = new Publisher($registry, $config);
try {
    $publisher->refreshHealthStatusFromRegistry();
} catch (Throwable $e) {
    fwrite(STDERR, 'Warning: failed to refresh status files: ' . $e->getMessage() . PHP_EOL);
}
