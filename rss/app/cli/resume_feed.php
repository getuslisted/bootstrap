<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;
use RSS\Publisher;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php app/cli/resume_feed.php <feed-id> [--no-queue]" . PHP_EOL);
    exit(1);
}

$feedId = trim($argv[1]);
if ($feedId === '') {
    fwrite(STDERR, "Feed ID is required." . PHP_EOL);
    exit(1);
}

$queue = true;
for ($i = 2; $i < $argc; $i++) {
    if ($argv[$i] === '--no-queue') {
        $queue = false;
    }
}

$registry = new FeedRegistry();
$feed = $registry->getFeed($feedId);
if ($feed === null) {
    fwrite(STDERR, "Feed not found: " . $feedId . PHP_EOL);
    exit(1);
}

$wasPaused = !empty($feed['is_paused']);
$registry->resumeFeed($feedId, $queue);
if ($wasPaused) {
    echo $queue
        ? "Feed resumed and queued for fetching." . PHP_EOL
        : "Feed resumed without queuing an immediate fetch." . PHP_EOL;
} else {
    echo $queue
        ? "Feed was already active; queued an immediate fetch." . PHP_EOL
        : "Feed was already active; settings left unchanged." . PHP_EOL;
}

$publisher = new Publisher($registry, $config);
try {
    $publisher->refreshHealthStatusFromRegistry();
} catch (Throwable $e) {
    fwrite(STDERR, 'Warning: failed to refresh status files: ' . $e->getMessage() . PHP_EOL);
}
