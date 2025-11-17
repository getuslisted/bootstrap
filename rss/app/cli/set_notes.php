<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;

if ($argc < 2 || in_array('--help', $argv, true)) {
    fwrite(STDERR, "Usage: php app/cli/set_notes.php <feed-id> [--clear] [--file=PATH|-] [notes...]\n");
    fwrite(STDERR, "  --clear        Remove any existing notes for the feed.\n");
    fwrite(STDERR, "  --file=PATH    Read notes content from PATH (use - for STDIN).\n");
    fwrite(STDERR, "  notes...       Optional inline note text when no file or --clear is provided.\n");
    exit($argc < 2 ? 1 : 0);
}

$feedId = trim($argv[1]);
if ($feedId === '') {
    fwrite(STDERR, "Feed ID is required." . PHP_EOL);
    exit(1);
}

$clear = false;
$file = null;
$parts = [];

for ($i = 2; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--clear') {
        $clear = true;
        continue;
    }
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
        continue;
    }
    $parts[] = $arg;
}

if ($clear && $file !== null) {
    fwrite(STDERR, "Specify either --clear or --file, not both." . PHP_EOL);
    exit(1);
}

if ($clear && $parts !== []) {
    fwrite(STDERR, "Inline notes are ignored when --clear is provided." . PHP_EOL);
    $parts = [];
}

$notes = null;
if ($clear) {
    $notes = null;
} elseif ($file !== null) {
    if ($file === '-') {
        $contents = stream_get_contents(STDIN);
        if ($contents === false) {
            fwrite(STDERR, "Failed to read notes from STDIN." . PHP_EOL);
            exit(1);
        }
        $notes = $contents;
    } else {
        if (!is_file($file)) {
            fwrite(STDERR, 'Notes file not found: ' . $file . PHP_EOL);
            exit(1);
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            fwrite(STDERR, 'Unable to read notes file: ' . $file . PHP_EOL);
            exit(1);
        }
        $notes = $contents;
    }
} elseif ($parts !== []) {
    $notes = implode(' ', $parts);
} else {
    fwrite(STDERR, "Provide notes text, --file, or --clear." . PHP_EOL);
    exit(1);
}

$registry = new FeedRegistry();
try {
    $registry->setNotes($feedId, $notes);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed to update notes: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($notes === null || RSS\FeedRegistry::normalizeNotes($notes) === null) {
    echo 'Notes cleared for feed ' . $feedId . PHP_EOL;
} else {
    echo 'Notes updated for feed ' . $feedId . PHP_EOL;
}
