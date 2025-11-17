<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;
use RSS\Publisher;

$options = array_slice($argv, 1);
$refresh = false;
foreach ($options as $index => $option) {
    if ($option === '--refresh-status') {
        $refresh = true;
        unset($options[$index]);
    }
}

$registry = new FeedRegistry();
if ($refresh) {
    $publisher = new Publisher($registry, $config);
    try {
        $publisher->refreshHealthStatusFromRegistry();
    } catch (Throwable $e) {
        fwrite(STDERR, 'Warning: failed to refresh status files: ' . $e->getMessage() . PHP_EOL);
    }
}

$feeds = $registry->getFeedStatusSummary();
if (empty($feeds)) {
    echo "No feeds registered." . PHP_EOL;
    exit(0);
}

echo str_pad('ID (sha256)', 18)
    . str_pad('Status', 12)
    . str_pad('Err/Suc', 9)
    . str_pad('Paused', 8)
    . str_pad('Private', 9)
    . str_pad('Language', 12)
    . str_pad('HTTP Auth', 14)
    . str_pad('Headers', 16)
    . str_pad('Interval', 12)
    . str_pad('Live/Dead', 16)
    . str_pad('Last Fetch (UTC)', 22)
    . str_pad('Backoff (UTC)', 22)
    . "Next Fetch (UTC)" . PHP_EOL;
echo str_repeat('-', 175) . PHP_EOL;

foreach ($feeds as $feed) {
    $idFull = (string) $feed['id'];
    $id = strlen($idFull) > 16 ? substr($idFull, 0, 16) . '…' : $idFull;
    $status = (string) ($feed['status'] ?? 'unknown');
    $interval = (int) ($feed['fetch_interval_sec'] ?? 0);
    $paused = !empty($feed['is_paused']) ? 'yes' : 'no';
    $private = !empty($feed['is_private']) ? 'yes' : 'no';
    $live = max(0, (int) ($feed['total_items'] ?? 0) - (int) ($feed['dead_items'] ?? 0));
    $dead = (int) ($feed['dead_items'] ?? 0);
    $lastFetch = isset($feed['last_fetch_ts']) ? gmdate('Y-m-d H:i', (int) $feed['last_fetch_ts']) : 'never';
    $nextFetch = !empty($feed['is_paused'])
        ? 'paused'
        : (isset($feed['next_fetch_ts']) ? gmdate('Y-m-d H:i', (int) $feed['next_fetch_ts']) : 'queued');
    $backoffLabel = '—';
    if (isset($feed['backoff_until_ts']) && $feed['backoff_until_ts'] !== null) {
        $backoffTs = (int) $feed['backoff_until_ts'];
        if ($backoffTs > 0) {
            $prefix = (!empty($feed['is_paused']) || $backoffTs <= time()) ? 'E ' : 'A ';
            $backoffLabel = $prefix . gmdate('Y-m-d H:i', $backoffTs);
        }
    }
    $errorStreak = (int) ($feed['error_streak'] ?? 0);
    $successStreak = (int) ($feed['success_streak'] ?? 0);
    $language = isset($feed['language']) && $feed['language'] !== null
        ? (string) $feed['language']
        : (!empty($feed['language_locked']) ? 'locked' : 'auto');
    $authLabel = !empty($feed['has_http_credentials']) ? 'yes' : 'no';
    if (!empty($feed['has_http_credentials']) && isset($feed['http_auth']) && is_array($feed['http_auth'])) {
        $username = trim((string) ($feed['http_auth']['username'] ?? ''));
        if ($username !== '') {
            $authLabel = 'yes ' . (strlen($username) > 9 ? substr($username, 0, 9) . '…' : $username);
        }
        if (!empty($feed['http_auth']['has_password'])) {
            $authLabel .= ' +pw';
        }
    }

    $headersArray = [];
    if (isset($feed['http_headers']) && is_array($feed['http_headers'])) {
        $headersArray = $feed['http_headers'];
    }
    $headersCount = is_array($headersArray) ? count($headersArray) : 0;
    if ($headersCount === 0) {
        $headersLabel = 'none';
    } else {
        $names = array_keys($headersArray);
        $display = array_slice($names, 0, 2);
        $display = array_map(static function ($name): string {
            $name = (string) $name;
            return strlen($name) > 12 ? substr($name, 0, 12) . '…' : $name;
        }, $display);
        $headersLabel = $headersCount . ' ' . implode(',', $display);
        if ($headersCount > count($display)) {
            $headersLabel .= '+';
        }
    }

    printf(
        "%s%s%s%s%s%s%s%s%s%s%s%s%s" . PHP_EOL,
        str_pad($id, 18),
        str_pad($status, 12),
        str_pad($errorStreak . '/' . $successStreak, 9),
        str_pad($paused, 8),
        str_pad($private, 9),
        str_pad($language, 12),
        str_pad($authLabel, 14),
        str_pad($headersLabel, 16),
        str_pad((string) $interval, 12),
        str_pad($live . '/' . $dead, 16),
        str_pad($lastFetch, 22),
        str_pad($backoffLabel, 22),
        $nextFetch
    );

    $notesPreview = '';
    if (isset($feed['notes']) && is_string($feed['notes'])) {
        $notesPreview = trim($feed['notes']);
    }
    if ($notesPreview !== '') {
        $singleLine = preg_replace('/\s+/', ' ', $notesPreview) ?? $notesPreview;
        if (mb_strlen($singleLine) > 120) {
            $singleLine = mb_substr($singleLine, 0, 117) . '…';
        }
        echo '    Notes: ' . $singleLine . PHP_EOL;
    }
}
