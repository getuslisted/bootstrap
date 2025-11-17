<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;

if ($argc < 2 || in_array('--help', $argv, true)) {
    fwrite(STDERR, "Usage: php app/cli/add_feed.php <feed-url> [title] [fetch-interval] [language] [options]\n");
    fwrite(STDERR, "Options:\n");
    fwrite(STDERR, "  --title=NAME         Override the feed title without using positionals.\n");
    fwrite(STDERR, "  --interval=N         Fetch interval in seconds (>=300).\n");
    fwrite(STDERR, "  --language=CODE      Lock the feed language (e.g. en-US).\n");
    fwrite(STDERR, "  --http-user=USER     HTTP Basic auth username for protected feeds.\n");
    fwrite(STDERR, "  --http-pass=PASS     HTTP Basic auth password (use carefully).\n");
    fwrite(STDERR, "  --http-pass-file=F   Read the HTTP password from file F.\n");
    fwrite(STDERR, "  --header=\"NAME:VALUE\"  Append a custom HTTP header for the feed (repeatable).\n");
    fwrite(STDERR, "  --headers-file=F    Load additional headers from file F (one per line).\n");
    fwrite(STDERR, "  --notes=TEXT       Attach operator notes to the feed.\n");
    fwrite(STDERR, "  --notes-file=F    Load notes from file F (use - for STDIN).\n");
    fwrite(STDERR, "  --private           Mark the feed as private (omit from public outputs).\n");
    exit($argc < 2 ? 1 : 0);
}

$url = trim($argv[1]);
$title = null;
$interval = 900;
$language = null;
$httpUser = null;
$httpPass = null;
$passwordFile = null;
$headerLines = [];
$headersFile = null;
$markPrivate = false;

$positionals = [];
for ($i = 2; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (str_starts_with($arg, '--title=')) {
        $title = substr($arg, 8);
        continue;
    }
    if (str_starts_with($arg, '--interval=')) {
        $candidate = (int) substr($arg, 11);
        if ($candidate >= 300) {
            $interval = $candidate;
        }
        continue;
    }
    if (str_starts_with($arg, '--language=')) {
        $language = substr($arg, 11);
        continue;
    }
    if (str_starts_with($arg, '--http-user=')) {
        $httpUser = substr($arg, 12);
        continue;
    }
    if (str_starts_with($arg, '--http-pass=')) {
        $httpPass = substr($arg, 12);
        continue;
    }
    if (str_starts_with($arg, '--http-pass-file=')) {
        $passwordFile = substr($arg, 17);
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
    if (str_starts_with($arg, '--notes=')) {
        $notesInput = substr($arg, 8);
        continue;
    }
    if (str_starts_with($arg, '--notes-file=')) {
        $notesFile = substr($arg, 13);
        continue;
    }
    if ($arg === '--private') {
        $markPrivate = true;
        continue;
    }
    $positionals[] = $arg;
}

if ($title === null && isset($positionals[0])) {
    $title = $positionals[0];
}
if (isset($positionals[1]) && ctype_digit((string) $positionals[1])) {
    $interval = max(300, (int) $positionals[1]);
}
if ($language === null && isset($positionals[2])) {
    $language = $positionals[2];
}

if ($passwordFile !== null && $passwordFile !== '') {
    if (!is_file($passwordFile)) {
        fwrite(STDERR, 'Password file not found: ' . $passwordFile . PHP_EOL);
        exit(1);
    }
    $httpPass = rtrim((string) file_get_contents($passwordFile), "\r\n");
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

if ($notesFile !== null && $notesFile !== '' && $notesInput !== null) {
    fwrite(STDERR, 'Specify either --notes or --notes-file, not both.' . PHP_EOL);
    exit(1);
}

if ($notesFile !== null && $notesFile !== '') {
    if ($notesFile === '-') {
        $stdin = stream_get_contents(STDIN);
        if ($stdin === false) {
            fwrite(STDERR, "Failed to read notes from STDIN." . PHP_EOL);
            exit(1);
        }
        $notesInput = $stdin;
    } elseif (!is_file($notesFile)) {
        fwrite(STDERR, 'Notes file not found: ' . $notesFile . PHP_EOL);
        exit(1);
    } else {
        $contents = file_get_contents($notesFile);
        if ($contents === false) {
            fwrite(STDERR, 'Unable to read notes file: ' . $notesFile . PHP_EOL);
            exit(1);
        }
        $notesInput = $contents;
    }
}

$headerLines = array_values(array_filter(array_map('trim', $headerLines), static function ($line): bool {
    return $line !== '';
}));

$title = $title !== null ? trim($title) : null;
$language = is_string($language) ? trim($language) : null;
$language = $language !== '' ? $language : null;
$httpUser = is_string($httpUser) ? trim($httpUser) : null;
$httpUser = $httpUser !== '' ? $httpUser : null;
$httpPass = is_string($httpPass) ? $httpPass : null;

$registry = new FeedRegistry();

try {
    $result = $registry->registerFeed($url, $title, $interval);
    if ($language !== null) {
        $registry->setLanguagePreference($result['id'], $language, true);
    }
    if ($httpUser !== null) {
        $registry->setHttpCredentials($result['id'], $httpUser, $httpPass);
    } elseif ($httpPass !== null) {
        fwrite(STDERR, "Warning: HTTP password ignored because no username was provided." . PHP_EOL);
    }
    if (!empty($headerLines)) {
        try {
            $headers = FeedRegistry::parseHeaderLines(implode("\n", $headerLines));
            $registry->setHttpHeaders($result['id'], $headers);
        } catch (InvalidArgumentException $headerError) {
            fwrite(STDERR, 'Failed to apply custom headers: ' . $headerError->getMessage() . PHP_EOL);
            exit(1);
        }
    }
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed to register feed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($result['created']) {
    echo 'Feed registered with ID: ' . $result['id'] . PHP_EOL;
} else {
    echo 'Feed already existed; metadata refreshed for ID: ' . $result['id'] . PHP_EOL;
}

if ($notesInput !== null) {
    try {
        $registry->setNotes($result['id'], $notesInput);
        if (RSS\FeedRegistry::normalizeNotes($notesInput) === null) {
            echo 'Notes cleared for feed ' . $result['id'] . PHP_EOL;
        } else {
            echo 'Notes saved for feed ' . $result['id'] . PHP_EOL;
        }
    } catch (Throwable $notesError) {
        fwrite(STDERR, 'Warning: unable to save notes: ' . $notesError->getMessage() . PHP_EOL);
    }
}

if ($language !== null) {
    echo 'Language locked to ' . FeedRegistry::normalizeLanguage($language) . PHP_EOL;
}
if ($httpUser !== null) {
    echo 'HTTP Basic authentication saved for user ' . $httpUser . PHP_EOL;
}
if (!empty($headerLines)) {
    echo 'Custom headers saved (' . count($headerLines) . ' header' . (count($headerLines) === 1 ? '' : 's') . ').' . PHP_EOL;
}
if ($markPrivate) {
    $registry->setPrivacy($result['id'], true);
    echo 'Feed marked as private and excluded from public publishing.' . PHP_EOL;
}
