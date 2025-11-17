<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;
use RSS\Opml;
use RSS\Storage;

$includeAuth = false;
$includeHeaders = false;
$includePrivate = false;
$target = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') {
        echo "Usage: php app/cli/export_opml.php [target-file] [--include-auth] [--include-headers] [--include-private]\n";
        echo "  --include-auth     Embed HTTP Basic credentials for feeds that have them configured.\n";
        echo "  --include-headers  Embed per-feed custom headers for automation snapshots.\n";
        echo "  --include-private  Export feeds flagged as private (excluded by default).\n";
        exit(0);
    }
    if ($arg === '--include-auth') {
        $includeAuth = true;
        continue;
    }
    if ($arg === '--include-headers') {
        $includeHeaders = true;
        continue;
    }
    if ($arg === '--include-private') {
        $includePrivate = true;
        continue;
    }
    if ($target === null) {
        $target = $arg;
        continue;
    }
}

$target = $target ?? ($config['paths']['public'] . '/status/subscriptions.opml');
$targetDir = dirname($target);
if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
    fwrite(STDERR, 'Unable to create directory: ' . $targetDir . PHP_EOL);
    exit(1);
}

$registry = new FeedRegistry();
$allFeeds = $registry->listFeeds();
$feeds = $allFeeds;
if (!$includePrivate) {
    $feeds = array_values(array_filter($feeds, static function (array $feed): bool {
        return empty($feed['is_private']);
    }));
}

$metadata = [
    'title' => $config['publisher']['site_title'] ?? 'RSS Subscriptions',
    'owner_name' => $config['publisher']['owner_name'] ?? null,
    'owner_email' => $config['publisher']['owner_email'] ?? null,
    'home_url' => $config['publisher']['site_url'] ?? null,
];

$opml = Opml::build($feeds, $metadata, $includeAuth, $includeHeaders);

try {
    Storage::writeAtomic($target, $opml);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed to export OPML: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Exported ' . count($feeds) . ' feeds to ' . $target . PHP_EOL;
if ($includeAuth) {
    echo "Included HTTP Basic credentials in this OPML export. Store the file securely." . PHP_EOL;
}
if ($includeHeaders) {
    echo "Included custom HTTP headers; treat this export as sensitive." . PHP_EOL;
}
if (!$includePrivate) {
    $skipped = count($allFeeds) - count($feeds);
    if ($skipped > 0) {
        echo 'Skipped ' . $skipped . ' private feed' . ($skipped === 1 ? '' : 's') . '.' . PHP_EOL;
    }
}
