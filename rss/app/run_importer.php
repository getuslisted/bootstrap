<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RSS\AlertManager;
use RSS\FeedRegistry;
use RSS\Http;
use RSS\JobStatus;
use RSS\Lock;
use RSS\Normalizer;
use RSS\Publisher;
use RSS\Storage;

try {
    $lock = Lock::acquire('importer');
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    return;
}

$registry = new FeedRegistry();
$http = new Http($config['http']);
$normalizer = new Normalizer();
$publisher = new Publisher($registry, $config);
$alerts = new AlertManager($config['alerts'] ?? [], $config['paths']);

$makeStatusContext = static function (array $feed, ?string $status = null, ?string $titleOverride = null): array {
    $title = $titleOverride ?? ($feed['title'] ?? ($feed['url'] ?? ($feed['id'] ?? 'Feed')));
    return [
        'id' => isset($feed['id']) ? (string) $feed['id'] : '',
        'url' => isset($feed['url']) ? (string) $feed['url'] : '',
        'title' => $title,
        'status' => $status,
    ];
};

$args = array_slice($argv ?? [], 1);
$feedIds = [];
$limit = 100;
$force = false;
$requested = false;

foreach ($args as $arg) {
    if ($arg === '--help') {
        echo "Usage: php run_importer.php [--feed=ID] [--url=URL] [--limit=N] [--force]\n";
        echo "  --feed=ID   Fetch only the feed with the specified ID (repeatable).\n";
        echo "  --url=URL   Fetch only the feed registered for the provided URL.\n";
        echo "  --limit=N   Maximum number of feeds to process (default 100).\n";
        echo "  --force     Ignore fetch intervals for targeted feeds.\n";
        $lock->release();
        return;
    }

    if (str_starts_with($arg, '--feed=')) {
        $feedIds[] = substr($arg, 7);
        $requested = true;
        continue;
    }

    if (str_starts_with($arg, '--url=')) {
        $url = substr($arg, 6);
        if ($url !== '') {
            $requested = true;
            $feed = $registry->getFeedByUrl($url);
            if ($feed === null) {
                fwrite(STDERR, 'No feed registered for URL ' . $url . "\n");
            } else {
                $feedIds[] = $feed['id'];
            }
        }
        continue;
    }

    if (str_starts_with($arg, '--limit=')) {
        $value = (int) substr($arg, 8);
        if ($value > 0) {
            $limit = $value;
        }
        continue;
    }

    if ($arg === '--force') {
        $force = true;
        continue;
    }
}

$feedIds = array_values(array_unique(array_filter($feedIds, static function ($id): bool {
    return is_string($id) && $id !== '';
})));

if ($requested && $feedIds === []) {
    fwrite(STDERR, "No matching feeds found for the provided filters.\n");
    JobStatus::markStart('importer');
    JobStatus::markFinish('importer', 'idle', ['message' => 'No matching feeds']);
    $lock->release();
    return;
}

JobStatus::markStart('importer');

