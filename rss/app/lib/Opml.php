<?php

declare(strict_types=1);

namespace RSS;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Utility helpers for OPML import/export.
 */
class Opml
{
    /**
     * Build an OPML document for the provided feeds.
     *
     * @param array<int, array<string, mixed>> $feeds
     * @param array<string, mixed> $metadata
     * @param bool $includeAuth Include HTTP Basic credentials in export
     * @param bool $includeHeaders Include custom HTTP headers in export
     *
     * @throws RuntimeException When the DOM cannot be serialized.
     */
    public static function build(array $feeds, array $metadata = [], bool $includeAuth = false, bool $includeHeaders = false): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $opml = $doc->createElement('opml');
        $opml->setAttribute('version', '2.0');
        $doc->appendChild($opml);

        $head = $doc->createElement('head');
        $opml->appendChild($head);

        $title = (string) ($metadata['title'] ?? 'RSS Subscriptions');
        $titleNode = $doc->createElement('title');
        $titleNode->appendChild($doc->createTextNode($title));
        $head->appendChild($titleNode);

        $head->appendChild($doc->createElement('dateCreated', gmdate('c')));
        $head->appendChild($doc->createElement('dateModified', gmdate('c')));

        if (!empty($metadata['owner_name'])) {
            $owner = $doc->createElement('ownerName');
            $owner->appendChild($doc->createTextNode((string) $metadata['owner_name']));
            $head->appendChild($owner);
        }
        if (!empty($metadata['owner_email'])) {
            $email = $doc->createElement('ownerEmail');
            $email->appendChild($doc->createTextNode((string) $metadata['owner_email']));
            $head->appendChild($email);
        }
        if (!empty($metadata['home_url'])) {
            $docsNode = $doc->createElement('docs');
            $docsNode->appendChild($doc->createTextNode((string) $metadata['home_url']));
            $head->appendChild($docsNode);
        }

        $body = $doc->createElement('body');
        $opml->appendChild($body);

        $seen = [];
        foreach ($feeds as $feed) {
            $url = isset($feed['url']) ? trim((string) $feed['url']) : '';
            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                continue;
            }

            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $titleValue = isset($feed['title']) && $feed['title'] !== ''
                ? (string) $feed['title']
                : $url;

            $outline = $doc->createElement('outline');
            $outline->setAttribute('type', 'rss');
            $outline->setAttribute('text', $titleValue);
            $outline->setAttribute('title', $titleValue);
            $outline->setAttribute('xmlUrl', $url);
            $outline->setAttribute('htmlUrl', $url);

            if (isset($feed['id']) && $feed['id'] !== '') {
                $outline->setAttribute('rssId', (string) $feed['id']);
            }
            $interval = isset($feed['fetch_interval_sec']) ? (int) $feed['fetch_interval_sec'] : null;
            if ($interval !== null && $interval >= 300) {
                $outline->setAttribute('fetchInterval', (string) $interval);
            }
            if (!empty($feed['language'])) {
                $language = FeedRegistry::normalizeLanguage((string) $feed['language']);
                if ($language !== null) {
                    $outline->setAttribute('language', $language);
                }
            }
            if ($includeAuth && !empty($feed['http_username'])) {
                $outline->setAttribute('httpUsername', (string) $feed['http_username']);
                if (array_key_exists('http_password', $feed) && $feed['http_password'] !== null) {
                    $outline->setAttribute('httpPasswordB64', base64_encode((string) $feed['http_password']));
                }
            }
            if ($includeHeaders && !empty($feed['http_headers']) && is_array($feed['http_headers'])) {
                try {
                    $encodedHeaders = json_encode($feed['http_headers'], JSON_THROW_ON_ERROR);
                    $outline->setAttribute('httpHeadersB64', base64_encode((string) $encodedHeaders));
                } catch (JsonException $jsonException) {
                    // Skip invalid header payloads during export.
                }
            }
            if (!empty($feed['is_private'])) {
                $outline->setAttribute('private', 'true');
            }
            $body->appendChild($outline);
        }

        $xml = $doc->saveXML();
        if ($xml === false) {
            throw new RuntimeException('Failed to build OPML document.');
        }

        return $xml;
    }

    /**
     * Parse OPML content into feed definitions.
     *
     * @return array<int, array{url: string, title: string|null, fetch_interval: int|null, language: string|null, http_username: string|null, http_password: string|null, http_headers: array<string, string>|null, is_private: bool, private_defined: bool}>
     *
     * @throws InvalidArgumentException When the OPML payload is malformed.
     */
    public static function parse(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $doc = new DOMDocument();
        $useErrors = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML(
            $content,
            LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($useErrors);

        if ($loaded === false) {
            throw new InvalidArgumentException('Invalid OPML document.');
        }

        $feeds = [];
        $seen = [];
        /** @var DOMElement $outline */
        foreach ($doc->getElementsByTagName('outline') as $outline) {
            if (!$outline->hasAttribute('xmlUrl')) {
                continue;
            }
            $url = trim($outline->getAttribute('xmlUrl'));
            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                continue;
            }
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $title = trim($outline->getAttribute('title') !== '' ? $outline->getAttribute('title') : $outline->getAttribute('text'));
            $intervalRaw = trim($outline->getAttribute('fetchInterval'));
            $interval = null;
            if ($intervalRaw !== '' && ctype_digit($intervalRaw)) {
                $value = (int) $intervalRaw;
                if ($value >= 300) {
                    $interval = $value;
                }
            }

            $languageAttr = $outline->getAttribute('language');
            if ($languageAttr === '' && $outline->hasAttribute('xml:lang')) {
                $languageAttr = $outline->getAttribute('xml:lang');
            }
            $language = FeedRegistry::normalizeLanguage($languageAttr !== '' ? $languageAttr : null);

            $httpUsernameAttr = trim($outline->getAttribute('httpUsername'));
            $httpUsername = $httpUsernameAttr !== '' ? $httpUsernameAttr : null;
            $httpPassword = null;
            if ($httpUsername !== null) {
                $encodedPassword = $outline->getAttribute('httpPasswordB64');
                if ($encodedPassword !== '') {
                    $decoded = base64_decode($encodedPassword, true);
                    if ($decoded !== false) {
                        $httpPassword = $decoded;
                    }
                } elseif ($outline->hasAttribute('httpPassword')) {
                    $httpPassword = $outline->getAttribute('httpPassword');
                }
            }
            $httpHeaders = null;
            $headersAttr = $outline->getAttribute('httpHeadersB64');
            if ($headersAttr !== '') {
                $decodedHeaders = base64_decode($headersAttr, true);
                if ($decodedHeaders !== false) {
                    try {
                        $decodedArray = json_decode($decodedHeaders, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decodedArray)) {
                            $httpHeaders = FeedRegistry::normalizeHttpHeaders($decodedArray);
                        }
                    } catch (JsonException | InvalidArgumentException $headerException) {
                        $httpHeaders = null;
                    }
                }
            }

            $privateAttr = strtolower(trim($outline->getAttribute('private')));
            $privateDefined = $outline->hasAttribute('private');
            $isPrivate = $privateDefined && in_array($privateAttr, ['1', 'true', 'yes'], true);

            $feeds[] = [
                'url' => $url,
                'title' => $title !== '' ? $title : null,
                'fetch_interval' => $interval,
                'language' => $language,
                'http_username' => $httpUsername,
                'http_password' => $httpUsername !== null ? $httpPassword : null,
                'http_headers' => $httpHeaders,
                'is_private' => $isPrivate,
                'private_defined' => $privateDefined,
            ];
        }

        return $feeds;
    }
}
