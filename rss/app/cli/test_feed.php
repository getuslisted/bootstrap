<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\FeedRegistry;
use RSS\FeedTester;
use RSS\Http;
use RSS\Normalizer;

/**
 * @param array<string, mixed>|false $options
 */
function printUsage($options = false): void
{
    $script = basename(__FILE__);
    $message = <<<TEXT
Usage: php app/cli/{$script} [--feed FEED_ID | --url FEED_URL] [options]

Options:
  --feed FEED_ID          Test a registered feed by its SHA256 identifier
  --url FEED_URL          Test an arbitrary feed URL (requires full scheme)
  --conditional           Send stored ETag/Last-Modified headers when available
  --header "Name: Value"  Append an extra request header (repeatable)
  --username USER         Override HTTP basic auth username for this test
  --password PASS         Override HTTP basic auth password for this test
  --json                  Emit machine-readable JSON output
  --help                  Show this message and exit
TEXT;

    if ($options === false) {
        fwrite(STDERR, $message . PHP_EOL);
    } else {
        echo $message . PHP_EOL;
    }
}

$options = getopt('', [
    'feed:',
    'url:',
    'json',
    'conditional',
    'header:',
    'username:',
    'password:',
    'help',
]);

if ($options === false) {
    printUsage(false);
    exit(1);
}

if (array_key_exists('help', $options)) {
    printUsage(true);
    exit(0);
}

$feedIdOption = $options['feed'] ?? null;
$urlOption = $options['url'] ?? null;

if (($feedIdOption === null && $urlOption === null) || ($feedIdOption !== null && $urlOption !== null)) {
    fwrite(STDERR, "Specify either --feed or --url." . PHP_EOL);
    printUsage(false);
    exit(1);
}

