<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;

if ($argc < 2 || in_array('--help', $argv, true)) {
    fwrite(STDERR, "Usage: php app/cli/set_http_auth.php <feed-id> [username] [password] [options]\n");
    fwrite(STDERR, "Options:\n");
    fwrite(STDERR, "  --username=USER       Explicitly set the HTTP Basic username.\n");
    fwrite(STDERR, "  --password=PASS       Provide the HTTP Basic password.\n");
    fwrite(STDERR, "  --password-file=FILE  Read the password from FILE.\n");
    fwrite(STDERR, "  --clear               Remove stored HTTP credentials for the feed.\n");
    exit($argc < 2 ? 1 : 0);
}

$feedId = trim($argv[1]);
if ($feedId === '') {
    fwrite(STDERR, "Feed ID is required." . PHP_EOL);
    exit(1);
}

$clear = false;
$username = null;
$password = null;
$passwordFile = null;

for ($i = 2; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--clear') {
        $clear = true;
        continue;
    }
    if (str_starts_with($arg, '--username=')) {
        $username = substr($arg, 11);
        continue;
    }
    if (str_starts_with($arg, '--password=')) {
        $password = substr($arg, 11);
        continue;
    }
    if (str_starts_with($arg, '--password-file=')) {
        $passwordFile = substr($arg, 16);
        continue;
    }
    if ($username === null) {
        $username = $arg;
        continue;
    }
    if ($password === null) {
        $password = $arg;
        continue;
    }
}

if ($passwordFile !== null && $passwordFile !== '') {
    if (!is_file($passwordFile)) {
        fwrite(STDERR, 'Password file not found: ' . $passwordFile . PHP_EOL);
        exit(1);
    }
    $password = rtrim((string) file_get_contents($passwordFile), "\r\n");
}

$registry = new FeedRegistry();

try {
    if ($clear) {
        $registry->setHttpCredentials($feedId, null, null);
        echo 'HTTP credentials cleared for feed ' . $feedId . PHP_EOL;
        exit(0);
    }

    $username = is_string($username) ? trim($username) : null;
    if ($username === null || $username === '') {
        fwrite(STDERR, "A username is required unless --clear is provided." . PHP_EOL);
        exit(1);
    }

    $password = $password === null ? '' : $password;
    $registry->setHttpCredentials($feedId, $username, $password);
    echo 'HTTP credentials updated for feed ' . $feedId . ' (user ' . $username . ')' . PHP_EOL;
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed to update HTTP credentials: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
