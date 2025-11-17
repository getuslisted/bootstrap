<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;

$args = array_slice($argv, 1);

if ($args === [] || in_array('--help', $args, true)) {
    echo "Usage: php app/cli/set_language.php <feed-id|--url=URL> [language] [--lock|--unlock]" . PHP_EOL;
    echo "Examples:" . PHP_EOL;
    echo "  php app/cli/set_language.php 123abc en-US" . PHP_EOL;
    echo "  php app/cli/set_language.php --url=https://example.com/feed.xml --unlock" . PHP_EOL;
    exit($args === [] ? 1 : 0);
}

$target = array_shift($args);
$feedId = null;
$registry = new FeedRegistry();

if (str_starts_with($target, '--url=')) {
    $url = substr($target, 6);
    if ($url === '') {
        fwrite(STDERR, "URL cannot be empty." . PHP_EOL);
        exit(1);
    }
    $feed = $registry->getFeedByUrl($url);
    if ($feed === null) {
        fwrite(STDERR, 'No feed found for URL: ' . $url . PHP_EOL);
        exit(1);
    }
    $feedId = (string) $feed['id'];
} else {
    $feedId = $target;
}

if ($feedId === null || $feedId === '') {
    fwrite(STDERR, "Feed ID is required." . PHP_EOL);
    exit(1);
}

$language = null;
$locked = null;

foreach ($args as $arg) {
    if ($arg === '--unlock' || $arg === '--auto') {
        $locked = false;
        continue;
    }
    if ($arg === '--lock') {
        $locked = true;
        continue;
    }
    $language = trim($arg);
}

if ($language !== null && $language === '') {
    $language = null;
}

if ($locked === null) {
    $locked = $language !== null;
}

if ($language === null && $locked) {
    fwrite(STDERR, "Language value is required when locking." . PHP_EOL);
    exit(1);
}

try {
    $registry->setLanguagePreference($feedId, $language, $locked);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed to update language: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($locked) {
    $normalized = FeedRegistry::normalizeLanguage($language);
    echo 'Language locked to ' . ($normalized ?? $language) . ' for feed ' . $feedId . PHP_EOL;
} else {
    echo 'Language auto-detection enabled for feed ' . $feedId . PHP_EOL;
}
