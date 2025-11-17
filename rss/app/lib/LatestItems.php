<?php

declare(strict_types=1);

namespace RSS;

use JsonException;

/**
 * Build a consolidated snapshot of the newest items across feeds.
 */
final class LatestItems
{
    /**
     * Generate the latest item snapshot and write it to the public status directory.
     *
     * @param array<int, array<string, mixed>> $feeds
     * @param array<string, mixed>             $config
     *
     * @return array<string, mixed>
     */
    public static function writePublicLatest(array $feeds, array $config): array
    {
        if (!isset($config['paths']['public'])) {
            throw new \RuntimeException('Missing public path configuration.');
        }

        $target = rtrim((string) $config['paths']['public'], '/') . '/status/latest.json';
        $settings = self::extractSettings($config['status']['latest'] ?? []);

        if (!$settings['enabled']) {
            if (is_file($target)) {
                @unlink($target);
            }

            return [
                'enabled' => false,
                'items_written' => 0,
                'feeds_with_items' => 0,
                'truncated' => 0,
                'feeds_considered' => 0,
                'items_considered' => 0,
            ];
        }

        $now = time();
        $minTimestamp = $settings['lookback_days'] > 0
            ? $now - ($settings['lookback_days'] * 86400)
            : null;

        $items = [];
        $feedsConsidered = 0;
        $feedsWithItems = [];
        $itemsConsidered = 0;

        foreach ($feeds as $feed) {
            if (!is_array($feed)) {
                continue;
            }

            $feedId = isset($feed['id']) ? trim((string) $feed['id']) : '';
            if ($feedId === '') {
                continue;
            }

            if (!$settings['include_private'] && !empty($feed['is_private'])) {
                continue;
            }

            $feedsConsidered++;

            $recentItems = Storage::loadRecentItems($feedId, $settings['max_items_per_feed'], $minTimestamp);
            if ($recentItems === []) {
                continue;
            }

            $feedTitle = isset($feed['title']) && $feed['title'] !== null
                ? trim((string) $feed['title'])
                : null;
            $feedUrl = isset($feed['url']) ? trim((string) $feed['url']) : '';

            foreach ($recentItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemsConsidered++;

                if (!$settings['include_dead'] && !empty($item['is_dead'])) {
                    continue;
                }

                $uid = isset($item['uid']) ? trim((string) $item['uid']) : '';
                if ($uid === '') {
                    continue;
                }

                $link = isset($item['url']) ? trim((string) $item['url']) : '';
                if ($link === '') {
                    continue;
                }

                $timestamp = Storage::resolveItemTimestamp($item);
                if ($timestamp === null) {
                    continue;
                }

                if ($minTimestamp !== null && $timestamp < $minTimestamp) {
                    continue;
                }

                $feedsWithItems[$feedId] = true;

                $record = [
                    'uid' => $uid,
                    'feed_id' => $feedId,
                    'feed_title' => $feedTitle,
                    'feed_url' => $feedUrl,
                    'title' => self::truncateString($item['title'] ?? null, 500),
                    'url' => $link,
                    'summary_text' => self::truncateString($item['summary_text'] ?? null, 2000),
                    'summary_html_safe' => $settings['include_summary_html']
                        ? self::truncateString($item['summary_html_safe'] ?? null, 4000)
                        : null,
                    'image_url' => self::filterUrl($item['image_url'] ?? null),
                    'tags' => self::normalizeTags($item['tags'] ?? []),
                    'published_ts' => self::normalizeTimestamp($item['published_ts'] ?? null),
                    'first_seen_ts' => self::normalizeTimestamp($item['first_seen_ts'] ?? null),
                    'last_verified_ts' => self::normalizeTimestamp($item['last_verified_ts'] ?? null),
                    'is_dead' => !empty($item['is_dead']),
                    'effective_ts' => (int) $timestamp,
                ];

                $items[] = $record;
            }
        }

        if ($items === []) {
            $payload = [
                'generated_at' => $now,
                'enabled' => true,
                'lookback_days' => $settings['lookback_days'],
                'max_items' => $settings['max_items'],
                'max_items_per_feed' => $settings['max_items_per_feed'],
                'include_private' => $settings['include_private'],
                'include_dead' => $settings['include_dead'],
                'feeds_considered' => $feedsConsidered,
                'feeds_with_items' => 0,
                'items_considered' => $itemsConsidered,
                'items_written' => 0,
                'truncated' => 0,
                'newest_item_ts' => null,
                'oldest_item_ts' => null,
                'items' => [],
            ];

            self::writeSnapshot($target, $payload);

            return $payload;
        }

        usort($items, static function (array $a, array $b): int {
            $left = isset($a['effective_ts']) ? (int) $a['effective_ts'] : 0;
            $right = isset($b['effective_ts']) ? (int) $b['effective_ts'] : 0;
            return $right <=> $left;
        });

        $truncated = 0;
        if (count($items) > $settings['max_items']) {
            $truncated = count($items) - $settings['max_items'];
            $items = array_slice($items, 0, $settings['max_items']);
        }

        $newestTs = isset($items[0]['effective_ts']) ? (int) $items[0]['effective_ts'] : null;
        $oldestTs = isset($items[count($items) - 1]['effective_ts'])
            ? (int) $items[count($items) - 1]['effective_ts']
            : null;

        foreach ($items as &$item) {
            if (array_key_exists('summary_html_safe', $item) && $item['summary_html_safe'] === null) {
                unset($item['summary_html_safe']);
            }
            $item['tags'] = array_values($item['tags']);
        }
        unset($item);

        $payload = [
            'generated_at' => $now,
            'enabled' => true,
            'lookback_days' => $settings['lookback_days'],
            'max_items' => $settings['max_items'],
            'max_items_per_feed' => $settings['max_items_per_feed'],
            'include_private' => $settings['include_private'],
            'include_dead' => $settings['include_dead'],
            'feeds_considered' => $feedsConsidered,
            'feeds_with_items' => count($feedsWithItems),
            'items_considered' => $itemsConsidered,
            'items_written' => count($items),
            'truncated' => $truncated,
            'newest_item_ts' => $newestTs,
            'oldest_item_ts' => $oldestTs,
            'items' => $items,
        ];

        self::writeSnapshot($target, $payload);

        return $payload;
    }

