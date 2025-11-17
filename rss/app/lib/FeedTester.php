<?php

declare(strict_types=1);

namespace RSS;

use InvalidArgumentException;
use SimplePie;
use SimplePie_Item;
use Throwable;

/**
 * Execute ad-hoc feed fetches for diagnostics and previews.
 */
class FeedTester
{
    private Http $http;

    private Normalizer $normalizer;

    public function __construct(Http $http, Normalizer $normalizer)
    {
        $this->http = $http;
        $this->normalizer = $normalizer;
    }

    /**
     * Perform a test fetch for the provided feed definition.
     *
     * @param array<string, mixed> $feed
     * @return array<string, mixed>
     */
    public function test(array $feed, bool $useConditionalHeaders = false): array
    {
        $url = isset($feed['url']) ? (string) $feed['url'] : '';
        if ($url === '') {
            throw new InvalidArgumentException('A feed URL is required for testing.');
        }

        $feedId = isset($feed['id']) && is_string($feed['id']) && $feed['id'] !== ''
            ? $feed['id']
            : hash('sha256', strtolower($url));

        $headers = $this->buildHeaders($feed, $useConditionalHeaders);
        $options = $this->buildRequestOptions($feed);

        $started = microtime(true);
        $testedAt = time();
        try {
            $response = $this->http->get($url, $headers, $options);
        } catch (Throwable $httpError) {
            return [
                'status' => 'error',
                'error' => $httpError->getMessage(),
                'url' => $url,
                'feed_id' => $feedId,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'tested_at' => $testedAt,
            ];
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $statusCode = $response->getStatusCode();
        if ($statusCode === 304) {
            return [
                'status' => 'not_modified',
                'http_status' => $statusCode,
                'url' => $url,
                'feed_id' => $feedId,
                'headers' => $this->summarizeResponseHeaders($response),
                'duration_ms' => $durationMs,
                'tested_at' => $testedAt,
            ];
        }

        $body = (string) $response->getBody();
        $contentLength = strlen($body);

        $parser = new SimplePie();
        $parser->set_raw_data($body);
        $parser->set_feed_url($url);
        $parser->enable_cache(false);
        $initResult = $parser->init();

        $items = $parser->get_items();
        $itemCount = is_array($items) ? count($items) : 0;
        $parserErrors = [];
        $parserError = $parser->error();
        if (is_string($parserError) && trim($parserError) !== '') {
            $parserErrors[] = trim($parserError);
        }

        $language = FeedRegistry::normalizeLanguage($parser->get_language() ?: null);
        $latestTs = null;
        $earliestTs = null;
        $samples = [];
        if (is_array($items) && $items !== []) {
            foreach ($items as $index => $item) {
                if (!$item instanceof SimplePie_Item) {
                    continue;
                }
                $published = $item->get_date('U');
                if ($published !== null) {
                    $publishedInt = (int) $published;
                    if ($latestTs === null || $publishedInt > $latestTs) {
                        $latestTs = $publishedInt;
                    }
                    if ($earliestTs === null || $publishedInt < $earliestTs) {
                        $earliestTs = $publishedInt;
                    }
                }

                if (count($samples) < 3) {
                    $normalized = $this->normalizer->normalize($feedId, $item);
                    $samples[] = [
                        'title' => $normalized['title'],
                        'url' => $normalized['url'],
                        'published_ts' => $normalized['published_ts'],
                        'summary_text' => mb_substr($normalized['summary_text'], 0, 280),
                        'tags' => $normalized['tags'],
                    ];
                }
            }
        }

        $status = 'ok';
        if (!$initResult && $itemCount === 0) {
            $status = 'parse_error';
        }

        return [
            'status' => $status,
            'http_status' => $statusCode,
            'url' => $url,
            'feed_id' => $feedId,
            'title' => $parser->get_title() ?: ($feed['title'] ?? null),
            'language' => $language,
            'item_count' => $itemCount,
            'latest_item_ts' => $latestTs,
            'earliest_item_ts' => $earliestTs,
            'samples' => $samples,
            'parser_errors' => $parserErrors,
            'content_length' => $contentLength,
            'headers' => $this->summarizeResponseHeaders($response),
            'duration_ms' => $durationMs,
            'tested_at' => $testedAt,
        ];
    }

    /**
     * @param array<string, mixed> $feed
     * @return array<string, string>
     */
    private function buildHeaders(array $feed, bool $useConditionalHeaders): array
    {
        $headers = [];
        if (isset($feed['http_headers']) && is_array($feed['http_headers'])) {
            foreach ($feed['http_headers'] as $name => $value) {
                if (!is_string($name) || $name === '') {
                    continue;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                $headers[$name] = (string) $value;
            }
        }

        if ($useConditionalHeaders) {
            if (!empty($feed['etag'])) {
                $headers['If-None-Match'] = (string) $feed['etag'];
            }
            if (!empty($feed['last_modified'])) {
                $headers['If-Modified-Since'] = (string) $feed['last_modified'];
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $feed
     * @return array<string, mixed>
     */
    private function buildRequestOptions(array $feed): array
    {
        $options = [];
        if (!empty($feed['http_username'])) {
            $options['auth'] = [
                (string) $feed['http_username'],
                isset($feed['http_password']) ? (string) $feed['http_password'] : '',
            ];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeResponseHeaders(\Psr\Http\Message\ResponseInterface $response): array
    {
        $headers = [
            'etag' => $response->getHeaderLine('ETag') ?: null,
            'last_modified' => $response->getHeaderLine('Last-Modified') ?: null,
            'content_type' => $response->getHeaderLine('Content-Type') ?: null,
        ];

        $length = $response->getHeaderLine('Content-Length');
        if ($length !== '') {
            $headers['content_length'] = (int) $length;
        }

        $encoding = $response->getHeaderLine('Content-Encoding');
        if ($encoding !== '') {
            $headers['content_encoding'] = $encoding;
        }

        $effectiveUrl = $response->getHeaderLine('X-RSS-Effective-Url');
        if ($effectiveUrl !== '') {
            $headers['effective_url'] = $effectiveUrl;
        }

        $redirectCount = $response->getHeaderLine('X-RSS-Redirect-Count');
        if ($redirectCount !== '') {
            $headers['redirect_count'] = (int) $redirectCount;
        }

        $redirectHistory = $response->getHeader('X-RSS-Redirect-History');
        if ($redirectHistory !== []) {
            $chain = array_values(array_filter(array_map('trim', $redirectHistory)));
            if ($chain !== []) {
                $headers['redirect_chain'] = implode(' → ', $chain);
            }
        }

        return $headers;
    }
}
