<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use RSS\AlertManager;
use RSS\Backup;
use RSS\BackupStatus;
use RSS\Diagnostics;
use RSS\FeedRegistry;
use RSS\FeedTester;
use RSS\Http;
use RSS\JobStatus;
use RSS\Metrics;
use RSS\MetricsHistory;
use RSS\Normalizer;
use RSS\Opml;
use RSS\Publisher;
use RSS\Security;

try {
    Security::enforceIpAllowlist($config['admin_allowed_ips']);
} catch (Throwable $e) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$registry = new FeedRegistry();
$publisher = new Publisher($registry, $config);
$errors = [];
$messages = [];
$needsStatusRefresh = false;
$feedTestResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken((string) $token)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        if (isset($_POST['action']) && $_POST['action'] === 'add') {
            $url = trim((string) ($_POST['url'] ?? ''));
            $title = trim((string) ($_POST['title'] ?? ''));
            $interval = max(300, (int) ($_POST['fetch_interval'] ?? 900));
            $languageInput = trim((string) ($_POST['language'] ?? ''));
            $httpUsername = trim((string) ($_POST['http_username'] ?? ''));
            $httpPassword = (string) ($_POST['http_password'] ?? '');
            $httpHeadersInput = trim((string) ($_POST['http_headers'] ?? ''));
            $notesInput = (string) ($_POST['notes'] ?? '');
            $markPrivate = isset($_POST['is_private']) && $_POST['is_private'] === '1';
            try {
                $result = $registry->registerFeed($url, $title !== '' ? $title : null, $interval);
                if ($languageInput !== '') {
                    $registry->setLanguagePreference($result['id'], $languageInput, true);
                    $messages[] = 'Language locked to ' . htmlspecialchars((string) FeedRegistry::normalizeLanguage($languageInput), ENT_QUOTES, 'UTF-8') . ' for ' . htmlspecialchars($result['id'], ENT_QUOTES, 'UTF-8');
                }
                if ($httpUsername !== '') {
                    $registry->setHttpCredentials($result['id'], $httpUsername, $httpPassword);
                    $messages[] = 'HTTP authentication saved for ' . htmlspecialchars($result['id'], ENT_QUOTES, 'UTF-8');
                } elseif ($httpPassword !== '') {
                    $errors[] = 'HTTP password ignored because no username was provided.';
                }
                if ($httpHeadersInput !== '') {
                    try {
                        $headers = FeedRegistry::parseHeaderLines($httpHeadersInput);
                        $registry->setHttpHeaders($result['id'], $headers);
                        $messages[] = 'Custom headers saved for ' . htmlspecialchars($result['id'], ENT_QUOTES, 'UTF-8');
                    } catch (InvalidArgumentException $headerError) {
                        $errors[] = htmlspecialchars($headerError->getMessage(), ENT_QUOTES, 'UTF-8');
                    }
                }
                if ($markPrivate) {
                    $registry->setPrivacy($result['id'], true);
                    try {
                        $publisher->removeFeedOutputs($result['id']);
                    } catch (Throwable $cleanupError) {
                        $errors[] = 'Warning: unable to purge published files for private feed: ' . htmlspecialchars($cleanupError->getMessage(), ENT_QUOTES, 'UTF-8');
                    }
                    $messages[] = 'Feed marked as private: ' . htmlspecialchars($result['id'], ENT_QUOTES, 'UTF-8');
                }
                if ($notesInput !== '') {
                    try {
                        $registry->setNotes($result['id'], $notesInput);
                        $normalizedNote = FeedRegistry::normalizeNotes($notesInput);
                        $messages[] = $normalizedNote === null
                            ? 'Notes cleared for ' . htmlspecialchars($result['id'], ENT_QUOTES, 'UTF-8')
                            : 'Notes saved for ' . htmlspecialchars($result['id'], ENT_QUOTES, 'UTF-8');
                        $needsStatusRefresh = true;
                    } catch (Throwable $notesError) {
                        $errors[] = 'Warning: unable to save notes: ' . htmlspecialchars($notesError->getMessage(), ENT_QUOTES, 'UTF-8');
                    }
                }
                $messages[] = $result['created']
                    ? 'Feed added successfully. ID: ' . $result['id']
                    : 'Feed already existed. Metadata refreshed for ID: ' . $result['id'];
                $needsStatusRefresh = true;
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            } catch (Throwable $e) {
                $errors[] = 'Unable to register feed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_language') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            $languageInput = trim((string) ($_POST['language'] ?? ''));
            $locked = isset($_POST['language_locked']) && $_POST['language_locked'] === '1';
            if ($feedId !== '') {
                try {
                    $normalized = $languageInput !== '' ? FeedRegistry::normalizeLanguage($languageInput) : null;
                    $registry->setLanguagePreference($feedId, $languageInput !== '' ? $languageInput : null, $locked);
                    if ($locked) {
                        $label = $normalized ?? $languageInput;
                        $messages[] = 'Language locked for ' . htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8')
                            . ' (' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . ')';
                    } else {
                        $messages[] = 'Language auto-detection enabled for ' . htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8');
                    }
                    $needsStatusRefresh = true;
                } catch (InvalidArgumentException $e) {
                    $errors[] = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                } catch (Throwable $e) {
                    $errors[] = 'Unable to update language: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_http_auth') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            $usernameInput = trim((string) ($_POST['http_username'] ?? ''));
            $passwordInput = (string) ($_POST['http_password'] ?? '');
            if ($feedId !== '') {
                if ($usernameInput === '') {
                    $errors[] = 'HTTP username is required to update credentials.';
                } else {
                    try {
                        $registry->setHttpCredentials($feedId, $usernameInput, $passwordInput);
                        $messages[] = 'HTTP authentication updated for ' . htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8');
                        $needsStatusRefresh = true;
                    } catch (InvalidArgumentException $e) {
                        $errors[] = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                    } catch (Throwable $e) {
                        $errors[] = 'Unable to update HTTP credentials: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                    }
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_http_headers') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            $headersInput = trim((string) ($_POST['http_headers'] ?? ''));
            if ($feedId !== '') {
                try {
                    if ($headersInput === '') {
                        $registry->setHttpHeaders($feedId, []);
                        $messages[] = 'Custom headers cleared for ' . htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8');
                    } else {
                        $headers = FeedRegistry::parseHeaderLines($headersInput);
                        $registry->setHttpHeaders($feedId, $headers);
                        $messages[] = 'Custom headers updated for ' . htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8');
                    }
                    $needsStatusRefresh = true;
                } catch (InvalidArgumentException $e) {
                    $errors[] = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                } catch (Throwable $e) {
                    $errors[] = 'Unable to update custom headers: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'clear_http_auth') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            if ($feedId !== '') {
                try {
                    $registry->setHttpCredentials($feedId, null, null);
                    $messages[] = 'HTTP authentication cleared for ' . htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8');
                    $needsStatusRefresh = true;
                } catch (InvalidArgumentException $e) {
                    $errors[] = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                } catch (Throwable $e) {
                    $errors[] = 'Unable to clear HTTP credentials: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'clear_http_headers') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            if ($feedId !== '') {
                try {
                    $registry->setHttpHeaders($feedId, []);
                    $messages[] = 'Custom headers cleared for ' . htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8');
                    $needsStatusRefresh = true;
                } catch (InvalidArgumentException $e) {
                    $errors[] = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                } catch (Throwable $e) {
                    $errors[] = 'Unable to clear custom headers: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_notes') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            $notesInput = (string) ($_POST['notes'] ?? '');
            if ($feedId !== '') {
                try {
                    $registry->setNotes($feedId, $notesInput);
                    $normalized = FeedRegistry::normalizeNotes($notesInput);
                    $messages[] = $normalized === null
                        ? 'Notes cleared for ' . htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8')
                        : 'Notes updated for ' . htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8');
                    $needsStatusRefresh = true;
                } catch (Throwable $e) {
                    $errors[] = 'Unable to update notes: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            if ($feedId !== '') {
                $registry->deleteFeed($feedId);
                $dir = $config['paths']['storage'] . '/feeds/' . $feedId;
                if (is_dir($dir)) {
                    array_map('unlink', glob($dir . '/*') ?: []);
                    @rmdir($dir);
                }
                $publicDir = $config['paths']['public'] . '/feeds';
                foreach (['json', 'rss', 'atom'] as $ext) {
                    @unlink($publicDir . '/' . $feedId . '.' . $ext);
                }
                $messages[] = 'Feed removed: ' . htmlspecialchars($feedId);
                $needsStatusRefresh = true;
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_interval') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            $interval = (int) ($_POST['fetch_interval'] ?? 900);
            if ($feedId !== '') {
                $registry->updateFetchInterval($feedId, $interval);
                $messages[] = 'Updated fetch interval for ' . htmlspecialchars($feedId);
                $needsStatusRefresh = true;
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'reset_fetch') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            if ($feedId !== '') {
                $registry->resetLastFetch($feedId, 'queued');
                $messages[] = 'Queued immediate fetch for ' . htmlspecialchars($feedId);
                $needsStatusRefresh = true;
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'pause') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            if ($feedId !== '') {
                $feed = $registry->getFeed($feedId);
                if ($feed === null) {
                    $errors[] = 'Feed not found.';
                } elseif (!empty($feed['is_paused'])) {
                    $messages[] = 'Feed was already paused.';
                } else {
                    $registry->pauseFeed($feedId);
                    $messages[] = 'Feed paused: ' . htmlspecialchars($feedId);
                    $needsStatusRefresh = true;
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'resume') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            $queue = (string) ($_POST['queue'] ?? '1');
            if ($feedId !== '') {
                $feed = $registry->getFeed($feedId);
                if ($feed === null) {
                    $errors[] = 'Feed not found.';
                } else {
                    $queueFetch = $queue !== '0';
                    $registry->resumeFeed($feedId, $queueFetch);
                    $messages[] = 'Feed resumed: ' . htmlspecialchars($feedId) . ($queueFetch ? ' (queued)' : '');
                    $needsStatusRefresh = true;
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'test_feed') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            $useConditional = isset($_POST['conditional']) && $_POST['conditional'] === '1';
            if ($feedId !== '') {
                $feed = $registry->getFeed($feedId);
                if ($feed === null) {
                    $errors[] = 'Feed not found.';
                } else {
                    $requestHeaders = [];
                    if (isset($feed['http_headers']) && is_array($feed['http_headers'])) {
                        foreach ($feed['http_headers'] as $headerName => $headerValue) {
                            if (!is_string($headerName) || $headerName === '') {
                                continue;
                            }
                            if (!is_scalar($headerValue)) {
                                continue;
                            }
                            $requestHeaders[$headerName] = (string) $headerValue;
                        }
                    }
                    if ($useConditional) {
                        if (!empty($feed['etag'])) {
                            $requestHeaders['If-None-Match'] = (string) $feed['etag'];
                        }
                        if (!empty($feed['last_modified'])) {
                            $requestHeaders['If-Modified-Since'] = (string) $feed['last_modified'];
                        }
                    }

                    try {
                        $tester = new FeedTester(new Http($config['http'] ?? []), new Normalizer());
                        $result = $tester->test($feed, $useConditional);
                        $result['feed_title'] = $feed['title'] ?? null;
                        $result['stored_status'] = $feed['status'] ?? null;
                        $result['request_headers'] = $requestHeaders;
                        $result['conditional_used'] = $useConditional;
                        $feedTestResult = $result;

                        if ($result['status'] === 'error') {
                            $errorMessage = isset($result['error']) ? (string) $result['error'] : 'Unknown error';
                            $errors[] = 'Feed test failed: ' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8');
                        } elseif ($result['status'] === 'parse_error') {
                            $parseMessage = 'Feed fetched but parsing failed for ' . htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8') . '.';
                            if (!empty($result['parser_errors']) && is_array($result['parser_errors'])) {
                                $firstError = (string) reset($result['parser_errors']);
                                if ($firstError !== '') {
                                    $parseMessage .= ' ' . htmlspecialchars($firstError, ENT_QUOTES, 'UTF-8');
                                }
                            }
                            $errors[] = $parseMessage;
                        } else {
                            $httpStatus = isset($result['http_status']) ? (int) $result['http_status'] : 0;
                            $itemCount = isset($result['item_count']) ? (int) $result['item_count'] : 0;
                            $messages[] = sprintf(
                                'Feed test succeeded (%s, %d items) for %s.',
                                $httpStatus > 0 ? 'HTTP ' . $httpStatus : 'no HTTP status',
                                $itemCount,
                                htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8')
                            );
                        }
                    } catch (Throwable $testError) {
                        $errors[] = 'Unable to execute feed test: ' . htmlspecialchars($testError->getMessage(), ENT_QUOTES, 'UTF-8');
                    }
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'make_private') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            if ($feedId !== '') {
                $feed = $registry->getFeed($feedId);
                if ($feed === null) {
                    $errors[] = 'Feed not found.';
                } elseif (!empty($feed['is_private'])) {
                    $messages[] = 'Feed was already private.';
                } else {
                    try {
                        $registry->setPrivacy($feedId, true);
                        try {
                            $publisher->removeFeedOutputs($feedId);
                        } catch (Throwable $cleanupError) {
                            $errors[] = 'Private feed outputs could not be fully removed: ' . htmlspecialchars($cleanupError->getMessage(), ENT_QUOTES, 'UTF-8');
                        }
                        $messages[] = 'Feed marked as private: ' . htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8');
                        $needsStatusRefresh = true;
                    } catch (Throwable $privacyError) {
                        $errors[] = 'Unable to mark feed private: ' . htmlspecialchars($privacyError->getMessage(), ENT_QUOTES, 'UTF-8');
                    }
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'make_public') {
            $feedId = (string) ($_POST['feed_id'] ?? '');
            if ($feedId !== '') {
                $feed = $registry->getFeed($feedId);
                if ($feed === null) {
                    $errors[] = 'Feed not found.';
                } elseif (empty($feed['is_private'])) {
                    $messages[] = 'Feed was already public.';
                } else {
                    try {
                        $registry->setPrivacy($feedId, false);
                        $messages[] = 'Feed marked as public: ' . htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8');
                        $needsStatusRefresh = true;
                    } catch (Throwable $privacyError) {
                        $errors[] = 'Unable to mark feed public: ' . htmlspecialchars($privacyError->getMessage(), ENT_QUOTES, 'UTF-8');
                    }
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'import_opml') {
            $uploaded = $_FILES['opml_file'] ?? null;
            if (!is_array($uploaded) || !isset($uploaded['tmp_name'])) {
                $errors[] = 'No OPML file was uploaded.';
            } elseif (!is_uploaded_file((string) $uploaded['tmp_name'])) {
                $errors[] = 'Invalid upload attempt.';
            } elseif ((int) ($uploaded['error'] ?? 0) !== UPLOAD_ERR_OK) {
                $errors[] = 'Upload failed. Error code: ' . (int) $uploaded['error'];
            } elseif ((int) ($uploaded['size'] ?? 0) > 524288) {
                $errors[] = 'OPML file is too large (max 512KB).';
            } else {
                $content = (string) file_get_contents((string) $uploaded['tmp_name']);
                try {
                    $feedsToImport = Opml::parse($content);
                } catch (InvalidArgumentException $e) {
                    $feedsToImport = [];
                    $errors[] = 'Unable to parse OPML: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                }

                $added = 0;
                $updated = 0;
                $skipped = 0;
                foreach ($feedsToImport as $feed) {
                        try {
                            $result = $registry->registerFeed(
                                $feed['url'],
                                $feed['title'],
                                $feed['fetch_interval'] ?? 900
                            );
                            if (!empty($feed['language'])) {
                                $registry->setLanguagePreference($result['id'], $feed['language'], true);
                            }
                            if (!empty($feed['http_username'])) {
                                $registry->setHttpCredentials($result['id'], $feed['http_username'], $feed['http_password'] ?? '');
                            }
                            if ($result['created']) {
                                $added++;
                            } else {
                                $updated++;
                            }
                            $privateDefined = !empty($feed['private_defined']);
                            if (!empty($feed['is_private'])) {
                                $registry->setPrivacy($result['id'], true);
                            } elseif ($privateDefined) {
                                $registry->setPrivacy($result['id'], false);
                            }
                        $needsStatusRefresh = true;
                    } catch (Throwable $e) {
                        $skipped++;
                    }
                }

                if ($added > 0 || $updated > 0) {
                    $messages[] = sprintf(
                        'Imported %d feeds (%d new, %d updated).%s',
                        $added + $updated,
                        $added,
                        $updated,
                        $skipped > 0 ? ' Skipped ' . $skipped . ' invalid entries.' : ''
                    );
                } elseif ($skipped > 0 && empty($errors)) {
                    $errors[] = 'All OPML entries were skipped.';
                }
            }
        }
    }
}

$feeds = $registry->listFeeds();
$csrf = Security::getCsrfToken();
$jobs = JobStatus::getAll();
$feedSummaries = $registry->getFeedStatusSummary();
$summaryById = [];
foreach ($feedSummaries as $summary) {
    $summaryById[$summary['id']] = $summary;
}

$diagnostics = Diagnostics::runChecks($config);
$diagnosticSummary = Diagnostics::summarize($diagnostics);

$metrics = Metrics::calculate($feedSummaries, $config);
$alertsMetrics = $metrics['alerts'] ?? [];
$alertsEnabled = !empty($alertsMetrics['enabled']);
$alertsRecipient = $alertsMetrics['recipient'] ?? null;
$alertsActive = $alertsEnabled ? (int) ($alertsMetrics['active'] ?? 0) : 0;
$alertsLastTs = null;
if ($alertsEnabled && isset($alertsMetrics['last_sent_ts']) && $alertsMetrics['last_sent_ts'] !== null) {
    $alertsLastTs = (int) $alertsMetrics['last_sent_ts'];
}

$alertsState = AlertManager::loadState($config['paths']);
$alertsHistory = [];
if (isset($alertsState['history']) && is_array($alertsState['history'])) {
    $alertsHistory = array_reverse(array_slice($alertsState['history'], -10));
    if ($alertsLastTs === null) {
        foreach ($alertsHistory as $entry) {
            $candidateTs = isset($entry['sent_ts']) ? (int) $entry['sent_ts'] : 0;
            if ($candidateTs > 0) {
                $alertsLastTs = $alertsLastTs === null ? $candidateTs : max($alertsLastTs, $candidateTs);
            }
        }
    }
}
$alertsLastDisplay = $alertsLastTs !== null ? gmdate('Y-m-d H:i', $alertsLastTs) : '—';

if ($needsStatusRefresh) {
    try {
        $publisher->refreshHealthStatusFromRegistry();
    } catch (Throwable $statusError) {
        $errors[] = 'Warning: unable to refresh feed status files (' . htmlspecialchars($statusError->getMessage(), ENT_QUOTES, 'UTF-8') . ')';
    }
}

$itemsMetrics = $metrics['items'] ?? [];
$latestItemTs = null;
if (isset($itemsMetrics['latest_ts']) && $itemsMetrics['latest_ts'] !== null) {
    $latestItemTs = (int) $itemsMetrics['latest_ts'];
}
$oldestItemTs = null;
if (isset($itemsMetrics['oldest_ts']) && $itemsMetrics['oldest_ts'] !== null) {
    $oldestItemTs = (int) $itemsMetrics['oldest_ts'];
}
$languagesMetrics = $metrics['languages'] ?? [];
$topLanguage = $languagesMetrics['most_common'] ?? null;
$diskMetrics = $metrics['disk'] ?? null;

$totals = [
    'feeds' => (int) ($metrics['feeds']['total'] ?? count($feedSummaries)),
    'feedsWithErrors' => (int) ($metrics['feeds']['errors'] ?? 0),
    'feedsPaused' => (int) ($metrics['feeds']['paused'] ?? 0),
    'feedsPrivate' => (int) ($metrics['feeds']['private'] ?? 0),
    'feedsPublic' => (int) ($metrics['feeds']['public'] ?? max(0, ($metrics['feeds']['total'] ?? count($feedSummaries)) - ($metrics['feeds']['private'] ?? 0))),
    'feedsOverdue' => (int) ($metrics['feeds']['overdue'] ?? 0),
    'feedsBackoff' => (int) ($metrics['feeds']['in_backoff'] ?? 0),
    'maxErrorStreak' => (int) ($metrics['feeds']['max_error_streak'] ?? 0),
    'feedsWithAuth' => (int) ($metrics['feeds']['with_http_auth'] ?? 0),
    'feedsWithHeaders' => (int) ($metrics['feeds']['with_custom_headers'] ?? 0),
    'feedsWithNotes' => (int) ($metrics['feeds']['with_notes'] ?? 0),
    'itemsActive' => (int) ($itemsMetrics['active'] ?? 0),
    'itemsDead' => (int) ($itemsMetrics['dead'] ?? 0),
    'itemsAverage' => (float) ($itemsMetrics['average_per_feed'] ?? 0),
    'latestItemDisplay' => $latestItemTs !== null ? gmdate('Y-m-d H:i', $latestItemTs) : '—',
    'oldestItemDisplay' => $oldestItemTs !== null ? gmdate('Y-m-d H:i', $oldestItemTs) : '—',
    'languagesUnique' => (int) ($languagesMetrics['unique'] ?? 0),
    'languagesLocked' => (int) ($languagesMetrics['locked'] ?? 0),
    'languagesTop' => $topLanguage !== null ? (string) $topLanguage : '—',
    'diskStatus' => is_array($diskMetrics) && isset($diskMetrics['status']) ? (string) $diskMetrics['status'] : 'unknown',
    'diskPath' => is_array($diskMetrics) && isset($diskMetrics['path']) ? (string) $diskMetrics['path'] : '—',
    'diskFree' => is_array($diskMetrics) && isset($diskMetrics['free_human']) ? (string) $diskMetrics['free_human'] : '—',
    'diskTotal' => is_array($diskMetrics) && isset($diskMetrics['total_human']) ? (string) $diskMetrics['total_human'] : '—',
    'diskUsedPercent' => is_array($diskMetrics) && isset($diskMetrics['used_percent']) && $diskMetrics['used_percent'] !== null
        ? (float) $diskMetrics['used_percent']
        : null,
    'diskFreePercent' => is_array($diskMetrics) && isset($diskMetrics['free_percent']) && $diskMetrics['free_percent'] !== null
        ? (float) $diskMetrics['free_percent']
        : null,
];

$metricsHistory = MetricsHistory::loadRecent($config, 7);
$tagsConfig = $config['metrics']['tags'] ?? [];
$tagsEnabledConfigured = !array_key_exists('enabled', $tagsConfig) || !empty($tagsConfig['enabled']);
$tagSummary = null;
if ($tagsEnabledConfigured) {
    $tagsPath = rtrim($config['paths']['public'], '/') . '/status/tags.json';
    if (is_file($tagsPath)) {
        $rawTags = @file_get_contents($tagsPath);
        if ($rawTags !== false && trim($rawTags) !== '') {
            try {
                $decodedTags = json_decode($rawTags, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decodedTags)) {
                    $tagSummary = $decodedTags;
                }
            } catch (\JsonException $tagError) {
                $errors[] = 'Warning: unable to parse tags.json ('
                    . htmlspecialchars($tagError->getMessage(), ENT_QUOTES, 'UTF-8') . ')';
            }
        }
    }
}

$latestConfig = $config['status']['latest'] ?? [];
$latestEnabledConfigured = !array_key_exists('enabled', $latestConfig) || !empty($latestConfig['enabled']);
$latestSummary = null;
if ($latestEnabledConfigured) {
    $latestPath = rtrim($config['paths']['public'], '/') . '/status/latest.json';
    if (is_file($latestPath)) {
        $rawLatest = @file_get_contents($latestPath);
        if ($rawLatest !== false && trim($rawLatest) !== '') {
            try {
                $decodedLatest = json_decode($rawLatest, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decodedLatest)) {
                    $latestSummary = $decodedLatest;
                }
            } catch (\JsonException $latestError) {
                $errors[] = 'Warning: unable to parse latest.json ('
                    . htmlspecialchars($latestError->getMessage(), ENT_QUOTES, 'UTF-8') . ')';
            }
        }
    }
}

$backupsBasePath = isset($config['paths']['backups']) ? rtrim((string) $config['paths']['backups'], '/') : rtrim((string) $config['paths']['storage'], '/') . '/backups';
$backupsError = null;
$backupsSummary = null;
$backupsList = [];
$backupsSummaryByName = [];
try {
    $dashboardBackupService = new Backup($config['paths']);
    $backupsList = $dashboardBackupService->listBackups();
    $backupsSummary = BackupStatus::summarize($dashboardBackupService, $backupsList);
    if (isset($backupsSummary['backups']) && is_array($backupsSummary['backups'])) {
        foreach ($backupsSummary['backups'] as $entry) {
            if (isset($entry['name'])) {
                $backupsSummaryByName[(string) $entry['name']] = $entry;
            }
        }
    }
} catch (Throwable $backupDashboardError) {
    $backupsError = $backupDashboardError->getMessage();
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>RSS Admin</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; }
        h1 { border-bottom: 2px solid #333; padding-bottom: 10px; }
        .messages { background: #e8f5e9; border: 1px solid #81c784; padding: 10px; margin-bottom: 20px; }
        .errors { background: #ffebee; border: 1px solid #e57373; padding: 10px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f5f5f5; }
        .actions form { display: inline-block; margin-right: 6px; }
        label { display: block; margin-top: 10px; }
        input[type="text"], input[type="number"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; }
        .btn { padding: 8px 12px; background: #1976d2; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        .btn-delete { background: #c62828; }
        .btn:hover { opacity: 0.9; }
        form.add { border: 1px solid #ccc; padding: 15px; border-radius: 6px; background: #fafafa; }
        .snapshot { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 15px; }
        .snapshot div { background: #f0f4c3; border: 1px solid #dce775; padding: 10px 14px; border-radius: 6px; min-width: 160px; }
        .actions input[type="number"] { width: 90px; margin-bottom: 6px; }
        .actions .language-input { width: 90px; margin-bottom: 6px; }
        .actions .auth-input { width: 110px; margin-bottom: 6px; }
        .actions .headers-textarea { width: 160px; height: 90px; margin-bottom: 6px; }
        .actions .notes-textarea { width: 160px; height: 80px; margin-bottom: 6px; }
        .actions .language-lock { display: block; margin-bottom: 6px; }
        .notes-cell { max-width: 200px; vertical-align: top; }
        .notes-preview { white-space: pre-wrap; font-size: 0.9em; color: #333; }
        tr.paused td { background: #f3e5f5; }
        tr.private td { background: #fff3e0; }
        .muted { color: #666; font-size: 0.9em; }
        .status-ok { color: #2e7d32; font-weight: bold; }
        .status-warn { color: #f9a825; font-weight: bold; }
        .status-error { color: #c62828; font-weight: bold; }
        .test-results { border: 1px solid #ccc; padding: 16px; border-radius: 6px; background: #fafafa; margin-bottom: 25px; }
        .test-results h3 { margin-top: 18px; }
        .test-results ul, .test-results ol { margin-left: 20px; }
        .test-results table.test-metrics { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .test-results table.test-metrics th { text-align: left; padding: 6px 8px; background: #f5f5f5; width: 180px; }
        .test-results table.test-metrics td { padding: 6px 8px; border-bottom: 1px solid #e0e0e0; }
        .test-results ol li { margin-bottom: 12px; }
    </style>
</head>
<body>
    <h1>RSS Feed Administration</h1>

    <?php if (!empty($messages)) : ?>
        <div class="messages">
            <?php foreach ($messages as $msg) : ?>
                <div><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)) : ?>
        <div class="errors">
            <?php foreach ($errors as $err) : ?>
                <div><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($feedTestResult !== null) : ?>
        <?php
            $test = $feedTestResult;
            $statusLabel = strtoupper((string) ($test['status'] ?? 'unknown'));
            $httpLabel = isset($test['http_status']) ? (string) $test['http_status'] : 'n/a';
            $durationLabel = isset($test['duration_ms']) ? ((int) $test['duration_ms']) . ' ms' : 'n/a';
            $testedAtLabel = isset($test['tested_at']) ? gmdate('Y-m-d H:i:s', (int) $test['tested_at']) . ' UTC' : 'Unknown';
            $conditionalLabel = !empty($test['conditional_used']) ? 'Yes' : 'No';
            $itemCount = isset($test['item_count']) ? (int) $test['item_count'] : 0;
            $latestItem = isset($test['latest_item_ts']) && $test['latest_item_ts'] !== null
                ? gmdate('Y-m-d H:i:s', (int) $test['latest_item_ts']) . ' UTC'
                : 'n/a';
            $earliestItem = isset($test['earliest_item_ts']) && $test['earliest_item_ts'] !== null
                ? gmdate('Y-m-d H:i:s', (int) $test['earliest_item_ts']) . ' UTC'
                : 'n/a';
            $feedTitle = isset($test['feed_title']) && $test['feed_title'] !== null
                ? (string) $test['feed_title']
                : 'Untitled';
            $storedStatus = isset($test['stored_status']) ? (string) $test['stored_status'] : 'unknown';
            $requestHeaders = isset($test['request_headers']) && is_array($test['request_headers'])
                ? $test['request_headers']
                : [];
            $responseHeaders = isset($test['headers']) && is_array($test['headers'])
                ? $test['headers']
                : [];
            $effectiveResponseUrl = null;
            if (isset($responseHeaders['effective_url']) && $responseHeaders['effective_url'] !== null && $responseHeaders['effective_url'] !== '') {
                $effectiveResponseUrl = (string) $responseHeaders['effective_url'];
                unset($responseHeaders['effective_url']);
            }
            $redirectChain = null;
            if (isset($responseHeaders['redirect_chain']) && $responseHeaders['redirect_chain'] !== null && $responseHeaders['redirect_chain'] !== '') {
                $redirectChain = (string) $responseHeaders['redirect_chain'];
                unset($responseHeaders['redirect_chain']);
            }
            $redirectCount = null;
            if (array_key_exists('redirect_count', $responseHeaders) && $responseHeaders['redirect_count'] !== null) {
                $redirectCount = (int) $responseHeaders['redirect_count'];
                unset($responseHeaders['redirect_count']);
            }
            $samples = isset($test['samples']) && is_array($test['samples']) ? $test['samples'] : [];
            $parserErrors = isset($test['parser_errors']) && is_array($test['parser_errors']) ? $test['parser_errors'] : [];
            $testError = isset($test['error']) ? (string) $test['error'] : null;
        ?>
        <section class="test-results">
            <h2>Feed Test Result</h2>
            <p>
                <strong>Status:</strong> <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                | <strong>HTTP:</strong> <?= htmlspecialchars($httpLabel, ENT_QUOTES, 'UTF-8') ?>
                | <strong>Duration:</strong> <?= htmlspecialchars($durationLabel, ENT_QUOTES, 'UTF-8') ?>
                | <strong>Conditional headers:</strong> <?= htmlspecialchars($conditionalLabel, ENT_QUOTES, 'UTF-8') ?>
                | <strong>Tested:</strong> <?= htmlspecialchars($testedAtLabel, ENT_QUOTES, 'UTF-8') ?>
            </p>
            <table class="test-metrics">
                <tr>
                    <th>Feed ID</th>
                    <td><?= htmlspecialchars((string) ($test['feed_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>Feed Title</th>
                    <td><?= htmlspecialchars($feedTitle, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>Stored Status</th>
                    <td><?= htmlspecialchars($storedStatus, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>URL</th>
                    <td>
                        <?php if (!empty($test['url'])) : ?>
                            <a href="<?= htmlspecialchars((string) $test['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                <?= htmlspecialchars((string) $test['url'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php else : ?>
                            <span class="muted">Unknown</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($effectiveResponseUrl !== null) : ?>
                <tr>
                    <th>Effective URL</th>
                    <td>
                        <a href="<?= htmlspecialchars($effectiveResponseUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                            <?= htmlspecialchars($effectiveResponseUrl, ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Items Returned</th>
                    <td><?= $itemCount ?></td>
                </tr>
                <tr>
                    <th>Newest Item</th>
                    <td><?= htmlspecialchars($latestItem, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>Oldest Item</th>
                    <td><?= htmlspecialchars($earliestItem, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php if ($redirectCount !== null || $redirectChain !== null) : ?>
                <tr>
                    <th>Redirects</th>
                    <td>
                        <?php if ($redirectCount !== null) : ?>
                            <strong><?= (int) $redirectCount ?></strong> total<?php if ($redirectCount === 0) : ?> (no redirects)<?php endif; ?>
                        <?php endif; ?>
                        <?php if ($redirectChain !== null) : ?>
                            <br><span class="muted">Chain: <?= htmlspecialchars($redirectChain, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <h3>Request Headers</h3>
            <?php if ($requestHeaders !== []) : ?>
                <ul>
                    <?php foreach ($requestHeaders as $name => $value) : ?>
                        <li><strong><?= htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p class="muted">No additional request headers were sent.</p>
            <?php endif; ?>

            <h3>Response Headers</h3>
            <?php if ($responseHeaders !== []) : ?>
                <ul>
                    <?php foreach ($responseHeaders as $name => $value) : ?>
                        <?php if ($value === null || $value === '') { continue; } ?>
                        <li><strong><?= htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p class="muted">No response headers were available.</p>
            <?php endif; ?>

            <?php if ($testError !== null) : ?>
                <p class="errors"><strong>HTTP Error:</strong> <?= htmlspecialchars($testError, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <?php if ($parserErrors !== []) : ?>
                <div class="errors">
                    <?php foreach ($parserErrors as $parserError) : ?>
                        <div>Parser notice: <?= htmlspecialchars((string) $parserError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3>Sample Items</h3>
            <?php if ($samples !== []) : ?>
                <ol>
                    <?php foreach ($samples as $sample) : ?>
                        <li>
                            <strong><?= htmlspecialchars((string) ($sample['title'] ?? '[untitled]'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if (!empty($sample['url'])) : ?>
                                <br>
                                <a href="<?= htmlspecialchars((string) $sample['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Visit link</a>
                            <?php endif; ?>
                            <?php if (isset($sample['published_ts'])) : ?>
                                <br>
                                <span class="muted">Published <?= htmlspecialchars(gmdate('Y-m-d H:i:s', (int) $sample['published_ts']) . ' UTC', ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <?php if (!empty($sample['summary_text'])) : ?>
                                <p><?= htmlspecialchars((string) $sample['summary_text'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                            <?php if (!empty($sample['tags']) && is_array($sample['tags'])) : ?>
                                <p class="muted">Tags: <?= htmlspecialchars(implode(', ', array_map('strval', $sample['tags'])), ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php else : ?>
                <p class="muted">No sample items were available in this response.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section>
        <h2>Add Feed</h2>
        <form method="post" class="add">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="add">
            <label>Feed URL
                <input type="text" name="url" required>
            </label>
            <label>Title (optional)
                <input type="text" name="title">
            </label>
            <label>Fetch Interval (seconds)
                <input type="number" name="fetch_interval" min="300" value="900">
            </label>
            <label>Language (optional, e.g. en-US)
                <input type="text" name="language" maxlength="20" placeholder="en-US">
            </label>
            <p class="muted">Leave blank to enable automatic language detection during imports.</p>
            <label>HTTP Basic Username (optional)
                <input type="text" name="http_username" autocomplete="off">
            </label>
            <label>HTTP Basic Password (optional)
                <input type="password" name="http_password" autocomplete="new-password">
            </label>
            <p class="muted">Only provide credentials for feeds that require HTTP Basic authentication. Values are stored inside the protected storage directory.</p>
            <label>Custom HTTP Headers (optional, one per line as &quot;Name: Value&quot;)
                <textarea name="http_headers" rows="3" placeholder="X-Api-Key: secret"></textarea>
            </label>
            <p class="muted">Headers override global defaults for this feed and remain inside the protected storage directory.</p>
            <label>Operator Notes (optional)
                <textarea name="notes" rows="3" placeholder="Internal context for this feed"></textarea>
            </label>
            <p class="muted">Notes are stored privately and surface only in the dashboard, CLI summaries, and backups.</p>
            <label>
                <input type="checkbox" name="is_private" value="1"> Mark as private (exclude from public publishing)
            </label>
            <p class="muted">Private feeds are still imported and verified but are removed from combined feeds, OPML exports, and published JSON/RSS/Atom files.</p>
            <button type="submit" class="btn">Add Feed</button>
        </form>
    </section>

    <section>
        <h2>Import &amp; Export OPML</h2>
        <p>
            Download the latest subscription list:
            <a href="../public/status/subscriptions.opml" target="_blank" rel="noopener">
                subscriptions.opml
            </a>
            (served from <code>public/status/subscriptions.opml</code>).
        </p>
        <form method="post" enctype="multipart/form-data" class="add">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="import_opml">
            <label>Import OPML file (max 512KB)
                <input type="file" name="opml_file" accept=".opml,application/xml,text/xml" required>
            </label>
            <button type="submit" class="btn">Import Feeds</button>
        </form>
    </section>

    <section>
        <h2>System Snapshot</h2>
        <div class="snapshot">
            <div><strong>Total Feeds:</strong> <?= (int) $totals['feeds'] ?></div>
            <div><strong>Public Feeds:</strong> <?= (int) $totals['feedsPublic'] ?></div>
            <div><strong>Private Feeds:</strong> <?= (int) $totals['feedsPrivate'] ?></div>
            <div><strong>Feeds Overdue:</strong> <?= (int) $totals['feedsOverdue'] ?></div>
            <div><strong>Feeds in Backoff:</strong> <?= (int) $totals['feedsBackoff'] ?></div>
            <div><strong>Feeds With Errors:</strong> <?= (int) $totals['feedsWithErrors'] ?></div>
            <div><strong>Max Error Streak:</strong> <?= (int) $totals['maxErrorStreak'] ?></div>
            <div><strong>Feeds with HTTP Auth:</strong> <?= (int) $totals['feedsWithAuth'] ?></div>
            <div><strong>Feeds with Custom Headers:</strong> <?= (int) $totals['feedsWithHeaders'] ?></div>
            <div><strong>Feeds with Notes:</strong> <?= (int) $totals['feedsWithNotes'] ?></div>
            <div><strong>Paused Feeds:</strong> <?= (int) $totals['feedsPaused'] ?></div>
            <div><strong>Active Items:</strong> <?= (int) $totals['itemsActive'] ?></div>
            <div><strong>Dead Items:</strong> <?= (int) $totals['itemsDead'] ?></div>
            <div><strong>Avg Items/Feed:</strong> <?= number_format((float) $totals['itemsAverage'], 2) ?></div>
            <div><strong>Languages Detected:</strong> <?= (int) $totals['languagesUnique'] ?></div>
            <div><strong>Top Language:</strong> <?= htmlspecialchars((string) $totals['languagesTop'], ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong>Language Locks:</strong> <?= (int) $totals['languagesLocked'] ?></div>
            <div><strong>Newest Item (UTC):</strong> <?= htmlspecialchars((string) $totals['latestItemDisplay'], ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong>Oldest Item (UTC):</strong> <?= htmlspecialchars((string) $totals['oldestItemDisplay'], ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong>Storage Path:</strong> <?= htmlspecialchars((string) $totals['diskPath'], ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong>Disk Status:</strong> <?= htmlspecialchars(strtoupper((string) $totals['diskStatus']), ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong>Disk Free:</strong> <?= htmlspecialchars((string) $totals['diskFree'], ENT_QUOTES, 'UTF-8') ?> of <?= htmlspecialchars((string) $totals['diskTotal'], ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong>Disk Free %:</strong> <?= $totals['diskFreePercent'] !== null ? number_format((float) $totals['diskFreePercent'], 2) . '%' : '—' ?></div>
            <div><strong>Disk Used %:</strong> <?= $totals['diskUsedPercent'] !== null ? number_format((float) $totals['diskUsedPercent'], 2) . '%' : '—' ?></div>
            <div><strong>Email Alerts:</strong> <?= $alertsEnabled ? 'Enabled' : 'Disabled' ?></div>
            <?php if ($alertsEnabled) : ?>
                <div><strong>Alert Recipient:</strong> <?= htmlspecialchars((string) $alertsRecipient, ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Active Alerts:</strong> <?= (int) $alertsActive ?></div>
                <div><strong>Last Alert (UTC):</strong> <?= htmlspecialchars($alertsLastDisplay, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
        <p>Latest machine-readable snapshots are published at <code>/public/status/health.json</code> and <code>/public/status/feeds.json</code>.</p>
    </section>

    <section>
        <h2>Backups</h2>
        <?php
            $backupTotal = isset($backupsSummary['total']) ? (int) $backupsSummary['total'] : count($backupsList);
            $backupWithLogs = isset($backupsSummary['with_logs']) ? (int) $backupsSummary['with_logs'] : 0;
            $backupWithStatus = isset($backupsSummary['with_status']) ? (int) $backupsSummary['with_status'] : 0;
            $backupGeneratedAt = isset($backupsSummary['generated_at']) ? (int) $backupsSummary['generated_at'] : null;
            $latestBackup = isset($backupsSummary['latest']) && is_array($backupsSummary['latest']) ? $backupsSummary['latest'] : null;
            $oldestBackup = isset($backupsSummary['oldest']) && is_array($backupsSummary['oldest']) ? $backupsSummary['oldest'] : null;
            $lastRestore = isset($backupsSummary['last_restore']) && is_array($backupsSummary['last_restore']) ? $backupsSummary['last_restore'] : null;
            $lastRestoreAt = $lastRestore && isset($lastRestore['restored_at']) && $lastRestore['restored_at'] !== null ? (int) $lastRestore['restored_at'] : null;
            $lastRestoreMode = $lastRestore && isset($lastRestore['mode']) ? (string) $lastRestore['mode'] : null;
            $lastRestoreName = $lastRestore && isset($lastRestore['name']) ? (string) $lastRestore['name'] : null;
            $lastRestoreTarget = $lastRestore && isset($lastRestore['target']) ? (string) $lastRestore['target'] : null;
            $lastRestoreCopied = [];
            if ($lastRestore && isset($lastRestore['copied']) && is_array($lastRestore['copied'])) {
                foreach ($lastRestore['copied'] as $copiedEntry) {
                    if (is_string($copiedEntry) && $copiedEntry !== '') {
                        $lastRestoreCopied[] = $copiedEntry;
                    }
                }
            }
            $lastRestoreWarnings = [];
            if ($lastRestore && isset($lastRestore['warnings']) && is_array($lastRestore['warnings'])) {
                foreach ($lastRestore['warnings'] as $warningEntry) {
                    if (is_string($warningEntry) && $warningEntry !== '') {
                        $lastRestoreWarnings[] = $warningEntry;
                    }
                }
            }
            $formatBytes = static function (?int $bytes): string {
                if ($bytes === null) {
                    return '—';
                }
                if ($bytes < 1024) {
                    return $bytes . ' B';
                }
                $units = ['KB', 'MB', 'GB', 'TB'];
                $value = (float) $bytes;
                foreach ($units as $unit) {
                    $value /= 1024;
                    if ($value < 1024) {
                        return sprintf('%.2f %s', $value, $unit);
                    }
                }
                return sprintf('%.2f PB', $value / 1024);
            };
        ?>
        <?php if ($backupsError !== null) : ?>
            <div class="errors">Unable to load backups: <?= htmlspecialchars($backupsError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php else : ?>
            <div class="snapshot">
                <div><strong>Total Backups:</strong> <?= (int) $backupTotal ?></div>
                <div><strong>With Logs:</strong> <?= (int) $backupWithLogs ?></div>
                <div><strong>With Status:</strong> <?= (int) $backupWithStatus ?></div>
                <div><strong>Latest Name:</strong> <?= $latestBackup && isset($latestBackup['name']) ? htmlspecialchars((string) $latestBackup['name'], ENT_QUOTES, 'UTF-8') : '—' ?></div>
                <div><strong>Latest Created (UTC):</strong> <?= $latestBackup && isset($latestBackup['created_at']) && $latestBackup['created_at'] !== null ? htmlspecialchars(gmdate('Y-m-d H:i', (int) $latestBackup['created_at']), ENT_QUOTES, 'UTF-8') : '—' ?></div>
                <div><strong>Oldest Name:</strong> <?= $oldestBackup && isset($oldestBackup['name']) ? htmlspecialchars((string) $oldestBackup['name'], ENT_QUOTES, 'UTF-8') : '—' ?></div>
                <div><strong>Oldest Created (UTC):</strong> <?= $oldestBackup && isset($oldestBackup['created_at']) && $oldestBackup['created_at'] !== null ? htmlspecialchars(gmdate('Y-m-d H:i', (int) $oldestBackup['created_at']), ENT_QUOTES, 'UTF-8') : '—' ?></div>
                <div><strong>Summary Generated:</strong> <?= $backupGeneratedAt !== null ? htmlspecialchars(gmdate('Y-m-d H:i', $backupGeneratedAt) . ' UTC', ENT_QUOTES, 'UTF-8') : '—' ?></div>
                <div><strong>Backup Path:</strong> <?= htmlspecialchars($backupsBasePath, ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Last Restore:</strong> <?= $lastRestoreName !== null ? htmlspecialchars($lastRestoreName, ENT_QUOTES, 'UTF-8') : '—' ?></div>
                <div><strong>Restore Mode:</strong> <?= $lastRestoreMode !== null ? htmlspecialchars($lastRestoreMode, ENT_QUOTES, 'UTF-8') : '—' ?></div>
                <div><strong>Restore Time (UTC):</strong> <?= $lastRestoreAt !== null ? htmlspecialchars(gmdate('Y-m-d H:i', $lastRestoreAt), ENT_QUOTES, 'UTF-8') : '—' ?></div>
                <div><strong>Restore Target:</strong> <?= $lastRestoreTarget !== null ? htmlspecialchars($lastRestoreTarget, ENT_QUOTES, 'UTF-8') : '—' ?></div>
                <div><strong>Restore Copied:</strong> <?= !empty($lastRestoreCopied) ? htmlspecialchars(implode(', ', $lastRestoreCopied), ENT_QUOTES, 'UTF-8') : '—' ?></div>
            </div>

            <?php if (!empty($lastRestoreWarnings)) : ?>
                <div class="warnings">
                    <strong>Last Restore Warnings:</strong>
                    <ul>
                        <?php foreach ($lastRestoreWarnings as $warning) : ?>
                            <li><?= htmlspecialchars($warning, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($backupsList)) : ?>
                <p class="muted">No filesystem backups have been created yet. Run <code>php app/cli/backup.php</code> to capture a snapshot.</p>
            <?php else : ?>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Label</th>
                            <th>Created (UTC)</th>
                            <th>Size</th>
                            <th>Files</th>
                            <th>Dirs</th>
                            <th>Logs?</th>
                            <th>Status Files?</th>
                            <th>Path</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backupsList as $backupEntry) : ?>
                            <?php
                                $name = isset($backupEntry['name']) ? (string) $backupEntry['name'] : '';
                                $manifest = isset($backupEntry['manifest']) && is_array($backupEntry['manifest']) ? $backupEntry['manifest'] : [];
                                $stats = isset($manifest['stats']) && is_array($manifest['stats']) ? $manifest['stats'] : [];
                                $summaryEntry = $backupsSummaryByName[$name] ?? null;
                                $createdAt = null;
                                if ($summaryEntry !== null && isset($summaryEntry['created_at'])) {
                                    $createdAt = $summaryEntry['created_at'] !== null ? (int) $summaryEntry['created_at'] : null;
                                } elseif (isset($backupEntry['created_at']) && $backupEntry['created_at'] !== null) {
                                    $createdAt = (int) $backupEntry['created_at'];
                                }
                                $includeLogs = $summaryEntry !== null ? !empty($summaryEntry['include_logs']) : (!empty($manifest['options']['include_logs'] ?? false));
                                $includeStatus = $summaryEntry !== null ? !empty($summaryEntry['include_public_status']) : (!empty($manifest['options']['include_public_status'] ?? false));
                                $sizeBytes = isset($summaryEntry['size_bytes']) ? $summaryEntry['size_bytes'] : (isset($stats['bytes']) ? (int) $stats['bytes'] : null);
                                $filesCount = isset($summaryEntry['files']) && $summaryEntry['files'] !== null ? (int) $summaryEntry['files'] : (isset($stats['files']) ? (int) $stats['files'] : null);
                                $dirsCount = isset($summaryEntry['directories']) && $summaryEntry['directories'] !== null ? (int) $summaryEntry['directories'] : (isset($stats['directories']) ? (int) $stats['directories'] : null);
                                $relativePath = $name !== '' ? $name : basename((string) ($backupEntry['path'] ?? ''));
                                if (isset($backupEntry['path']) && is_string($backupEntry['path'])) {
                                    $pathString = (string) $backupEntry['path'];
                                    if (str_starts_with($pathString, $backupsBasePath . '/')) {
                                        $relativePath = substr($pathString, strlen($backupsBasePath) + 1);
                                    }
                                }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= isset($manifest['label']) && $manifest['label'] !== '' ? htmlspecialchars((string) $manifest['label'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                <td><?= $createdAt !== null ? htmlspecialchars(gmdate('Y-m-d H:i', $createdAt) . ' UTC', ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                <td><?= htmlspecialchars($formatBytes($sizeBytes !== null ? (int) $sizeBytes : null), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $filesCount !== null ? (int) $filesCount : '—' ?></td>
                                <td><?= $dirsCount !== null ? (int) $dirsCount : '—' ?></td>
                                <td><?= $includeLogs ? 'Yes' : 'No' ?></td>
                                <td><?= $includeStatus ? 'Yes' : 'No' ?></td>
                                <td><code><?= htmlspecialchars($relativePath, ENT_QUOTES, 'UTF-8') ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
        <p class="muted">Backup summaries are exported to <code>public/status/backups.json</code> for automation. Use <code>php app/cli/backup.php --help</code> to manage snapshots, <code>php app/cli/restore_backup.php --help</code> to extract or restore snapshots, or configure automatic pruning in <code>app/config.php</code>.</p>
    </section>

    <section>
        <h2>Latest Items</h2>
        <?php if (!$latestEnabledConfigured) : ?>
            <p>Latest snapshots are disabled. Enable <code>status.latest.enabled</code> in <code>app/config.php</code> to publish <code>public/status/latest.json</code>.</p>
        <?php else : ?>
            <?php
            $latestSummaryData = is_array($latestSummary) ? $latestSummary : [];
            $latestItems = [];
            if (isset($latestSummaryData['items']) && is_array($latestSummaryData['items'])) {
                $latestItems = $latestSummaryData['items'];
            }
            $latestLookback = isset($latestSummaryData['lookback_days'])
                ? (int) $latestSummaryData['lookback_days']
                : (int) ($latestConfig['lookback_days'] ?? 14);
            $latestFeedsCount = isset($latestSummaryData['feeds_with_items'])
                ? (int) $latestSummaryData['feeds_with_items']
                : 0;
            $latestItemsCount = isset($latestSummaryData['items_written'])
                ? (int) $latestSummaryData['items_written']
                : count($latestItems);
            $latestTruncated = isset($latestSummaryData['truncated'])
                ? (int) $latestSummaryData['truncated']
                : 0;
            $latestGenerated = isset($latestSummaryData['generated_at'])
                ? (int) $latestSummaryData['generated_at']
                : null;
            $latestNewest = isset($latestSummaryData['newest_item_ts']) && $latestSummaryData['newest_item_ts'] !== null
                ? (int) $latestSummaryData['newest_item_ts']
                : null;
            $latestOldest = isset($latestSummaryData['oldest_item_ts']) && $latestSummaryData['oldest_item_ts'] !== null
                ? (int) $latestSummaryData['oldest_item_ts']
                : null;
            $latestIncludeDead = array_key_exists('include_dead', $latestSummaryData)
                ? !empty($latestSummaryData['include_dead'])
                : !empty($latestConfig['include_dead']);
            $latestIncludePrivate = array_key_exists('include_private', $latestSummaryData)
                ? !empty($latestSummaryData['include_private'])
                : !empty($latestConfig['include_private']);
            $latestMaxItems = isset($latestSummaryData['max_items'])
                ? (int) $latestSummaryData['max_items']
                : (int) ($latestConfig['max_items'] ?? 200);
            $latestMaxPerFeed = isset($latestSummaryData['max_items_per_feed'])
                ? (int) $latestSummaryData['max_items_per_feed']
                : (int) ($latestConfig['max_items_per_feed'] ?? 40);
            $latestItemsTable = array_slice($latestItems, 0, 20);
            $latestSummaryParts = [];
            if ($latestGenerated !== null) {
                $latestSummaryParts[] = 'Generated ' . gmdate('Y-m-d H:i', $latestGenerated) . ' UTC';
            }
            $latestSummaryParts[] = 'Captured ' . $latestItemsCount . ' item' . ($latestItemsCount === 1 ? '' : 's')
                . ' from ' . $latestFeedsCount . ' feed' . ($latestFeedsCount === 1 ? '' : 's');
            if ($latestLookback > 0) {
                $latestSummaryParts[] = 'Lookback ' . $latestLookback . ' day' . ($latestLookback === 1 ? '' : 's');
            } else {
                $latestSummaryParts[] = 'Lookback unlimited';
            }
            $latestSummaryParts[] = 'Per-feed cap ' . $latestMaxPerFeed . ' (global ' . $latestMaxItems . ')';
            $latestSummaryParts[] = $latestIncludeDead ? 'Dead items included' : 'Dead items excluded';
            $latestSummaryParts[] = $latestIncludePrivate ? 'Private feeds included' : 'Private feeds hidden';
            $latestSummaryText = implode(' • ', $latestSummaryParts);
            ?>
            <?php if ($latestSummary === null) : ?>
                <p>No latest-item snapshot is available yet. Run the publisher to generate <code>public/status/latest.json</code>.</p>
            <?php elseif (empty($latestItemsTable)) : ?>
                <p>No recent items matched the configured criteria<?= $latestLookback > 0 ? ' in the last ' . (int) $latestLookback . ' day' . ($latestLookback === 1 ? '' : 's') : '' ?>.</p>
            <?php else : ?>
                <p><?= htmlspecialchars($latestSummaryText, ENT_QUOTES, 'UTF-8') ?></p>
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Feed</th>
                            <th>Effective (UTC)</th>
                            <th>Published (UTC)</th>
                            <th>Dead?</th>
                            <th>Link</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($latestItemsTable as $entry) : ?>
                            <?php
                            $entry = is_array($entry) ? $entry : [];
                            $itemTitle = isset($entry['title']) ? trim((string) $entry['title']) : '';
                            if ($itemTitle === '') {
                                $itemTitle = '(untitled)';
                            }
                            $itemSummary = isset($entry['summary_text']) ? (string) $entry['summary_text'] : '';
                            if ($itemSummary !== '' && mb_strlen($itemSummary) > 160) {
                                $itemSummary = mb_substr($itemSummary, 0, 160) . '…';
                            }
                            $itemTags = [];
                            if (isset($entry['tags']) && is_array($entry['tags'])) {
                                foreach ($entry['tags'] as $tagValue) {
                                    if (is_string($tagValue)) {
                                        $tag = trim($tagValue);
                                        if ($tag !== '') {
                                            $itemTags[] = $tag;
                                        }
                                    }
                                }
                            }
                            $feedTitle = isset($entry['feed_title']) && $entry['feed_title'] !== null
                                ? trim((string) $entry['feed_title'])
                                : '';
                            $feedId = isset($entry['feed_id']) ? (string) $entry['feed_id'] : '';
                            $feedLabel = $feedTitle !== '' ? $feedTitle : $feedId;
                            $feedUrl = isset($entry['feed_url']) ? (string) $entry['feed_url'] : '';
                            $effectiveTs = isset($entry['effective_ts']) ? (int) $entry['effective_ts'] : 0;
                            $publishedTs = isset($entry['published_ts']) && $entry['published_ts'] !== null
                                ? (int) $entry['published_ts']
                                : 0;
                            $isDead = !empty($entry['is_dead']);
                            $url = isset($entry['url']) ? trim((string) $entry['url']) : '';
                            $linkHost = '';
                            if ($url !== '') {
                                $parsedUrl = @parse_url($url);
                                if (is_array($parsedUrl) && isset($parsedUrl['host'])) {
                                    $linkHost = (string) $parsedUrl['host'];
                                }
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if ($itemSummary !== '') : ?>
                                        <div class="muted"><?= htmlspecialchars($itemSummary, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($itemTags)) : ?>
                                        <div class="muted">Tags: <?= htmlspecialchars(implode(', ', $itemTags), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($feedLabel !== '' ? $feedLabel : '—', ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ($feedId !== '' && $feedLabel !== $feedId) : ?>
                                        <div class="muted">ID: <?= htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if ($feedUrl !== '') : ?>
                                        <div class="muted"><a href="<?= htmlspecialchars($feedUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Feed</a></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $effectiveTs > 0 ? htmlspecialchars(gmdate('Y-m-d H:i', $effectiveTs), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                <td><?= $publishedTs > 0 ? htmlspecialchars(gmdate('Y-m-d H:i', $publishedTs), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                <td><?= $isDead ? 'Yes' : 'No' ?></td>
                                <td>
                                    <?php if ($url !== '') : ?>
                                        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                            <?= htmlspecialchars($linkHost !== '' ? $linkHost : $url, ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($latestNewest !== null || $latestOldest !== null) : ?>
                    <p class="muted">
                        <?php if ($latestNewest !== null) : ?>Newest item <?= htmlspecialchars(gmdate('Y-m-d H:i', $latestNewest), ENT_QUOTES, 'UTF-8') ?> UTC<?php endif; ?>
                        <?php if ($latestNewest !== null && $latestOldest !== null) : ?> &middot; <?php endif; ?>
                        <?php if ($latestOldest !== null) : ?>Oldest item <?= htmlspecialchars(gmdate('Y-m-d H:i', $latestOldest), ENT_QUOTES, 'UTF-8') ?> UTC<?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if ($latestTruncated > 0) : ?>
                    <p class="muted">Plus <?= (int) $latestTruncated ?> additional item<?= $latestTruncated === 1 ? '' : 's' ?> not shown due to the configured limit.</p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section>
        <h2>Trending Tags</h2>
        <?php if (!$tagsEnabledConfigured) : ?>
            <p>Tag summaries are disabled. Enable <code>metrics.tags.enabled</code> in <code>app/config.php</code> to publish <code>public/status/tags.json</code>.</p>
        <?php else : ?>
            <?php
            $tagEntries = [];
            if ($tagSummary !== null && isset($tagSummary['tags']) && is_array($tagSummary['tags'])) {
                $tagEntries = $tagSummary['tags'];
            }
            $tagLookback = isset($tagSummary['lookback_days']) ? (int) $tagSummary['lookback_days'] : (int) ($tagsConfig['lookback_days'] ?? 30);
            $tagFeedsConsidered = isset($tagSummary['feeds_considered']) ? (int) $tagSummary['feeds_considered'] : 0;
            $tagItemsConsidered = isset($tagSummary['items_considered']) ? (int) $tagSummary['items_considered'] : 0;
            $tagTruncatedCount = isset($tagSummary['truncated_tags']) ? (int) $tagSummary['truncated_tags'] : 0;
            $tagTruncatedItems = isset($tagSummary['truncated_count']) ? (int) $tagSummary['truncated_count'] : 0;
            ?>
            <?php if ($tagSummary === null) : ?>
                <p>No tag snapshot is available yet. Run the publisher to generate <code>public/status/tags.json</code>.</p>
            <?php elseif (empty($tagEntries)) : ?>
                <p>No tags observed in the last <?= (int) $tagLookback ?> day<?= $tagLookback === 1 ? '' : 's' ?> across the analyzed feeds.</p>
            <?php else : ?>
                <p>Analyzed <?= (int) $tagItemsConsidered ?> items from <?= (int) $tagFeedsConsidered ?> feed<?= $tagFeedsConsidered === 1 ? '' : 's' ?> over the last <?= (int) $tagLookback ?> day<?= $tagLookback === 1 ? '' : 's' ?>.</p>
                <table>
                    <thead>
                        <tr>
                            <th>Tag</th>
                            <th>Items</th>
                            <th>Feeds</th>
                            <th>Latest Item (UTC)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tagEntries as $entry) : ?>
                            <?php $latestTs = isset($entry['latest_item_ts']) ? (int) $entry['latest_item_ts'] : 0; ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($entry['tag'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) ($entry['count'] ?? 0) ?></td>
                                <td><?= (int) ($entry['feed_count'] ?? 0) ?></td>
                                <td><?= $latestTs > 0 ? htmlspecialchars(gmdate('Y-m-d H:i', $latestTs), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($tagTruncatedCount > 0) : ?>
                    <p class="muted">Plus <?= $tagTruncatedCount ?> additional tag<?= $tagTruncatedCount === 1 ? '' : 's' ?> not shown (<?= $tagTruncatedItems ?> total items).</p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section>
        <h2>Metrics History</h2>
        <p>Historical snapshots are recorded in <code>storage/index/metrics_history.jsonl</code> for trend analysis.</p>
        <?php if (empty($metricsHistory)) : ?>
            <p>No history has been recorded yet. Run an importer or publisher job to generate the first snapshot.</p>
        <?php else : ?>
            <table>
                <thead>
                    <tr>
                        <th>Recorded (UTC)</th>
                        <th>Total Feeds</th>
                        <th>Errors</th>
                        <th>Backoff</th>
                        <th>Private</th>
                        <th>Paused</th>
                        <th>HTTP Auth</th>
                        <th>Headers</th>
                        <th>Notes</th>
                        <th>Active Items</th>
                        <th>Dead Items</th>
                        <th>Alerts Active</th>
                        <th>Top Language</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($metricsHistory as $entry) : ?>
                        <?php
                        $feedsData = isset($entry['feeds']) && is_array($entry['feeds']) ? $entry['feeds'] : [];
                        $itemsData = isset($entry['items']) && is_array($entry['items']) ? $entry['items'] : [];
                        $alertsData = isset($entry['alerts']) && is_array($entry['alerts']) ? $entry['alerts'] : [];
                        $languagesData = isset($entry['languages']) && is_array($entry['languages']) ? $entry['languages'] : [];
                        $recordedTs = isset($entry['recorded_at']) ? (int) $entry['recorded_at'] : (int) ($entry['generated_at'] ?? 0);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($recordedTs > 0 ? gmdate('Y-m-d H:i', $recordedTs) : '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int) ($feedsData['total'] ?? 0) ?></td>
                            <td><?= (int) ($feedsData['errors'] ?? 0) ?></td>
                            <td><?= (int) ($feedsData['in_backoff'] ?? 0) ?></td>
                            <td><?= (int) ($feedsData['private'] ?? 0) ?></td>
                            <td><?= (int) ($feedsData['paused'] ?? 0) ?></td>
                            <td><?= (int) ($feedsData['with_http_auth'] ?? 0) ?></td>
                            <td><?= (int) ($feedsData['with_custom_headers'] ?? 0) ?></td>
                            <td><?= (int) ($feedsData['with_notes'] ?? 0) ?></td>
                            <td><?= (int) ($itemsData['active'] ?? 0) ?></td>
                            <td><?= (int) ($itemsData['dead'] ?? 0) ?></td>
                            <td><?= !empty($alertsData['enabled']) ? (int) ($alertsData['active'] ?? 0) : 0 ?></td>
                            <td><?= htmlspecialchars((string) ($languagesData['most_common'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section>
        <h2>Recent Alert Activity</h2>
        <?php if (!$alertsEnabled) : ?>
            <p>Email alerts are currently disabled. Enable alerts in <code>app/config.php</code> to receive notifications.</p>
        <?php elseif (empty($alertsHistory)) : ?>
            <p>No alert emails have been sent yet.</p>
        <?php else : ?>
            <table>
                <thead>
                    <tr>
                        <th>Feed ID</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Sent (UTC)</th>
                        <th>Subject</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alertsHistory as $entry) : ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($entry['feed_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(strtoupper((string) ($entry['type'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($entry['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(gmdate('Y-m-d H:i', (int) ($entry['sent_ts'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($entry['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section>
        <h2>Environment Diagnostics</h2>
        <p>
            Checks completed: <?= (int) ($diagnosticSummary['ok'] + $diagnosticSummary['warn'] + $diagnosticSummary['error']) ?> —
            <span class="status-ok">OK: <?= (int) $diagnosticSummary['ok'] ?></span>,
            <span class="status-warn">Warnings: <?= (int) $diagnosticSummary['warn'] ?></span>,
            <span class="status-error">Errors: <?= (int) $diagnosticSummary['error'] ?></span>.
            Run <code>php app/cli/diagnostics.php</code> for CLI output. Use <code>php app/cli/backup.php --help</code> to create or manage filesystem snapshots.
        </p>
        <table>
            <thead>
                <tr>
                    <th>Check</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($diagnostics as $check) : ?>
                    <?php
                    $status = isset($check['status']) ? strtolower((string) $check['status']) : 'ok';
                    $statusClass = 'status-' . $status;
                    $statusLabel = strtoupper($status);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($check['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($check['details'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section>
        <h2>Cron Job Status</h2>
        <table>
            <thead>
                <tr>
                    <th>Job</th>
                    <th>Status</th>
                    <th>Last Start (UTC)</th>
                    <th>Last Finish (UTC)</th>
                    <th>Duration</th>
                    <th>Message</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($jobs)) : ?>
                    <tr><td colspan="7">No job history yet.</td></tr>
                <?php else : ?>
                    <?php foreach ($jobs as $jobName => $info) : ?>
                        <?php
                        $contextParts = [];
                        if (isset($info['context']) && is_array($info['context'])) {
                            foreach ($info['context'] as $key => $value) {
                                if (is_scalar($value) || $value === null) {
                                    $contextParts[] = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                                }
                            }
                        }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $jobName, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($info['status'] ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= isset($info['last_started_at']) ? gmdate('Y-m-d H:i', (int) $info['last_started_at']) : 'Never' ?></td>
                            <td><?= isset($info['last_finished_at']) ? gmdate('Y-m-d H:i', (int) $info['last_finished_at']) : 'Never' ?></td>
                            <td><?= isset($info['duration_sec']) ? (int) $info['duration_sec'] . 's' : 'n/a' ?></td>
                            <td><?= htmlspecialchars((string) ($info['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= empty($contextParts) ? '-' : implode('<br>', $contextParts) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section>
        <h2>Existing Feeds</h2>
        <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Language</th>
                        <th>HTTP Auth</th>
                        <th>Headers</th>
                        <th>URL</th>
                        <th>Fetch Interval</th>
                        <th>Last Fetch</th>
                        <th>Next Fetch</th>
                        <th>Backoff</th>
                        <th>Private</th>
                        <th>Paused</th>
                        <th>Status</th>
                        <th>Error Streak</th>
                        <th>Items (live/dead)</th>
                        <th>Latest Item</th>
                        <th>Actions</th>
                    </tr>
            </thead>
            <tbody>
                <?php if (empty($feeds)) : ?>
                    <tr><td colspan="15">No feeds registered.</td></tr>
                <?php else : ?>
                    <?php foreach ($feeds as $row) : ?>
                        <?php
                        $summary = $summaryById[$row['id']] ?? [
                            'total_items' => 0,
                            'dead_items' => 0,
                            'next_fetch_ts' => null,
                            'latest_item_ts' => null,
                            'is_paused' => !empty($row['is_paused']),
                            'is_private' => !empty($row['is_private']),
                            'error_streak' => 0,
                            'success_streak' => 0,
                            'backoff_until_ts' => null,
                        ];
                        $totalItems = (int) ($summary['total_items'] ?? 0);
                        $deadItems = (int) ($summary['dead_items'] ?? 0);
                        $liveItems = max(0, $totalItems - $deadItems);
                        $nextFetchTs = $summary['next_fetch_ts'] ?? null;
                        $latestItemTs = $summary['latest_item_ts'] ?? null;
                        $isPaused = !empty($summary['is_paused']);
                        $isPrivate = !empty($summary['is_private']);
                        $nextFetchLabel = $isPaused
                            ? 'Paused'
                            : ($nextFetchTs ? gmdate('Y-m-d H:i', (int) $nextFetchTs) : 'Queued');
                        $backoffUntil = isset($summary['backoff_until_ts']) && $summary['backoff_until_ts'] !== null
                            ? (int) $summary['backoff_until_ts']
                            : null;
                        $backoffActive = $backoffUntil !== null && !$isPaused && $backoffUntil > time();
                        $backoffLabel = '—';
                        if ($backoffUntil !== null) {
                            $backoffLabel = ($backoffActive ? 'Active → ' : 'Expired → ') . gmdate('Y-m-d H:i', $backoffUntil);
                        }
                        $errorStreak = (int) ($summary['error_streak'] ?? 0);
                        $successStreak = (int) ($summary['success_streak'] ?? 0);
                        $authSummary = isset($summary['http_auth']) && is_array($summary['http_auth']) ? $summary['http_auth'] : null;
                        $hasAuth = !empty($summary['has_http_credentials']);
                        $headersArray = isset($row['http_headers']) && is_array($row['http_headers']) ? $row['http_headers'] : [];
                        $headersCount = is_array($headersArray) ? count($headersArray) : 0;
                        $headersNames = array_keys($headersArray);
                        $headersDisplayList = [];
                        foreach (array_slice($headersNames, 0, 3) as $headerName) {
                            $headerName = (string) $headerName;
                            $headersDisplayList[] = strlen($headerName) > 20 ? substr($headerName, 0, 20) . '…' : $headerName;
                        }
                        $headersTextarea = '';
                        if ($headersArray !== []) {
                            $lines = [];
                            foreach ($headersArray as $headerName => $headerValue) {
                                $lines[] = $headerName . ': ' . $headerValue;
                            }
                            $headersTextarea = implode("\n", $lines);
                        }
                        $rowClass = trim(($isPaused ? 'paused ' : '') . ($isPrivate ? 'private' : ''));
                        ?>
                        <tr class="<?= htmlspecialchars($rowClass, ENT_QUOTES, 'UTF-8') ?>">
                            <td><?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if (!empty($row['language'])) : ?>
                                    <?= htmlspecialchars((string) $row['language'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($row['language_locked'])) : ?>
                                        <span class="muted">(locked)</span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="muted">
                                        <?= !empty($row['language_locked']) ? 'Locked' : 'Auto' ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hasAuth && $authSummary !== null) : ?>
                                    <?= htmlspecialchars((string) ($authSummary['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($authSummary['has_password'])) : ?>
                                        <span class="muted">(+pw)</span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="muted">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($headersCount === 0) : ?>
                                    <span class="muted">None</span>
                                <?php else : ?>
                                    <?= htmlspecialchars((string) $headersCount, ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars(implode(', ', $headersDisplayList), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ($headersCount > count($headersDisplayList)) : ?>
                                        <span class="muted">+<?= $headersCount - count($headersDisplayList) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?= htmlspecialchars($row['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Visit</a></td>
                            <td><?= (int) $row['fetch_interval_sec'] ?>s</td>
                            <td><?= $row['last_fetch_ts'] ? gmdate('Y-m-d H:i', (int) $row['last_fetch_ts']) : 'Never' ?></td>
                            <td><?= $nextFetchLabel ?></td>
                            <td><?= htmlspecialchars($backoffLabel, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $isPrivate ? 'Yes' : 'No' ?></td>
                            <td><?= $isPaused ? 'Yes' : 'No' ?></td>
                            <td><?= htmlspecialchars($row['status'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td title="Success streak: <?= (int) $successStreak ?>"><?= (int) $errorStreak ?></td>
                            <td><?= $liveItems ?> / <?= $deadItems ?></td>
                            <td><?= $latestItemTs ? gmdate('Y-m-d H:i', (int) $latestItemTs) : 'n/a' ?></td>
                            <td class="notes-cell">
                                <?php if (!empty($row['notes'])) : ?>
                                    <div class="notes-preview"><?= nl2br(htmlspecialchars((string) $row['notes'], ENT_QUOTES, 'UTF-8')) ?></div>
                                <?php else : ?>
                                    <span class="muted">None</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update_language">
                                    <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="text" name="language" value="<?= htmlspecialchars((string) ($row['language'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="en-US" class="language-input">
                                    <label class="language-lock">
                                        <input type="checkbox" name="language_locked" value="1" <?= !empty($row['language_locked']) ? 'checked' : '' ?>> Lock
                                    </label>
                                    <button type="submit" class="btn">Language</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update_http_auth">
                                    <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="text" name="http_username" value="<?= htmlspecialchars((string) ($row['http_username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Username" autocomplete="off" class="auth-input">
                                    <input type="password" name="http_password" placeholder="Password" autocomplete="new-password" class="auth-input">
                                    <button type="submit" class="btn">HTTP Auth</button>
                                </form>
                                <?php if ($hasAuth) : ?>
                                    <form method="post" onsubmit="return confirm('Clear HTTP credentials for this feed?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="clear_http_auth">
                                        <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn">Clear Auth</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update_http_headers">
                                    <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <textarea name="http_headers" class="headers-textarea" rows="3" placeholder="Header: Value"><?= htmlspecialchars($headersTextarea, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <button type="submit" class="btn">Headers</button>
                                </form>
                                <?php if ($headersCount > 0) : ?>
                                    <form method="post" onsubmit="return confirm('Clear custom headers for this feed?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="clear_http_headers">
                                        <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn">Clear Headers</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update_notes">
                                    <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <textarea name="notes" class="notes-textarea" rows="3" placeholder="Internal notes"><?= htmlspecialchars((string) ($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <button type="submit" class="btn">Save Notes</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update_interval">
                                    <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="number" name="fetch_interval" min="300" value="<?= (int) $row['fetch_interval_sec'] ?>" title="Fetch interval in seconds">
                                    <button type="submit" class="btn">Update</button>
                                </form>
                                <?php if (!$isPaused) : ?>
                                    <form method="post" onsubmit="return confirm('Pause this feed?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="pause">
                                        <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn">Pause</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Queue an immediate fetch for this feed?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="reset_fetch">
                                        <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn">Queue Fetch</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="test_feed">
                                        <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn">Test Fetch</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="test_feed">
                                        <input type="hidden" name="conditional" value="1">
                                        <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn">Test Conditional</button>
                                    </form>
                                <?php else : ?>
                                    <form method="post" onsubmit="return confirm('Resume this feed?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="resume">
                                        <input type="hidden" name="queue" value="1">
                                        <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn">Resume &amp; Queue</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="test_feed">
                                        <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn">Test Fetch</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="test_feed">
                                        <input type="hidden" name="conditional" value="1">
                                        <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn">Test Conditional</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!$isPrivate) : ?>
                                    <form method="post" onsubmit="return confirm('Make this feed private? Public files will be removed.');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="make_private">
                                        <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn">Make Private</button>
                                    </form>
                                <?php else : ?>
                                    <form method="post" onsubmit="return confirm('Make this feed public again?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="make_public">
                                        <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn">Make Public</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Remove this feed?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="feed_id" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="btn btn-delete">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</body>
</html>
