<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;

if ($argc < 2 || in_array('--help', $argv, true)) {
    fwrite(STDERR, "Usage: php app/cli/set_http_headers.php <feed-id> [--header=\"Name: Value\"] [--headers-file=FILE] [--clear]\n");
    fwrite(STDERR, "Options:\n");
    fwrite(STDERR, "  --header=\"Name: Value\"  Append a custom header (repeat for multiple).\n");
    fwrite(STDERR, "  --headers-file=FILE    Load additional headers from FILE (one per line).\n");
    fwrite(STDERR, "  --clear                Remove all stored headers for the feed.\n");
    exit($argc < 2 ? 1 : 0);
}

$feedId = trim($argv[1]);
if ($feedId === '') {
    fwrite(STDERR, "Feed ID is required." . PHP_EOL);
    exit(1);
}

$clear = false;
$headerLines = [];
$headersFile = null;

for ($i = 2; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--clear') {
        $clear = true;
        continue;
    }
    if (str_starts_with($arg, '--header=')) {
        $headerLines[] = substr($arg, 9);
        continue;
    }
    if (str_starts_with($arg, '--headers-file=')) {
        $headersFile = substr($arg, 15);
        continue;
    }
    $headerLines[] = $arg;
}

if ($headersFile !== null && $headersFile !== '') {
    if (!is_file($headersFile)) {
        fwrite(STDERR, 'Headers file not found: ' . $headersFile . PHP_EOL);
        exit(1);
    }
    $fileLines = file($headersFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($fileLines !== false) {
        foreach ($fileLines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $headerLines[] = $line;
            }
        }
    }
}

$headerLines = array_values(array_filter(array_map('trim', $headerLines), static function ($line): bool {
    return $line !== '';
}));

$registry = new FeedRegistry();

try {
    if ($clear) {
        $registry->setHttpHeaders($feedId, []);
        echo 'Custom headers cleared for feed ' . $feedId . PHP_EOL;
        exit(0);
    }

    if ($headerLines === []) {
        fwrite(STDERR, "Provide at least one --header option or use --clear." . PHP_EOL);
        exit(1);
    }

    $headers = FeedRegistry::parseHeaderLines(implode("\n", $headerLines));
    $registry->setHttpHeaders($feedId, $headers);
    echo 'Custom headers updated for feed ' . $feedId . ' (' . count($headers) . ' header' . (count($headers) === 1 ? '' : 's') . ')' . PHP_EOL;
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed to update custom headers: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
