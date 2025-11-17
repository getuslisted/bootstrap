<?php

declare(strict_types=1);

namespace RSS;

use DOMDocument;

/**
 * Publisher generates JSON Feed, RSS, and Atom outputs.
 */
class Publisher
{
    private FeedRegistry $registry;
    private array $config;

    public function __construct(FeedRegistry $registry, array $config)
    {
        $this->registry = $registry;
        $this->config = $config;
    }

    /**
     * Publish feeds and optionally refresh combined/status outputs.
     *
     * @param array<int, string>|null $feedIds
     *
     * @return array{
     *     feeds:int,
     *     total_items:int,
     *     combined_items:int,
     *     private_skipped:int,
     *     tag_trends:?array,
     *     latest_items:?array
     * }
     */
    public function publishAll(?array $feedIds = null, bool $includeCombined = true, bool $refreshSnapshots = true): array
    {
        $feeds = $this->registry->listFeeds($feedIds);
        $feedCount = 0;
        $totalItems = 0;
        $cache = [];
        $skippedPrivate = 0;

        foreach ($feeds as $feed) {
            if (!empty($feed['is_private'])) {
                $skippedPrivate++;
                try {
                    $this->removeFeedOutputs($feed['id']);
                } catch (\Throwable $cleanupError) {
                    error_log('Failed to purge private feed outputs for ' . $feed['id'] . ': ' . $cleanupError->getMessage());
                }
                continue;
            }
            $rows = $this->registry->getRecentItems($feed['id'], 200);
            $items = $this->hydrateFeedItems($feed['id'], $rows);
            $cache[$feed['id']] = [];
            foreach ($items as $item) {
                $cache[$feed['id']][$item['uid']] = $item;
            }
            $this->publishFeed($feed, $items);
            $feedCount++;
            $totalItems += count($items);
        }

        $combinedCount = 0;
        if ($includeCombined) {
            $combinedRows = $this->registry->getCombinedItems(500);
            $combinedItems = $this->hydrateCombinedItems($combinedRows, $cache);
            $combinedCount = count($combinedItems);
            $this->publishCombined($combinedItems);
        }

        $tagSummary = null;
        $latestSummary = null;
        if ($refreshSnapshots) {
            $statusSummary = $this->registry->getFeedStatusSummary();
            $metrics = Metrics::writePublicMetrics($statusSummary, $this->config);
            try {
                MetricsHistory::record($metrics, $this->config);
            } catch (\Throwable $historyError) {
                error_log('Metrics history recording failed: ' . $historyError->getMessage());
            }
            $this->writeFeedStatus($statusSummary);
            $this->writeHealthStatus($statusSummary, $metrics);
            $this->writeOpml($feeds);
            try {
                $tagSummary = TagTrends::writePublicTags($feeds, $this->config);
            } catch (\Throwable $tagError) {
                error_log('Tag trends refresh failed: ' . $tagError->getMessage());
            }
            try {
                $latestSummary = LatestItems::writePublicLatest($feeds, $this->config);
            } catch (\Throwable $latestError) {
                error_log('Latest items snapshot failed: ' . $latestError->getMessage());
            }
        }

        return [
            'feeds' => $feedCount,
            'total_items' => $totalItems,
            'combined_items' => $includeCombined ? $combinedCount : 0,
            'private_skipped' => $skippedPrivate,
            'tag_trends' => $tagSummary,
            'latest_items' => $latestSummary,
        ];
    }

    /**
     * @param array<string, mixed> $feed
     * @param array<int, array<string, mixed>> $items
     */
    public function publishFeed(array $feed, array $items): void
    {
        $items = $this->sortItems($items);
        $basePath = $this->config['paths']['public'] . '/feeds/' . $feed['id'];
        if (!is_dir($basePath) && !mkdir($basePath, 0755, true) && !is_dir($basePath)) {
            throw new \RuntimeException('Unable to create feed publish directory: ' . $basePath);
        }

        $json = $this->buildJsonFeed($feed, $items);
        Storage::writeAtomic($this->config['paths']['public'] . '/feeds/' . $feed['id'] . '.json', $json);

        $rss = $this->buildRss($feed, $items);
        Storage::writeAtomic($this->config['paths']['public'] . '/feeds/' . $feed['id'] . '.rss', $rss);

        $atom = $this->buildAtom($feed, $items);
        Storage::writeAtomic($this->config['paths']['public'] . '/feeds/' . $feed['id'] . '.atom', $atom);
    }

