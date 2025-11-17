<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php app/cli/remove_feed.php <feed-id>" . PHP_EOL);
    exit(1);
}

$feedId = trim($argv[1]);
if ($feedId === '') {
    fwrite(STDERR, "Feed ID is required." . PHP_EOL);
    exit(1);
}

$registry = new FeedRegistry();
$registry->deleteFeed($feedId);

$dir = $config['paths']['storage'] . '/feeds/' . $feedId;
if (is_dir($dir)) {
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

echo 'Feed removed: ' . $feedId . PHP_EOL;
