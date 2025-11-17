<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;
use RSS\LatestItems;

/**
 * Display the latest-item snapshot and optionally regenerate it.
 */
function printUsage(): void
{
    echo <<<TXT
Usage: php app/cli/show_latest.php [--refresh] [--json] [--feed=<id>] [--limit=<n>]

Options:
  --refresh       Regenerate public/status/latest.json before displaying results.
  --json          Output the raw JSON payload instead of a formatted table.
  --feed=<id>     Filter displayed entries to a specific feed identifier.
  --limit=<n>     Limit the number of displayed items (default 10).
  -h, --help      Show this help message.
TXT;
}

$options = array_slice($argv, 1);
$refresh = false;
$outputJson = false;
$filterFeed = null;
$limit = 10;

foreach ($options as $index => $option) {
    if ($option === '--refresh') {
        $refresh = true;
        unset($options[$index]);
        continue;
    }

    if ($option === '--json') {
        $outputJson = true;
        unset($options[$index]);
        continue;
    }

    if ($option === '--help' || $option === '-h') {
        printUsage();
        exit(0);
    }

    if (preg_match('/^--feed=(.+)$/', $option, $matches) === 1) {
        $filterFeed = strtolower(trim($matches[1]));
        unset($options[$index]);
        continue;
    }

    if (preg_match('/^--limit=(\d{1,4})$/', $option, $matches) === 1) {
        $limit = max(1, (int) $matches[1]);
        unset($options[$index]);
        continue;
    }
}

if ($options !== []) {
    fwrite(STDERR, 'Unknown option(s): ' . implode(', ', $options) . PHP_EOL . PHP_EOL);
    printUsage();
    exit(1);
}

$registry = new FeedRegistry();
$summary = null;