    /**
     * Load the latest snapshot from disk if it exists.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>|null
     */
    public static function loadSnapshot(array $config): ?array
    {
        if (!isset($config['paths']['public'])) {
            return null;
        }

        $path = rtrim((string) $config['paths']['public'], '/') . '/status/latest.json';
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read latest snapshot from ' . $path);
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new \RuntimeException('Failed to decode latest snapshot: ' . $error->getMessage(), 0, $error);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Normalize a snapshot payload into consistent summary metadata.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    public static function summarizeSnapshot(array $snapshot): array
    {
        $items = [];
        if (isset($snapshot['items']) && is_array($snapshot['items'])) {
            $items = $snapshot['items'];
        }

        $feedsWithItems = [];
        $newest = null;
        $oldest = null;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $feedId = isset($item['feed_id']) ? trim((string) $item['feed_id']) : '';
            if ($feedId !== '') {
                $feedsWithItems[$feedId] = true;
            }

            $timestamp = null;
            if (isset($item['effective_ts'])) {
                $timestamp = (int) $item['effective_ts'];
            }
            if ($timestamp === null || $timestamp <= 0) {
                $timestamp = Storage::resolveItemTimestamp($item);
            }

            if ($timestamp !== null && $timestamp > 0) {
                $newest = $newest === null ? $timestamp : max($newest, $timestamp);
                $oldest = $oldest === null ? $timestamp : min($oldest, $timestamp);
            }
        }

        $feedsCount = count($feedsWithItems);
        $itemsWritten = isset($snapshot['items_written']) ? (int) $snapshot['items_written'] : count($items);
        $itemsConsidered = isset($snapshot['items_considered'])
            ? (int) $snapshot['items_considered']
            : $itemsWritten;
        $feedsConsidered = isset($snapshot['feeds_considered'])
            ? (int) $snapshot['feeds_considered']
            : $feedsCount;
        $truncated = isset($snapshot['truncated']) ? max(0, (int) $snapshot['truncated']) : 0;

        if ($truncated === 0 && $itemsWritten > count($items)) {
            $truncated = $itemsWritten - count($items);
        }

        return [
            'generated_at' => isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : null,
            'lookback_days' => isset($snapshot['lookback_days']) ? (int) $snapshot['lookback_days'] : 0,
            'max_items' => isset($snapshot['max_items']) ? (int) $snapshot['max_items'] : max($itemsWritten, 1),
            'max_items_per_feed' => isset($snapshot['max_items_per_feed'])
                ? (int) $snapshot['max_items_per_feed']
                : max(1, $itemsWritten),
            'include_private' => array_key_exists('include_private', $snapshot)
                ? !empty($snapshot['include_private'])
                : false,
            'include_dead' => array_key_exists('include_dead', $snapshot)
                ? !empty($snapshot['include_dead'])
                : false,
            'feeds_considered' => $feedsConsidered,
            'feeds_with_items' => $feedsCount,
            'items_considered' => $itemsConsidered,
            'items_written' => $itemsWritten,
            'truncated' => $truncated,
            'newest_item_ts' => $newest,
            'oldest_item_ts' => $oldest,
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{
     *     enabled: bool,
     *     max_items: int,
     *     max_items_per_feed: int,
     *     lookback_days: int,
     *     include_private: bool,
     *     include_dead: bool,
     *     include_summary_html: bool
     * }
     */
    private static function extractSettings(array $config): array
    {
        return [
            'enabled' => !array_key_exists('enabled', $config) || !empty($config['enabled']),
            'max_items' => max(1, (int) ($config['max_items'] ?? 200)),
            'max_items_per_feed' => max(1, (int) ($config['max_items_per_feed'] ?? 50)),
            'lookback_days' => max(0, (int) ($config['lookback_days'] ?? 14)),
            'include_private' => !empty($config['include_private']),
            'include_dead' => !empty($config['include_dead']),
            'include_summary_html' => !empty($config['include_summary_html']),
        ];
    }

    private static function truncateString(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            if (is_scalar($value)) {
                $value = (string) $value;
            } else {
                return null;
            }
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $limit) {
            $value = mb_substr($value, 0, $limit);
        }

        return $value;
    }

    private static function filterUrl(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 2000) {
            $value = mb_substr($value, 0, 2000);
        }

        return $value;
    }

    private static function normalizeTags(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $tags = [];
        foreach ($value as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $normalized = trim($tag);
            if ($normalized === '') {
                continue;
            }

            if (mb_strlen($normalized) > 120) {
                $normalized = mb_substr($normalized, 0, 120);
            }

            $key = mb_strtolower($normalized);
            if ($key === '') {
                continue;
            }

            $tags[$key] = $normalized;
            if (count($tags) >= 25) {
                break;
            }
        }

        return $tags;
    }

    private static function normalizeTimestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_numeric($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function writeSnapshot(string $target, array $payload): void
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException $e) {
            throw new \RuntimeException('Unable to encode latest items snapshot: ' . $e->getMessage(), 0, $e);
        }

        Storage::writeAtomic($target, $encoded . "\n");
    }
}