$headersOverride = [];
if (isset($options['header'])) {
    $rawHeaders = $options['header'];
    if (!is_array($rawHeaders)) {
        $rawHeaders = [$rawHeaders];
    }
    $rawHeaders = array_map(static function ($value): string {
        return (string) $value;
    }, $rawHeaders);

    try {
        $headersOverride = FeedRegistry::parseHeaderLines(implode(PHP_EOL, $rawHeaders));
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, 'Invalid header definition: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

$conditional = array_key_exists('conditional', $options);
$usernameOverride = isset($options['username']) ? (string) $options['username'] : null;
$passwordOverride = isset($options['password']) ? (string) $options['password'] : null;

if ($usernameOverride === null && $passwordOverride !== null) {
    fwrite(STDERR, 'HTTP password override ignored because no username was provided.' . PHP_EOL);
    $passwordOverride = null;
}

$registry = new FeedRegistry();
$http = new Http($config['http'] ?? []);
$tester = new FeedTester($http, new Normalizer());

try {
    if ($feedIdOption !== null) {
        $feedId = (string) $feedIdOption;
        $feed = $registry->getFeed($feedId);
        if ($feed === null) {
            fwrite(STDERR, 'Feed not found: ' . $feedId . PHP_EOL);
            exit(1);
        }
    } else {
        $feedUrl = (string) $urlOption;
        if (filter_var($feedUrl, FILTER_VALIDATE_URL) === false) {
            fwrite(STDERR, 'A valid feed URL is required when using --url.' . PHP_EOL);
            exit(1);
        }
        $feed = [
            'id' => hash('sha256', strtolower($feedUrl)),
            'url' => $feedUrl,
            'http_headers' => [],
        ];
    }
} catch (\Throwable $initializationError) {
    fwrite(STDERR, 'Unable to prepare feed details: ' . $initializationError->getMessage() . PHP_EOL);
    exit(1);
}

if (!isset($feed['http_headers']) || !is_array($feed['http_headers'])) {
    $feed['http_headers'] = [];
}
if ($headersOverride !== []) {
    $feed['http_headers'] = array_merge($feed['http_headers'], $headersOverride);
}

if ($usernameOverride !== null) {
    $feed['http_username'] = $usernameOverride;
    $feed['http_password'] = $passwordOverride ?? '';
}

try {
    $result = $tester->test($feed, $conditional);
} catch (\Throwable $testError) {
    fwrite(STDERR, 'Feed test failed: ' . $testError->getMessage() . PHP_EOL);
    exit(1);
}

$exitCode = 0;
if ($result['status'] === 'error') {
    $exitCode = 1;
} elseif ($result['status'] === 'parse_error') {
    $exitCode = 2;
}

if (array_key_exists('json', $options)) {
    $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false) {
        fwrite(STDERR, 'Unable to encode JSON output.' . PHP_EOL);
        exit(1);
    }
    echo $encoded . PHP_EOL;
    exit($exitCode);
}

echo 'Status: ' . $result['status'] . PHP_EOL;
if (isset($result['feed_id'])) {
    echo 'Feed ID: ' . $result['feed_id'] . PHP_EOL;
}
if (isset($result['url'])) {
    echo 'URL: ' . $result['url'] . PHP_EOL;
}
if (isset($result['http_status'])) {
    echo 'HTTP Status: ' . $result['http_status'] . PHP_EOL;
}
if (isset($result['duration_ms'])) {
    echo 'Duration: ' . $result['duration_ms'] . " ms" . PHP_EOL;
}
if (isset($result['tested_at'])) {
    echo 'Tested At (UTC): ' . gmdate('Y-m-d H:i:s', (int) $result['tested_at']) . PHP_EOL;
}
if (isset($result['content_length'])) {
    echo 'Body Length: ' . $result['content_length'] . ' bytes' . PHP_EOL;
}
if (isset($result['headers']) && is_array($result['headers'])) {
    $headerCopy = $result['headers'];
    $effectiveUrl = null;
    if (isset($headerCopy['effective_url']) && $headerCopy['effective_url'] !== null && $headerCopy['effective_url'] !== '') {
        $effectiveUrl = (string) $headerCopy['effective_url'];
        unset($headerCopy['effective_url']);
    }
    $redirectChain = null;
    if (isset($headerCopy['redirect_chain']) && $headerCopy['redirect_chain'] !== null && $headerCopy['redirect_chain'] !== '') {
        $redirectChain = (string) $headerCopy['redirect_chain'];
        unset($headerCopy['redirect_chain']);
    }
    $redirectCount = null;
    if (array_key_exists('redirect_count', $headerCopy) && $headerCopy['redirect_count'] !== null) {
        $redirectCount = (int) $headerCopy['redirect_count'];
        unset($headerCopy['redirect_count']);
    }

    if ($effectiveUrl !== null) {
        echo 'Effective URL: ' . $effectiveUrl . PHP_EOL;
    }
    if ($redirectCount !== null || $redirectChain !== null) {
        $parts = [];
        if ($redirectCount !== null) {
            $parts[] = 'count=' . $redirectCount;
        }
        if ($redirectChain !== null) {
            $parts[] = 'chain=' . $redirectChain;
        }
        echo 'Redirects: ' . implode(', ', $parts) . PHP_EOL;
    }

    if ($headerCopy !== []) {
        echo 'Response Headers:' . PHP_EOL;
        foreach ($headerCopy as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            echo '  - ' . $name . ': ' . $value . PHP_EOL;
        }
    }
}
if (isset($result['title']) && $result['title'] !== null) {
    echo 'Feed Title: ' . $result['title'] . PHP_EOL;
}
if (isset($result['language']) && $result['language'] !== null) {
    echo 'Detected Language: ' . $result['language'] . PHP_EOL;
}
if (isset($result['item_count'])) {
    echo 'Items Found: ' . $result['item_count'] . PHP_EOL;
}
if (isset($result['latest_item_ts']) && $result['latest_item_ts'] !== null) {
    echo 'Newest Item (UTC): ' . gmdate('Y-m-d H:i:s', (int) $result['latest_item_ts']) . PHP_EOL;
}
if (isset($result['earliest_item_ts']) && $result['earliest_item_ts'] !== null) {
    echo 'Oldest Item (UTC): ' . gmdate('Y-m-d H:i:s', (int) $result['earliest_item_ts']) . PHP_EOL;
}

if (!empty($result['parser_errors'])) {
    echo 'Parser Errors:' . PHP_EOL;
    foreach ($result['parser_errors'] as $errorMessage) {
        echo '  - ' . $errorMessage . PHP_EOL;
    }
}

if (!empty($result['samples']) && is_array($result['samples'])) {
    echo 'Sample Items:' . PHP_EOL;
    foreach ($result['samples'] as $sample) {
        $title = isset($sample['title']) ? (string) $sample['title'] : '[untitled]';
        $url = isset($sample['url']) ? (string) $sample['url'] : '[no url]';
        echo '  • ' . $title . PHP_EOL;
        echo '    URL: ' . $url . PHP_EOL;
        if (isset($sample['published_ts'])) {
            echo '    Published (UTC): ' . gmdate('Y-m-d H:i:s', (int) $sample['published_ts']) . PHP_EOL;
        }
        if (isset($sample['summary_text']) && $sample['summary_text'] !== '') {
            echo '    Summary: ' . $sample['summary_text'] . PHP_EOL;
        }
        if (!empty($sample['tags']) && is_array($sample['tags'])) {
            echo '    Tags: ' . implode(', ', $sample['tags']) . PHP_EOL;
        }
    }
}

if ($result['status'] === 'error' && isset($result['error'])) {
    echo 'Error: ' . $result['error'] . PHP_EOL;
}

echo PHP_EOL;
exit($exitCode);
