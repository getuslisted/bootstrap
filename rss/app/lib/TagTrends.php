<?php

declare(strict_types=1);

namespace RSS;

use JsonException;

/**
 * Aggregate tag usage from recent feed items and publish status snapshots.
 */
final class TagTrends
{
    /**
     * Generate the tag summary payload and persist it to the public status directory.
     *
     * @param array<int, array<string, mixed>> $feeds
     * @param array<string, mixed>             $config
     *
     * @return array<string, mixed>
     */
    public static function writePublicTags(array $feeds, array $config): array
    {
        if (!isset($config['paths']['public'])) {
            throw new \RuntimeException('Missing public path configuration.');
        }

        $target = rtrim((string) $config['paths']['public'], '/') . '/status/tags.json';
        $settings = self::extractSettings($config['metrics']['tags'] ?? []);

        if (!$settings['enabled']) {
            if (is_file($target)) {
                @unlink($target);
            }

            return [
                'enabled' => false,
                'tags_written' => 0,
                'total_tags' => 0,
                'feeds_considered' => 0,
                'items_considered' => 0,
            ];
        }

        $now = time();
        $since = $now - ($settings['lookback_days'] * 86400);
        $tagCounts = [];
        $tagFeeds = [];
        $tagLatest = [];
        $tagDisplay = [];
        $feedsConsidered = 0;
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

            $items = Storage::loadRecentItems($feedId, $settings['max_items_per_feed'], $since);
            if ($items === []) {
                continue;
            }

            $feedsConsidered++;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemsConsidered++;
                $tags = $item['tags'] ?? [];
                if (!is_array($tags) || $tags === []) {
                    continue;
                }

                $timestamp = Storage::resolveItemTimestamp($item);
                $uniqueTags = [];
                foreach ($tags as $tag) {
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

                    $uniqueTags[$key] = $normalized;
                }

                foreach ($uniqueTags as $key => $display) {
                    if (!isset($tagCounts[$key])) {
                        $tagCounts[$key] = 0;
                        $tagFeeds[$key] = [];
                        $tagLatest[$key] = 0;
                        $tagDisplay[$key] = $display;
                    }

                    $tagCounts[$key]++;
                    $tagFeeds[$key][$feedId] = true;

                    if ($timestamp !== null && $timestamp > $tagLatest[$key]) {
                        $tagLatest[$key] = $timestamp;
                        $tagDisplay[$key] = $display;
                    }
                }
            }
        }

        $records = [];
        foreach ($tagCounts as $key => $count) {
            if ($count < $settings['min_count']) {
                continue;
            }

            $records[] = [
                'tag' => $tagDisplay[$key] ?? $key,
                'count' => $count,
                'feed_count' => isset($tagFeeds[$key]) ? count($tagFeeds[$key]) : 0,
                'latest_item_ts' => isset($tagLatest[$key]) && $tagLatest[$key] > 0 ? $tagLatest[$key] : null,
            ];
        }

        usort($records, static function (array $a, array $b): int {
            $countComparison = (int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0);
            if ($countComparison !== 0) {
                return $countComparison;
            }

            $feedComparison = (int) ($b['feed_count'] ?? 0) <=> (int) ($a['feed_count'] ?? 0);
            if ($feedComparison !== 0) {
                return $feedComparison;
            }

            $timeComparison = (int) ($b['latest_item_ts'] ?? 0) <=> (int) ($a['latest_item_ts'] ?? 0);
            if ($timeComparison !== 0) {
                return $timeComparison;
            }

            return strcmp(mb_strtolower((string) ($a['tag'] ?? '')), mb_strtolower((string) ($b['tag'] ?? '')));
        });

        $totalDistinct = count($records);
        $truncatedCount = 0;
        $truncatedTags = 0;
        if (count($records) > $settings['max_tags']) {
            for ($i = $settings['max_tags']; $i < count($records); $i++) {
                $truncatedCount += (int) ($records[$i]['count'] ?? 0);
            }
            $truncatedTags = count($records) - $settings['max_tags'];
            $records = array_slice($records, 0, $settings['max_tags']);
        }

        $payload = [
            'generated_at' => $now,
            'enabled' => true,
            'lookback_days' => $settings['lookback_days'],
            'feeds_considered' => $feedsConsidered,
            'items_considered' => $itemsConsidered,
            'max_items_per_feed' => $settings['max_items_per_feed'],
            'min_count' => $settings['min_count'],
            'max_tags' => $settings['max_tags'],
            'include_private' => $settings['include_private'],
            'total_tags' => $totalDistinct,
            'truncated_tags' => $truncatedTags,
            'truncated_count' => $truncatedCount,
            'tags' => array_map(static function (array $record): array {
                return [
                    'tag' => (string) $record['tag'],
                    'count' => (int) $record['count'],
                    'feed_count' => (int) ($record['feed_count'] ?? 0),
                    'latest_item_ts' => isset($record['latest_item_ts']) && $record['latest_item_ts'] !== null
                        ? (int) $record['latest_item_ts']
                        : null,
                ];
            }, $records),
        ];

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException $e) {
            throw new \RuntimeException('Unable to encode tag summary: ' . $e->getMessage(), 0, $e);
        }

        Storage::writeAtomic($target, $encoded);

        return [
            'enabled' => true,
            'tags_written' => count($payload['tags']),
            'total_tags' => $payload['total_tags'],
            'feeds_considered' => $payload['feeds_considered'],
            'items_considered' => $payload['items_considered'],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private static function extractSettings(array $settings): array
    {
        return [
            'enabled' => !array_key_exists('enabled', $settings) || !empty($settings['enabled']),
            'lookback_days' => max(1, (int) ($settings['lookback_days'] ?? 30)),
            'max_tags' => max(1, (int) ($settings['max_tags'] ?? 100)),
            'min_count' => max(1, (int) ($settings['min_count'] ?? 2)),
            'max_items_per_feed' => max(1, (int) ($settings['max_items_per_feed'] ?? 400)),
            'include_private' => !empty($settings['include_private']),
        ];
    }
}
