<?php

declare(strict_types=1);

namespace RSS;

use HTMLPurifier;
use HTMLPurifier_Config;
use SimplePie_Item;

/**
 * Normalize SimplePie items into sanitized arrays.
 */
class Normalizer
{
    private HTMLPurifier $purifier;

    public function __construct()
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('Cache.SerializerPath', Storage::getConfig()['paths']['storage'] . '/tmp');
        $config->set('HTML.SafeIframe', true);
        $config->set('URI.DisableExternalResources', false);
        $config->set('URI.DisableResources', false);
        $config->set('Attr.EnableID', false);
        $config->set('HTML.SafeObject', false);
        $config->set('AutoFormat.RemoveEmpty', true);
        $this->purifier = new HTMLPurifier($config);
    }

    /**
     * Normalize a feed item into storage format.
     *
     * @param string $feedId
     *
     * @return array<string, mixed>
     */
    public function normalize(string $feedId, SimplePie_Item $item): array
    {
        $link = $item->get_permalink();
        $title = trim($item->get_title() ?? '');
        $uid = $this->generateUid($feedId, $item);
        $published = $item->get_date('U');
        $description = $item->get_description();
        $content = $item->get_content();
        $summaryHtml = $content ?: $description ?: '';
        $summaryText = trim(strip_tags($summaryHtml));

        $safeHtml = $summaryHtml !== '' ? $this->purifier->purify($summaryHtml) : '';
        $author = $item->get_author() ? $item->get_author()->get_name() : null;
        $enclosure = $item->get_enclosure();
        $imageUrl = $enclosure ? $enclosure->get_link() : null;
        $categories = array_filter(array_map(static function ($category) {
            return is_object($category) ? trim((string) $category->get_label()) : null;
        }, $item->get_categories() ?? []));

        return [
            'uid' => $uid,
            'feed_id' => $feedId,
            'title' => $title !== '' ? $title : ($summaryText !== '' ? mb_substr($summaryText, 0, 120) : 'Untitled'),
            'url' => $link ?? '',
            'published_ts' => $published ? (int) $published : time(),
            'author' => $author,
            'summary_text' => mb_substr($summaryText, 0, 5000),
            'summary_html_safe' => $safeHtml,
            'image_url' => $imageUrl,
            'tags' => array_values(array_unique(array_filter($categories))),
            'first_seen_ts' => time(),
            'last_verified_ts' => time(),
            'is_dead' => false,
            'fail_count' => 0,
        ];
    }

    private function generateUid(string $feedId, SimplePie_Item $item): string
    {
        $guid = trim((string) ($item->get_id() ?? ''));
        $link = trim((string) ($item->get_permalink() ?? ''));
        $date = $item->get_date('U') ?: '';
        if ($guid !== '') {
            return hash('sha256', $feedId . ':' . $guid);
        }
        if ($link !== '') {
            return hash('sha256', $feedId . ':' . $link);
        }

        return hash('sha256', $feedId . ':' . $date . ':' . random_bytes(16));
    }
}
