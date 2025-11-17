<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;
use RSS\Opml;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php app/cli/import_opml.php <opml-file> [--private|--public]" . PHP_EOL);
    exit(1);
}

$path = $argv[1];
$forcePrivate = false;
$forcePublic = false;

for ($i = 2; $i < $argc; $i++) {
    $option = $argv[$i];
    if ($option === '--private') {
        $forcePrivate = true;
        $forcePublic = false;
        continue;
    }
    if ($option === '--public') {
        $forcePublic = true;
        $forcePrivate = false;
        continue;
    }
    fwrite(STDERR, 'Unknown option: ' . $option . PHP_EOL);
    exit(1);
}

if (!is_file($path)) {
    fwrite(STDERR, 'File not found: ' . $path . PHP_EOL);
    exit(1);
}

$content = (string) file_get_contents($path);

try {
    $entries = Opml::parse($content);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'Failed to parse OPML: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (empty($entries)) {
    fwrite(STDERR, "No feeds found in OPML." . PHP_EOL);
    exit(1);
}

$registry = new FeedRegistry();
$added = 0;
$updated = 0;
$skipped = 0;

foreach ($entries as $entry) {
    try {
        $result = $registry->registerFeed(
            $entry['url'],
            $entry['title'],
            $entry['fetch_interval'] ?? 900
        );
        if (!empty($entry['language'])) {
            $registry->setLanguagePreference($result['id'], $entry['language'], true);
        }
        if (!empty($entry['http_username'])) {
            $registry->setHttpCredentials($result['id'], $entry['http_username'], $entry['http_password'] ?? '');
        }
        if (!empty($entry['http_headers']) && is_array($entry['http_headers'])) {
            $registry->setHttpHeaders($result['id'], $entry['http_headers']);
        }
        $privateDefined = !empty($entry['private_defined']);
        $shouldBePrivate = $forcePrivate ? true : ($forcePublic ? false : (!empty($entry['is_private'])));
        if ($shouldBePrivate) {
            $registry->setPrivacy($result['id'], true);
        } elseif ($forcePublic || $privateDefined) {
            $registry->setPrivacy($result['id'], false);
        }
        if ($result['created']) {
            $added++;
        } else {
            $updated++;
        }
    } catch (Throwable $e) {
        $skipped++;
    }
}

printf(
    "Imported %d feeds (%d new, %d updated).%s" . PHP_EOL,
    $added + $updated,
    $added,
    $updated,
    $skipped > 0 ? ' Skipped ' . $skipped . ' invalid entries.' : ''
);
