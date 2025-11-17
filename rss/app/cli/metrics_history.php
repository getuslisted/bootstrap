<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;
use RSS\MetricsHistory;
use RSS\Publisher;

$limit = 10;
$refresh = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') {
        echo "Usage: php app/cli/metrics_history.php [--limit=10] [--refresh]\n";
        echo "  --limit=N   Display the N most recent history entries (default 10).\n";
        echo "  --refresh   Regenerate metrics snapshots before reading history.\n";
        exit(0);
    }

    if ($arg === '--refresh') {
        $refresh = true;
        continue;
    }

    if (str_starts_with($arg, '--limit=')) {
        $candidate = (int) substr($arg, 8);
        if ($candidate > 0) {
            $limit = $candidate;
        }
        continue;
    }
}

if ($refresh) {
    $registry = new FeedRegistry();
    $publisher = new Publisher($registry, $config);
    try {
        $publisher->refreshHealthStatusFromRegistry();
        echo "Status snapshots refreshed." . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, 'Warning: failed to refresh status snapshots: ' . $e->getMessage() . PHP_EOL);
    }
}

$entries = MetricsHistory::loadRecent($config, $limit);
if (empty($entries)) {
    echo "No metrics history is available yet." . PHP_EOL;
    exit(0);
}

$rows = [];
foreach ($entries as $entry) {
    $feeds = isset($entry['feeds']) && is_array($entry['feeds']) ? $entry['feeds'] : [];
    $items = isset($entry['items']) && is_array($entry['items']) ? $entry['items'] : [];
    $alerts = isset($entry['alerts']) && is_array($entry['alerts']) ? $entry['alerts'] : [];
    $languages = isset($entry['languages']) && is_array($entry['languages']) ? $entry['languages'] : [];

    $recordedTs = isset($entry['recorded_at']) ? (int) $entry['recorded_at'] : (int) ($entry['generated_at'] ?? 0);
    $rows[] = [
        'recorded' => $recordedTs > 0 ? gmdate('Y-m-d H:i', $recordedTs) : '—',
        'feeds' => (string) (int) ($feeds['total'] ?? 0),
        'errors' => (string) (int) ($feeds['errors'] ?? 0),
        'private' => (string) (int) ($feeds['private'] ?? 0),
        'paused' => (string) (int) ($feeds['paused'] ?? 0),
        'with_auth' => (string) (int) ($feeds['with_http_auth'] ?? 0),
        'with_headers' => (string) (int) ($feeds['with_custom_headers'] ?? 0),
        'with_notes' => (string) (int) ($feeds['with_notes'] ?? 0),
        'active_items' => (string) (int) ($items['active'] ?? 0),
        'dead_items' => (string) (int) ($items['dead'] ?? 0),
        'alerts_active' => (string) (!empty($alerts['enabled']) ? (int) ($alerts['active'] ?? 0) : 0),
        'top_language' => (string) ($languages['most_common'] ?? '—'),
    ];
}

$headers = [
    'recorded' => 'Recorded (UTC)',
    'feeds' => 'Total Feeds',
    'errors' => 'Errors',
    'private' => 'Private',
    'paused' => 'Paused',
    'with_auth' => 'HTTP Auth',
    'with_headers' => 'Custom Headers',
    'with_notes' => 'Notes',
    'active_items' => 'Active Items',
    'dead_items' => 'Dead Items',
    'alerts_active' => 'Alerts Active',
    'top_language' => 'Top Language',
];

$widths = [];
foreach ($headers as $key => $label) {
    $widths[$key] = strlen($label);
}

foreach ($rows as $row) {
    foreach ($row as $key => $value) {
        $widths[$key] = max($widths[$key], strlen($value));
    }
}

$lineParts = [];
foreach ($headers as $key => $label) {
    $lineParts[] = str_pad($label, $widths[$key]);
}

echo implode('  ', $lineParts) . PHP_EOL;
echo str_repeat('-', array_sum($widths) + (count($widths) - 1) * 2) . PHP_EOL;

foreach ($rows as $row) {
    $lineParts = [];
    foreach ($headers as $key => $_) {
        $lineParts[] = str_pad($row[$key], $widths[$key]);
    }
    echo implode('  ', $lineParts) . PHP_EOL;
}