    public function removeFeedOutputs(string $feedId): void
    {
        $base = rtrim($this->config['paths']['public'], '/') . '/feeds/';
        foreach (['json', 'rss', 'atom'] as $ext) {
            $path = $base . $feedId . '.' . $ext;
            if (is_file($path) && !@unlink($path)) {
                throw new \RuntimeException('Unable to delete published feed file: ' . $path);
            }
        }

        $dir = $base . $feedId;
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            @rmdir($dir);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function publishCombined(array $items): void
    {
        $feed = [
            'id' => 'all',
            'title' => $this->config['publisher']['site_title'],
            'url' => $this->config['publisher']['site_url'],
            'description' => $this->config['publisher']['site_description'],
            'language' => $this->config['publisher']['language'] ?? null,
        ];

        $items = $this->sortItems($items);

        $json = $this->buildJsonFeed($feed, $items);
        Storage::writeAtomic($this->config['paths']['public'] . '/feeds/all.json', $json);
        $rss = $this->buildRss($feed, $items);
        Storage::writeAtomic($this->config['paths']['public'] . '/feeds/all.rss', $rss);
        $atom = $this->buildAtom($feed, $items);
        Storage::writeAtomic($this->config['paths']['public'] . '/feeds/all.atom', $atom);
    }

    public function refreshHealthStatusFromRegistry(): void
    {
        $feeds = $this->registry->listFeeds();
        $statusSummary = $this->registry->getFeedStatusSummary();
        $metrics = Metrics::writePublicMetrics($statusSummary, $this->config);
        try {
            MetricsHistory::record($metrics, $this->config);
        } catch (\Throwable $historyError) {
            error_log('Metrics history recording failed: ' . $historyError->getMessage());
        }
        $this->writeFeedStatus($statusSummary);
        $this->writeHealthStatus($statusSummary, $metrics);
        $this->writeOpml($feeds);
        try {
            TagTrends::writePublicTags($feeds, $this->config);
        } catch (\Throwable $tagError) {
            error_log('Tag trends refresh failed: ' . $tagError->getMessage());
        }
        try {
            LatestItems::writePublicLatest($feeds, $this->config);
        } catch (\Throwable $latestError) {
            error_log('Latest items snapshot failed: ' . $latestError->getMessage());
        }
        try {
            $backupService = new Backup($this->config['paths']);
            BackupStatus::writeSummary($backupService, $this->config['paths']);
        } catch (\Throwable $backupError) {
            error_log('Backup status refresh failed: ' . $backupError->getMessage());
        }
    }

    /**
     * @param array<int, array<string, scalar|null>> $summary
     */
    private function writeHealthStatus(array $summary, ?array $metrics = null): void
    {
        $metrics = $metrics ?? Metrics::calculate($summary, $this->config);

        $feedsMetrics = $metrics['feeds'] ?? [];
        $status = [
            'generated_at' => $metrics['generated_at'] ?? time(),
            'feeds_count' => isset($feedsMetrics['total']) ? (int) $feedsMetrics['total'] : count($summary),
            'feeds_with_errors' => (int) ($feedsMetrics['errors'] ?? 0),
            'feeds_overdue' => (int) ($feedsMetrics['overdue'] ?? 0),
            'feeds_never_fetched' => (int) ($feedsMetrics['never_fetched'] ?? 0),
            'feeds_paused' => (int) ($feedsMetrics['paused'] ?? 0),
            'feeds_private' => (int) ($feedsMetrics['private'] ?? 0),
            'feeds_public' => (int) ($feedsMetrics['public'] ?? max(0, ($feedsMetrics['total'] ?? 0) - ($feedsMetrics['private'] ?? 0))),
            'feeds_with_http_auth' => (int) ($feedsMetrics['with_http_auth'] ?? 0),
            'feeds_with_custom_headers' => (int) ($feedsMetrics['with_custom_headers'] ?? 0),
            'feeds_with_notes' => (int) ($feedsMetrics['with_notes'] ?? 0),
            'feeds_in_backoff' => (int) ($feedsMetrics['in_backoff'] ?? 0),
            'feeds_max_error_streak' => (int) ($feedsMetrics['max_error_streak'] ?? 0),
            'jobs' => $metrics['jobs'] ?? JobStatus::getPublicSummary(),
        ];

        $languagesMetrics = $metrics['languages'] ?? [];
        $status['languages'] = [
            'unique' => (int) ($languagesMetrics['unique'] ?? 0),
            'most_common' => $languagesMetrics['most_common'] ?? null,
            'locked' => (int) ($languagesMetrics['locked'] ?? 0),
            'distribution' => $languagesMetrics['distribution'] ?? [],
        ];

        $alertsMetrics = $metrics['alerts'] ?? [];
        $alertsEnabled = !empty($alertsMetrics['enabled']);
        $status['alerts'] = [
            'enabled' => $alertsEnabled,
            'active' => $alertsEnabled ? (int) ($alertsMetrics['active'] ?? 0) : 0,
            'last_sent_ts' => $alertsEnabled ? ($alertsMetrics['last_sent_ts'] ?? null) : null,
            'history_count' => $alertsEnabled ? (int) ($alertsMetrics['history_count'] ?? 0) : 0,
        ];

        if (isset($metrics['disk'])) {
            $status['disk'] = $metrics['disk'];
        }
        if (isset($metrics['backups'])) {
            $status['backups'] = $metrics['backups'];
        }

        Storage::writeAtomic($this->config['paths']['public'] . '/status/health.json', json_encode($status, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * @param array<int, array<string, scalar|null>> $summary
     */
    private function writeFeedStatus(array $summary): void
    {
        $publicSummary = [];
        foreach ($summary as $feed) {
            $entry = $feed;
            $hasNotes = !empty($entry['has_notes']);
            if (!$hasNotes && isset($entry['notes'])) {
                $noteValue = $entry['notes'];
                if (is_string($noteValue) && FeedRegistry::normalizeNotes($noteValue) !== null) {
                    $hasNotes = true;
                } elseif (is_scalar($noteValue)) {
                    $normalized = FeedRegistry::normalizeNotes((string) $noteValue);
                    if ($normalized !== null) {
                        $hasNotes = true;
                    }
                }
            }
            unset($entry['notes']);
            $entry['has_notes'] = $hasNotes;
            $publicSummary[] = $entry;
        }

        Storage::writeAtomic(
            $this->config['paths']['public'] . '/status/feeds.json',
            json_encode(
                [
                    'generated_at' => time(),
                    'feeds' => $publicSummary,
                ],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $feeds
     */
    private function writeOpml(array $feeds): void
    {
        $metadata = [
            'title' => $this->config['publisher']['site_title'] ?? 'RSS Subscriptions',
            'owner_name' => $this->config['publisher']['owner_name'] ?? null,
            'owner_email' => $this->config['publisher']['owner_email'] ?? null,
            'home_url' => $this->config['publisher']['site_url'] ?? null,
        ];

        $activeFeeds = array_values(array_filter($feeds, static function ($feed): bool {
            return empty($feed['is_paused']) && empty($feed['is_private']);
        }));

        $opml = Opml::build($activeFeeds, $metadata);
        Storage::writeAtomic(
            $this->config['paths']['public'] . '/status/subscriptions.opml',
            $opml
        );
    }

    /**
     * @param array<string, mixed> $feed
     * @param array<int, array<string, mixed>> $items
     */
    private function buildJsonFeed(array $feed, array $items): string
    {
        $feedUrl = $this->getFeedUrl($feed, 'json');
        $latestTs = $this->resolveLatestTimestamp($items);
        $jsonFeed = [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => $feed['title'] ?? $this->config['publisher']['site_title'],
            'home_page_url' => $feed['url'] ?? $this->config['publisher']['site_url'],
            'feed_url' => $feedUrl,
            'description' => $feed['description'] ?? $this->config['publisher']['site_description'],
            'items' => array_map(function ($item) {
                $published = $this->resolveItemTimestamp($item);
                $author = isset($item['author']) && $item['author'] !== '' ? ['name' => (string) $item['author']] : null;
                return [
                    'id' => $item['uid'],
                    'url' => $item['url'],
                    'title' => $item['title'],
                    'content_html' => $item['summary_html_safe'],
                    'content_text' => $item['summary_text'],
                    'date_published' => $published > 0 ? gmdate('c', $published) : null,
                    'author' => $author,
                    'tags' => $item['tags'],
                    'image' => $item['image_url'],
                ];
            }, $items),
        ];
        if ($latestTs > 0) {
            $jsonFeed['date_modified'] = gmdate('c', $latestTs);
        }

        $language = FeedRegistry::normalizeLanguage($feed['language'] ?? ($this->config['publisher']['language'] ?? null));
        if ($language !== null) {
            $jsonFeed['language'] = $language;
        }

        return json_encode($jsonFeed, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * @param array<string, mixed> $feed
     * @param array<int, array<string, mixed>> $items
     */
    private function buildRss(array $feed, array $items): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $channel = $doc->createElement('channel');
        $channel->appendChild($doc->createElement('title'))->appendChild($doc->createTextNode($feed['title'] ?? $this->config['publisher']['site_title']));
        $channel->appendChild($doc->createElement('link'))->appendChild($doc->createTextNode($feed['url'] ?? $this->config['publisher']['site_url']));
        $channel->appendChild($doc->createElement('description'))->appendChild($doc->createTextNode($feed['description'] ?? $this->config['publisher']['site_description']));
        $latestTs = $this->resolveLatestTimestamp($items);
        if ($latestTs > 0) {
            $channel->appendChild($doc->createElement('lastBuildDate'))->appendChild($doc->createTextNode(gmdate('r', $latestTs)));
        }

        $language = FeedRegistry::normalizeLanguage($feed['language'] ?? ($this->config['publisher']['language'] ?? null));
        if ($language !== null) {
            $channel->appendChild($doc->createElement('language'))->appendChild($doc->createTextNode($language));
        }

        $selfLink = $doc->createElement('atom:link');
        $selfLink->setAttribute('href', $this->getFeedUrl($feed, 'rss'));
        $selfLink->setAttribute('rel', 'self');
        $selfLink->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($selfLink);

        foreach ($items as $item) {
            $itemNode = $doc->createElement('item');
            $itemNode->appendChild($doc->createElement('title'))->appendChild($doc->createTextNode($item['title']));
            $itemNode->appendChild($doc->createElement('link'))->appendChild($doc->createTextNode($item['url']));
            $guid = $doc->createElement('guid');
            $guid->appendChild($doc->createTextNode($item['uid']));
            $itemNode->appendChild($guid);
            $pubTs = $this->resolveItemTimestamp($item);
            $itemNode->appendChild($doc->createElement('pubDate'))->appendChild($doc->createTextNode(gmdate('r', $pubTs > 0 ? $pubTs : time())));
            $description = $doc->createElement('description');
            $cdata = $doc->createCDATASection($item['summary_html_safe']);
            $description->appendChild($cdata);
            $itemNode->appendChild($description);
            if (!empty($item['author'])) {
                $itemNode->appendChild($doc->createElement('author'))->appendChild($doc->createTextNode((string) $item['author']));
            }
            $channel->appendChild($itemNode);
        }

        $rss->appendChild($channel);
        $doc->appendChild($rss);
        return $doc->saveXML() ?: '';
    }

    /**
     * @param array<string, mixed> $feed
     * @param array<int, array<string, mixed>> $items
     */
    private function buildAtom(array $feed, array $items): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $feedNode = $doc->createElementNS('http://www.w3.org/2005/Atom', 'feed');
        $doc->appendChild($feedNode);
        $language = FeedRegistry::normalizeLanguage($feed['language'] ?? ($this->config['publisher']['language'] ?? null));
        if ($language !== null) {
            $feedNode->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $language);
        }
        $feedNode->appendChild($doc->createElementNS('http://www.w3.org/2005/Atom', 'title', $feed['title'] ?? $this->config['publisher']['site_title']));
        $feedNode->appendChild($doc->createElementNS('http://www.w3.org/2005/Atom', 'id', $feed['url'] ?? $this->config['publisher']['site_url']));
        $latestTs = $this->resolveLatestTimestamp($items);
        $feedNode->appendChild($doc->createElementNS('http://www.w3.org/2005/Atom', 'updated', gmdate('c', $latestTs > 0 ? $latestTs : time())));
        $selfLink = $doc->createElementNS('http://www.w3.org/2005/Atom', 'link');
        $selfLink->setAttribute('rel', 'self');
        $selfLink->setAttribute('href', $this->getFeedUrl($feed, 'atom'));
        $selfLink->setAttribute('type', 'application/atom+xml');
        $feedNode->appendChild($selfLink);
        $siteLink = $doc->createElementNS('http://www.w3.org/2005/Atom', 'link');
        $siteLink->setAttribute('rel', 'alternate');
        $siteLink->setAttribute('href', $feed['url'] ?? $this->config['publisher']['site_url']);
        $feedNode->appendChild($siteLink);

        foreach ($items as $item) {
            $entry = $doc->createElementNS('http://www.w3.org/2005/Atom', 'entry');
            $entry->appendChild($doc->createElementNS('http://www.w3.org/2005/Atom', 'id', $item['uid']));
            $title = $doc->createElementNS('http://www.w3.org/2005/Atom', 'title');
            $title->appendChild($doc->createCDATASection($item['title']));
            $entry->appendChild($title);
            $entryLink = $doc->createElementNS('http://www.w3.org/2005/Atom', 'link');
            $entryLink->setAttribute('href', $item['url']);
            $entry->appendChild($entryLink);
            $pubTs = $this->resolveItemTimestamp($item);
            $entry->appendChild($doc->createElementNS('http://www.w3.org/2005/Atom', 'updated', gmdate('c', $pubTs > 0 ? $pubTs : time())));
            if (!empty($item['author'])) {
                $author = $doc->createElementNS('http://www.w3.org/2005/Atom', 'author');
                $author->appendChild($doc->createElementNS('http://www.w3.org/2005/Atom', 'name', (string) $item['author']));
                $entry->appendChild($author);
            }
            $content = $doc->createElementNS('http://www.w3.org/2005/Atom', 'content');
            $content->setAttribute('type', 'html');
            $content->appendChild($doc->createCDATASection($item['summary_html_safe']));
            $entry->appendChild($content);
            if (!empty($item['summary_text'])) {
                $summary = $doc->createElementNS('http://www.w3.org/2005/Atom', 'summary');
                $summary->appendChild($doc->createCDATASection($item['summary_text']));
                $entry->appendChild($summary);
            }
            $feedNode->appendChild($entry);
        }

        return $doc->saveXML() ?: '';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function hydrateFeedItems(string $feedId, array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $uids = [];
        foreach ($rows as $row) {
            if (isset($row['uid']) && $row['uid'] !== '') {
                $uids[] = (string) $row['uid'];
            }
        }

        $details = Storage::loadItemsByUid($feedId, $uids);
        $items = [];
        foreach ($rows as $row) {
            $uid = (string) $row['uid'];
            $item = $details[$uid] ?? $this->fallbackItem($row, $feedId);
            $items[] = $this->mergeRowIntoItem($item, $row);
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<string, array<string, mixed>>> $cache
     * @return array<int, array<string, mixed>>
     */
    private function hydrateCombinedItems(array $rows, array $cache): array
    {
        if (empty($rows)) {
            return [];
        }

        $grouped = [];
        foreach ($rows as $row) {
            $feedId = (string) $row['feed_id'];
            $grouped[$feedId][] = $row;
        }

        $items = [];
        foreach ($grouped as $feedId => $feedRows) {
            $known = $cache[$feedId] ?? [];
            $uids = [];
            foreach ($feedRows as $row) {
                if (isset($row['uid'])) {
                    $uids[] = (string) $row['uid'];
                }
            }
            $loaded = Storage::loadItemsByUid($feedId, $uids);
            foreach ($feedRows as $row) {
                $uid = (string) $row['uid'];
                $item = $known[$uid] ?? $loaded[$uid] ?? $this->fallbackItem($row, $feedId);
                $items[] = $this->mergeRowIntoItem($item, $row);
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mergeRowIntoItem(array $item, array $row): array
    {
        $item['uid'] = (string) ($item['uid'] ?? ($row['uid'] ?? ''));
        $item['feed_id'] = (string) ($item['feed_id'] ?? ($row['feed_id'] ?? ''));
        $item['url'] = (string) ($item['url'] ?? ($row['url'] ?? ''));
        $item['title'] = (string) ($item['title'] ?? ($row['title'] ?? 'Untitled'));
        if (!isset($item['summary_text']) || !is_string($item['summary_text']) || $item['summary_text'] === '') {
            $item['summary_text'] = $item['title'];
        }
        if (!isset($item['summary_html_safe']) || !is_string($item['summary_html_safe']) || $item['summary_html_safe'] === '') {
            $item['summary_html_safe'] = '<p>' . htmlspecialchars($item['summary_text'], ENT_QUOTES, 'UTF-8') . '</p>';
        }
        if (!isset($item['tags']) || !is_array($item['tags'])) {
            $item['tags'] = [];
        } else {
            $item['tags'] = $this->normalizeTags($item['tags']);
        }
        $item['image_url'] = isset($item['image_url']) && is_string($item['image_url']) ? $item['image_url'] : null;
        $item['author'] = isset($item['author']) && $item['author'] !== '' ? (string) $item['author'] : null;
        if (isset($row['published_ts']) && (!isset($item['published_ts']) || (int) $item['published_ts'] === 0)) {
            $item['published_ts'] = (int) $row['published_ts'];
        } elseif (isset($item['published_ts'])) {
            $item['published_ts'] = (int) $item['published_ts'];
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function fallbackItem(array $row, string $feedId): array
    {
        $title = isset($row['title']) && $row['title'] !== '' ? (string) $row['title'] : 'Untitled';
        $summary = $title;

        return [
            'uid' => (string) ($row['uid'] ?? ''),
            'feed_id' => $feedId,
            'title' => $title,
            'url' => (string) ($row['url'] ?? ''),
            'summary_text' => $summary,
            'summary_html_safe' => '<p>' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</p>',
            'author' => null,
            'image_url' => null,
            'tags' => [],
            'published_ts' => isset($row['published_ts']) ? (int) $row['published_ts'] : null,
        ];
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    private function normalizeTags(array $tags): array
    {
        $clean = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $trimmed = trim($tag);
            if ($trimmed === '') {
                continue;
            }
            $clean[$trimmed] = true;
        }

        return array_keys($clean);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function sortItems(array $items): array
    {
        usort($items, function (array $a, array $b): int {
            return $this->resolveItemTimestamp($b) <=> $this->resolveItemTimestamp($a);
        });

        return $items;
    }

    /**
     * Determine the most relevant timestamp for an item.
     */
    private function resolveItemTimestamp(array $item): int
    {
        $candidates = [
            $item['published_ts'] ?? null,
            $item['first_seen_ts'] ?? null,
            $item['last_verified_ts'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return time();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function resolveLatestTimestamp(array $items): int
    {
        $latest = 0;
        foreach ($items as $item) {
            $ts = $this->resolveItemTimestamp($item);
            if ($ts > $latest) {
                $latest = $ts;
            }
        }

        return $latest;
    }

    /**
     * @param array<string, mixed> $feed
     */
    private function getFeedUrl(array $feed, string $extension): string
    {
        $base = rtrim($this->config['publisher']['site_url'], '/');

        return $base . '/feeds/' . $feed['id'] . '.' . $extension;
    }
}