try {
    if ($refresh) {
        $feeds = $registry->listFeeds();
        $summary = LatestItems::writePublicLatest($feeds, $config);
        if (!$outputJson) {
            echo 'Snapshot regenerated at ' . gmdate('Y-m-d H:i:s \U\T\C', time()) . PHP_EOL;
        } else {
            fwrite(STDERR, 'Snapshot regenerated.' . PHP_EOL);
        }
    } else {
        $summary = LatestItems::loadSnapshot($config);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Failed to prepare latest snapshot: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

if ($summary === null) {
    fwrite(STDERR, 'No latest snapshot found. Run with --refresh after a publish to generate one.' . PHP_EOL);
    exit(1);
}

$normalized = LatestItems::summarizeSnapshot($summary);

if ($outputJson) {
    try {
        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    } catch (JsonException $jsonError) {
        fwrite(STDERR, 'Failed to encode snapshot as JSON: ' . $jsonError->getMessage() . PHP_EOL);
        exit(1);
    }

    echo $json . PHP_EOL;
    exit(0);
}

$items = [];
if (isset($summary['items']) && is_array($summary['items'])) {
    $items = $summary['items'];
}

if ($filterFeed !== null) {
    $items = array_values(array_filter($items, static function ($item) use ($filterFeed): bool {
        if (!is_array($item)) {
            return false;
        }
        $feedId = isset($item['feed_id']) ? strtolower((string) $item['feed_id']) : '';
        return $feedId === $filterFeed;
    }));
    if ($items === []) {
        echo 'No items matched feed ' . $filterFeed . PHP_EOL;
        exit(0);
    }
}

usort($items, static function ($a, $b): int {
    $left = isset($a['effective_ts']) ? (int) $a['effective_ts'] : (isset($a['published_ts']) ? (int) $a['published_ts'] : 0);
    $right = isset($b['effective_ts']) ? (int) $b['effective_ts'] : (isset($b['published_ts']) ? (int) $b['published_ts'] : 0);
    return $right <=> $left;
});

$totalItems = count($items);
if ($totalItems > $limit) {
    $items = array_slice($items, 0, $limit);
}

$feedsWithItems = isset($normalized['feeds_with_items']) ? (int) $normalized['feeds_with_items'] : 0;
$itemsWritten = isset($normalized['items_written']) ? (int) $normalized['items_written'] : $totalItems;
$lookback = isset($normalized['lookback_days']) ? (int) $normalized['lookback_days'] : 0;
$maxItems = isset($normalized['max_items']) ? (int) $normalized['max_items'] : $itemsWritten;
$maxPerFeed = isset($normalized['max_items_per_feed']) ? (int) $normalized['max_items_per_feed'] : $totalItems;
$includeDead = !empty($normalized['include_dead']);
$includePrivate = !empty($normalized['include_private']);
$truncated = isset($normalized['truncated']) ? (int) $normalized['truncated'] : 0;
$generatedAt = isset($normalized['generated_at']) ? (int) $normalized['generated_at'] : null;
$newest = isset($normalized['newest_item_ts']) ? $normalized['newest_item_ts'] : null;
$oldest = isset($normalized['oldest_item_ts']) ? $normalized['oldest_item_ts'] : null;

if ($generatedAt !== null) {
    echo 'Generated: ' . gmdate('Y-m-d H:i:s \U\T\C', $generatedAt) . PHP_EOL;
}

echo 'Feeds represented: ' . $feedsWithItems . PHP_EOL;
$lookbackLabel = $lookback > 0 ? $lookback . ' day' . ($lookback === 1 ? '' : 's') : 'unlimited';
echo 'Lookback window: ' . $lookbackLabel . PHP_EOL;
echo 'Max items: ' . $maxItems . ' (per feed ' . $maxPerFeed . ')' . PHP_EOL;
echo 'Include dead items: ' . ($includeDead ? 'yes' : 'no') . PHP_EOL;
echo 'Include private feeds: ' . ($includePrivate ? 'yes' : 'no') . PHP_EOL;
echo 'Items captured: ' . $itemsWritten . PHP_EOL;
if ($truncated > 0) {
    echo 'Truncated due to limits: ' . $truncated . PHP_EOL;
}
if ($newest !== null || $oldest !== null) {
    echo 'Newest item: ' . ($newest !== null ? gmdate('Y-m-d H:i:s \U\T\C', (int) $newest) : 'n/a');
    echo ' | Oldest item: ' . ($oldest !== null ? gmdate('Y-m-d H:i:s \U\T\C', (int) $oldest) : 'n/a') . PHP_EOL;
}

echo PHP_EOL;
if ($items === []) {
    echo 'No recent items matched the configured filters.' . PHP_EOL;
    exit(0);
}

echo str_pad('PUBLISHED (UTC)', 22)
    . str_pad('FEED', 18)
    . str_pad('DEAD', 6)
    . 'TITLE' . PHP_EOL;
echo str_repeat('-', 22 + 18 + 6 + 60) . PHP_EOL;

foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $effectiveTs = isset($item['effective_ts'])
        ? (int) $item['effective_ts']
        : (isset($item['published_ts']) ? (int) $item['published_ts'] : 0);
    $published = $effectiveTs > 0 ? gmdate('Y-m-d H:i', $effectiveTs) : 'unknown';
    $feedId = isset($item['feed_id']) ? (string) $item['feed_id'] : '';
    $feedLabel = strlen($feedId) > 16 ? substr($feedId, 0, 16) . '…' : $feedId;
    $isDead = !empty($item['is_dead']) ? 'yes' : 'no';
    $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
    if ($title === '') {
        $title = '[untitled]';
    }
    if (mb_strlen($title) > 60) {
        $title = mb_substr($title, 0, 57) . '…';
    }

    printf(
        "%s%s%s%s" . PHP_EOL,
        str_pad($published, 22),
        str_pad($feedLabel, 18),
        str_pad($isDead, 6),
        $title
    );

    if (isset($item['url'])) {
        echo '    URL: ' . $item['url'] . PHP_EOL;
    }
    if (!empty($item['summary_text']) && is_string($item['summary_text'])) {
        $summaryText = preg_replace('/\s+/', ' ', $item['summary_text']);
        if (is_string($summaryText) && mb_strlen($summaryText) > 100) {
            $summaryText = mb_substr($summaryText, 0, 97) . '…';
        }
        if (is_string($summaryText) && $summaryText !== '') {
            echo '    Summary: ' . $summaryText . PHP_EOL;
        }
    }
    if (!empty($item['tags']) && is_array($item['tags'])) {
        $tags = array_values(array_filter(array_map(static function ($tag): ?string {
            if (!is_string($tag)) {
                return null;
            }
            $trimmed = trim($tag);
            return $trimmed === '' ? null : $trimmed;
        }, $item['tags'])));
        if ($tags !== []) {
            echo '    Tags: ' . implode(', ', array_slice($tags, 0, 8)) . PHP_EOL;
        }
    }
    echo PHP_EOL;
}
