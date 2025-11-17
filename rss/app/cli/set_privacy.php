<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;
use RSS\Publisher;

if ($argc < 3 || in_array($argv[1], ['--help', '-h'], true)) {
    fwrite(STDERR, "Usage: php app/cli/set_privacy.php <feed-id> <private|public>" . PHP_EOL);
    exit($argc < 3 ? 1 : 0);
}

$feedId = trim($argv[1]);
$visibility = strtolower(trim($argv[2]));

if ($feedId === '') {
    fwrite(STDERR, "Feed ID is required." . PHP_EOL);
    exit(1);
}

if (!in_array($visibility, ['private', 'public'], true)) {
    fwrite(STDERR, "Visibility must be 'private' or 'public'." . PHP_EOL);
    exit(1);
}

$registry = new FeedRegistry();
$feed = $registry->getFeed($feedId);
if ($feed === null) {
    fwrite(STDERR, 'Feed not found: ' . $feedId . PHP_EOL);
    exit(1);
}

$publisher = new Publisher($registry, $config);

try {
    if ($visibility === 'private') {
        $registry->setPrivacy($feedId, true);
        try {
            $publisher->removeFeedOutputs($feedId);
        } catch (Throwable $cleanup) {
            fwrite(STDERR, 'Warning: unable to purge published files: ' . $cleanup->getMessage() . PHP_EOL);
        }
        echo 'Feed marked as private and excluded from public publishing.' . PHP_EOL;
    } else {
        $registry->setPrivacy($feedId, false);
        echo 'Feed marked as public.' . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to update feed visibility: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

try {
    $publisher->refreshHealthStatusFromRegistry();
} catch (Throwable $statusError) {
    fwrite(STDERR, 'Warning: failed to refresh status files: ' . $statusError->getMessage() . PHP_EOL);
}