try {
    $feeds = $registry->getFeedsDueForFetch($limit, $feedIds, $force || ($requested && $feedIds !== []));
    if (empty($feeds)) {
        JobStatus::markFinish('importer', 'idle', ['message' => 'No feeds due']);
        echo "No feeds due for fetching\n";
        return;
    }

    echo 'Processing ' . count($feeds) . " feeds\n";

    $processed = 0;
    $newItems = 0;
    $errors = 0;

    foreach ($feeds as $feed) {
        $feedId = $feed['id'];
        echo "Fetching {$feed['url']}\n";

        $previousContext = $makeStatusContext($feed, isset($feed['status']) ? (string) $feed['status'] : null);

        $headers = [];
        if (!empty($feed['http_headers']) && is_array($feed['http_headers'])) {
            foreach ($feed['http_headers'] as $headerName => $headerValue) {
                if (!is_string($headerName) || $headerName === '') {
                    continue;
                }
                if (!is_scalar($headerValue)) {
                    continue;
                }
                $headers[(string) $headerName] = (string) $headerValue;
            }
        }
        if (!empty($feed['etag'])) {
            $headers['If-None-Match'] = $feed['etag'];
        }
        if (!empty($feed['last_modified'])) {
            $headers['If-Modified-Since'] = $feed['last_modified'];
        }

        $requestOptions = [];
        if (!empty($feed['http_username'])) {
            $requestOptions['auth'] = [
                (string) $feed['http_username'],
                isset($feed['http_password']) ? (string) $feed['http_password'] : '',
            ];
        }

        try {
            $response = $http->get($feed['url'], $headers, $requestOptions);
            if ($response->getStatusCode() === 304) {
                echo "Not modified\n";
                $registry->recordFetchSuccess($feedId, [
                    'etag' => $feed['etag'] ?? null,
                    'last_modified' => $feed['last_modified'] ?? null,
                    'last_fetch_ts' => time(),
                    'status' => 'not_modified',
                    'last_seen_at' => time(),
                ]);
                $alerts->handleFeedStatusChange(
                    $previousContext,
                    $makeStatusContext($feed, 'not_modified')
                );
                continue;
            }

            $body = (string) $response->getBody();
            $parser = new \SimplePie();
            $parser->set_raw_data($body);
            $parser->set_feed_url($feed['url']);
            $parser->enable_cache(false);
            $parser->init();

            $items = $parser->get_items();
            if (empty($items)) {
                echo "No items found\n";
                $emptyTitle = $parser->get_title() ?: ($feed['title'] ?? null);
                $registry->recordFetchSuccess($feedId, [
                    'etag' => $response->getHeaderLine('ETag') ?: null,
                    'last_modified' => $response->getHeaderLine('Last-Modified') ?: null,
                    'last_fetch_ts' => time(),
                    'status' => 'empty',
                    'last_seen_at' => time(),
                    'title' => $emptyTitle,
                ]);
                $alerts->handleFeedStatusChange(
                    $previousContext,
                    $makeStatusContext($feed, 'empty', $emptyTitle)
                );
                continue;
            }

            $feedTitle = $parser->get_title() ?: ($feed['title'] ?? null);
            $detectedLanguage = $parser->get_language();
            $registry->applyDetectedLanguage($feed, $detectedLanguage);
            $updatedFeed = $registry->getFeed($feedId) ?? $feed;

            $registry->updateFeedMetadata($feedId, [
                'url' => $feed['url'],
                'title' => $feedTitle,
                'fetched_at' => time(),
                'language' => $updatedFeed['language'] ?? null,
                'language_locked' => !empty($updatedFeed['language_locked']),
            ]);

            $existing = [];
            foreach ($registry->getRecentItems($feedId, 1000) as $row) {
                $existing[$row['uid']] = true;
                $existing[$row['url']] = true;
            }

            $newCount = 0;
            foreach ($items as $item) {
                $data = $normalizer->normalize($feedId, $item);
                if (isset($existing[$data['uid']]) || isset($existing[$data['url']])) {
                    continue;
                }

                try {
                    $registry->upsertItem([
                        'uid' => $data['uid'],
                        'feed_id' => $feedId,
                        'url' => $data['url'],
                        'title' => $data['title'],
                        'published_ts' => $data['published_ts'],
                        'is_dead' => 0,
                        'last_verified_ts' => $data['last_verified_ts'],
                        'fail_count' => 0,
                    ]);
                } catch (PDOException $dbException) {
                    if (FeedRegistry::isUniqueUrlViolation($dbException)) {
                        echo "Duplicate URL skipped: {$data['url']}\n";
                        $existing[$data['url']] = true;
                        continue;
                    }

                    throw $dbException;
                }

                try {
                    Storage::appendJsonLine($feedId, $data);
                } catch (\Throwable $writeError) {
                    $registry->deleteItemsByUid([$data['uid']]);
                    throw $writeError;
                }

                $existing[$data['uid']] = true;
                $existing[$data['url']] = true;
                $newCount++;
            }

            $registry->recordFetchSuccess($feedId, [
                'etag' => $response->getHeaderLine('ETag') ?: null,
                'last_modified' => $response->getHeaderLine('Last-Modified') ?: null,
                'last_fetch_ts' => time(),
                'status' => 'ok',
                'last_seen_at' => time(),
                'title' => $feedTitle,
            ]);

            $alerts->handleFeedStatusChange(
                $previousContext,
                $makeStatusContext($feed, 'ok', $feedTitle)
            );

            echo "Added {$newCount} items\n";
            $processed++;
            $newItems += $newCount;
        } catch (\Throwable $e) {
            $errorStatus = 'error: ' . substr($e->getMessage(), 0, 120);
            $registry->recordFetchFailure($feedId, $errorStatus);
            $alerts->handleFeedStatusChange(
                $previousContext,
                $makeStatusContext($feed, $errorStatus)
            );
            fwrite(STDERR, 'Error fetching feed ' . $feed['url'] . ': ' . $e->getMessage() . "\n");
            $errors++;
        }
    }

    JobStatus::markFinish('importer', $errors > 0 ? 'warning' : 'ok', [
        'feeds_processed' => $processed,
        'new_items' => $newItems,
        'feed_errors' => $errors,
    ]);
} catch (\Throwable $e) {
    JobStatus::markFinish('importer', 'error', ['message' => $e->getMessage()]);
    fwrite(STDERR, 'Importer failed: ' . $e->getMessage() . "\n");
} finally {
    try {
        $publisher->refreshHealthStatusFromRegistry();
    } catch (\Throwable $statusError) {
        fwrite(STDERR, 'Failed to refresh feed status: ' . $statusError->getMessage() . "\n");
    }
    try {
        JobStatus::writePublicStatus($config['paths']['public'] . '/status/jobs.json');
    } catch (\Throwable $statusError) {
        fwrite(STDERR, 'Failed to write job status file: ' . $statusError->getMessage() . "\n");
    }
    $lock->release();
}
