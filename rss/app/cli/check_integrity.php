<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\Integrity;

$options = array_slice($argv, 1);
$jsonOutput = false;
$fullScan = false;
$feedFilter = [];

foreach ($options as $option) {
    if ($option === '--json') {
        $jsonOutput = true;
        continue;
    }
    if ($option === '--full') {
        $fullScan = true;
        continue;
    }
    if ($option === '--help') {
        echo "Usage: php app/cli/check_integrity.php [--json] [--full] [--feed=ID]\n";
        echo "  --json     Emit JSON output instead of plain text.\n";
        echo "  --full     Scan entire JSONL history without sampling (may be slow).\n";
        echo "  --feed=ID  Restrict the scan to a specific feed ID (repeatable).\n";
        exit(0);
    }
    if (str_starts_with($option, '--feed=')) {
        $candidate = substr($option, 7);
        if ($candidate !== '') {
            $feedFilter[] = $candidate;
        }
    }
}

$feedFilter = array_values(array_unique($feedFilter));
if ($feedFilter === []) {
    $feedFilter = null;
}

try {
    $stats = Integrity::analyze($config, $fullScan, $feedFilter);
} catch (Throwable $e) {
    fwrite(STDERR, 'Integrity check failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($jsonOutput) {
    try {
        echo json_encode($stats, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
    } catch (\JsonException $e) {
        fwrite(STDERR, 'Failed to encode stats as JSON: ' . $e->getMessage() . PHP_EOL);
        $jsonOutput = false;
    }
}

$issueCounts = [
    'missing_jsonl' => $stats['feeds_missing_jsonl'],
    'unexpected_empty' => $stats['feeds_unexpected_empty'],
    'mismatched_counts' => $stats['feeds_mismatched_counts'],
    'missing_samples' => $stats['feeds_missing_samples'],
    'orphan_samples' => $stats['feeds_orphan_samples'],
    'truncated_counts' => $stats['feeds_truncated_counts'],
    'errors' => count($stats['errors']),
];

$hasErrors = false;
foreach ($issueCounts as $key => $value) {
    if ($value > 0) {
        $hasErrors = true;
        break;
    }
}

if (!$jsonOutput) {
    echo 'Feeds total:        ' . $stats['feeds_total'] . PHP_EOL;
    echo 'Feeds checked:      ' . $stats['feeds_checked'] . PHP_EOL;
    echo 'Items in database:  ' . $stats['items_total'] . PHP_EOL;
    echo 'JSONL lines read:   ' . $stats['jsonl_lines'] . ($stats['max_lines'] !== null ? ' (max ' . $stats['max_lines'] . ')' : '') . PHP_EOL;
    echo 'Sample size:        ' . $stats['sample_size'] . PHP_EOL;
    echo 'Count tolerance:    ' . $stats['count_tolerance'] . PHP_EOL;
    echo 'Full scan:          ' . ($stats['full_scan'] ? 'yes' : 'no') . PHP_EOL;
    echo PHP_EOL;

    if ($stats['missing_jsonl_feeds'] !== []) {
        echo 'Feeds missing JSONL: ' . implode(', ', $stats['missing_jsonl_feeds']) . PHP_EOL;
    }
    if ($stats['mismatched_count_feeds'] !== []) {
        echo "Feeds with count mismatch:\n";
        foreach ($stats['mismatched_count_feeds'] as $feedId => $info) {
            $suffix = $info['truncated'] ? ' (partial scan)' : '';
            echo sprintf('  %s — DB: %d, JSONL: %d%s', $feedId, $info['db'], $info['jsonl'], $suffix) . PHP_EOL;
        }
    }
    if ($stats['missing_sample_feeds'] !== []) {
        echo "Feeds missing sampled JSON entries:\n";
        foreach ($stats['missing_sample_feeds'] as $feedId => $count) {
            echo sprintf('  %s — %d missing JSON payload(s)', $feedId, $count) . PHP_EOL;
        }
    }
    if ($stats['orphan_sample_feeds'] !== []) {
        echo "Feeds with orphan JSON entries:\n";
        foreach ($stats['orphan_sample_feeds'] as $feedId => $count) {
            echo sprintf('  %s — %d JSON record(s) absent from SQLite', $feedId, $count) . PHP_EOL;
        }
    }
    if ($stats['truncated_feeds'] !== []) {
        echo 'Feeds exceeding quick-scan limits: ' . implode(', ', $stats['truncated_feeds']) . PHP_EOL;
    }
    if ($stats['errors'] !== []) {
        echo "Errors:\n";
        foreach ($stats['errors'] as $message) {
            echo '  - ' . $message . PHP_EOL;
        }
    }
}

exit($hasErrors ? 1 : 0);
