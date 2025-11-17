<?php

declare(strict_types=1);

namespace RSS;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client wrapper with SSRF protections and conditional requests.
 */
class Http
{
    private Client $client;

    /**
     * @var array<string, string>
     */
    private array $defaultHeaders = [];

    /**
     * @var array<int, string>
     */
    private array $allowedSchemes = [];

    /**
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->allowedSchemes = $this->normalizeSchemes($this->config['allowed_schemes'] ?? ['http', 'https']);

        $this->defaultHeaders = [
            'User-Agent' => $config['user_agent'] ?? 'RSS Aggregator/1.0',
            'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.1',
        ];

        if (!empty($config['default_headers']) && is_array($config['default_headers'])) {
            foreach ($config['default_headers'] as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $this->defaultHeaders[$key] = (string) $value;
            }
        }

        $redirectConfig = [
            'max' => 5,
            'strict' => true,
            'referer' => true,
            'on_redirect' => function ($request, $response, $uri): void {
                Security::assertSafeUrl((string) $uri, $this->allowedSchemes);
            },
        ];

        if (!empty($config['redirects']) && is_array($config['redirects'])) {
            $redirectConfig = array_merge($redirectConfig, $config['redirects']);
            $redirectConfig['on_redirect'] = $redirectConfig['on_redirect'] ?? function ($request, $response, $uri): void {
                Security::assertSafeUrl((string) $uri, $this->allowedSchemes);
            };
        }

        $clientOptions = [
            'timeout' => $config['timeout'] ?? 20,
            'connect_timeout' => $config['connect_timeout'] ?? 10,
            'headers' => $this->defaultHeaders,
            'allow_redirects' => $redirectConfig,
            'verify' => $config['verify'] ?? true,
        ];

        if (!empty($config['proxy'])) {
            $clientOptions['proxy'] = $config['proxy'];
        }

        $this->client = new Client($clientOptions);
    }

    /**
     * Perform a GET request while enforcing SSRF protections.
     *
     * @param array<string, string> $headers
     * @throws Exception
     */
    public function get(string $url, array $headers = [], array $options = []): ResponseInterface
    {
        $response = $this->requestWithRetry('GET', $url, $headers, $options);
        return $this->enforceBodyLimit($response);
    }

    /**
     * Perform a HEAD request for link verification.
     *
     * @param array<string, string> $headers
     * @throws Exception
     */
    public function head(string $url, array $headers = [], array $options = []): ResponseInterface
    {
        return $this->requestWithRetry('HEAD', $url, $headers, $options);
    }

    /**
     * @param array<int, string>|string $schemes
     *
     * @return array<int, string>
     */
    private function normalizeSchemes($schemes): array
    {
        $result = [];
        if (is_string($schemes)) {
            $schemes = [$schemes];
        }

        if (is_array($schemes)) {
            foreach ($schemes as $scheme) {
                if (!is_string($scheme)) {
                    continue;
                }
                $scheme = strtolower($scheme);
                if ($scheme !== '') {
                    $result[] = $scheme;
                }
            }
        }

        if ($result === []) {
            $result = ['http', 'https'];
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws Exception
     */
    private function requestWithRetry(string $method, string $url, array $headers = [], array $options = []): ResponseInterface
    {
        Security::assertSafeUrl($url, $this->allowedSchemes);

        $retries = max(0, (int) ($this->config['retries'] ?? 0));
        $attempt = 0;
        $lastException = null;

        do {
            try {
                $requestOptions = $this->prepareRequestOptions($headers, $options);
                $effectiveUri = null;
                $previousOnStats = $requestOptions['on_stats'] ?? null;
                $requestOptions['on_stats'] = function (TransferStats $stats) use (&$effectiveUri, $previousOnStats): void {
                    $uri = $stats->getEffectiveUri();
                    if ($uri !== null) {
                        $effectiveUri = (string) $uri;
                    }
                    if (is_callable($previousOnStats)) {
                        $previousOnStats($stats);
                    }
                };
                $response = $this->client->request($method, $url, $requestOptions);

                if ($effectiveUri !== null && $effectiveUri !== '') {
                    $response = $response->withHeader('X-RSS-Effective-Url', $effectiveUri);
                }

                $redirectHistory = $this->extractRedirectHistory($response);
                if ($redirectHistory !== []) {
                    $response = $response->withHeader('X-RSS-Redirect-History', $redirectHistory);
                    $response = $response->withHeader('X-RSS-Redirect-Count', (string) count($redirectHistory));
                }

                $status = $response->getStatusCode();
                if ($status === 304) {
                    return $response;
                }

                if ($this->shouldRetryForStatus($status) && $attempt < $retries) {
                    $this->sleepBeforeRetry($attempt, $response);
                    $attempt++;
                    continue;
                }

                if ($status >= 400) {
                    throw new Exception(sprintf('HTTP %s request failed with status %d', $method, $status));
                }

                return $response;
            } catch (RequestException $e) {
                $lastException = $e;
                $response = $e->getResponse();
                if ($response instanceof ResponseInterface && $this->shouldRetryForStatus($response->getStatusCode()) && $attempt < $retries) {
                    $this->sleepBeforeRetry($attempt, $response);
                    $attempt++;
                    continue;
                }
                if ($this->shouldRetryForException($e) && $attempt < $retries) {
                    $this->sleepBeforeRetry($attempt, null);
                    $attempt++;
                    continue;
                }
                throw new Exception('HTTP request failed: ' . $e->getMessage(), 0, $e);
            } catch (ConnectException $e) {
                $lastException = $e;
                if ($attempt < $retries) {
                    $this->sleepBeforeRetry($attempt, null);
                    $attempt++;
                    continue;
                }
                throw new Exception('HTTP connection failed: ' . $e->getMessage(), 0, $e);
            } catch (GuzzleException $e) {
                $lastException = $e;
                if ($this->shouldRetryForException($e) && $attempt < $retries) {
                    $this->sleepBeforeRetry($attempt, null);
                    $attempt++;
                    continue;
                }
                throw new Exception('HTTP request failed: ' . $e->getMessage(), 0, $e);
            }
        } while ($attempt <= $retries);

        throw new Exception('HTTP request exhausted retries.', 0, $lastException);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function prepareRequestOptions(array $headers, array $options): array
    {
        $requestOptions = $options;
        $optionHeaders = [];
        if (isset($requestOptions['headers']) && is_array($requestOptions['headers'])) {
            foreach ($requestOptions['headers'] as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $optionHeaders[$key] = (string) $value;
            }
        }

        $requestOptions['headers'] = $this->mergeHeaders($headers, $optionHeaders);
        $requestOptions['http_errors'] = false;

        if (array_key_exists('auth', $requestOptions)) {
            $normalized = $this->normalizeAuthOption($requestOptions['auth']);
            if ($normalized === null) {
                unset($requestOptions['auth']);
            } else {
                $requestOptions['auth'] = $normalized;
            }
        }

        if (!array_key_exists('track_redirects', $requestOptions)) {
            $requestOptions['track_redirects'] = $this->shouldTrackRedirects($requestOptions);
        }

        return $requestOptions;
    }

    /**
     * @param array<string, string> $override
     * @param array<string, string> $base
     *
     * @return array<string, string>
     */
    private function mergeHeaders(array $override, array $base = []): array
    {
        $merged = $this->defaultHeaders;
        foreach ($base as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $merged[$key] = (string) $value;
        }

        foreach ($override as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $merged[$key] = (string) $value;
        }
        return $merged;
    }

    /**
     * @param mixed $auth
     *
     * @return array<int, string>|null
     */
    private function normalizeAuthOption($auth): ?array
    {
        if (is_array($auth)) {
            $username = $auth[0] ?? null;
            if (!is_string($username)) {
                return null;
            }
            $password = '';
            if (array_key_exists(1, $auth)) {
                $password = (string) $auth[1];
            }
            return [(string) $username, $password];
        }

        if (is_string($auth) && str_contains($auth, ':')) {
            [$username, $password] = explode(':', $auth, 2);
            return [$username, $password];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $requestOptions
     */
    private function shouldTrackRedirects(array $requestOptions): bool
    {
        if (array_key_exists('allow_redirects', $requestOptions)) {
            $allow = $requestOptions['allow_redirects'];
            if ($allow === false) {
                return false;
            }
            if (is_array($allow) && array_key_exists('max', $allow)) {
                return (int) $allow['max'] > 0;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function extractRedirectHistory(ResponseInterface $response): array
    {
        $history = $response->getHeader('X-RSS-Redirect-History');
        if ($history === []) {
            $history = $response->getHeader('X-Guzzle-Redirect-History');
        }

        if ($history === []) {
            return [];
        }

        $normalized = [];
        foreach ($history as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $parts = preg_split('/\s*(?:,|\n)\s*/', $entry) ?: [];
            foreach (array_filter(array_map('trim', $parts)) as $value) {
                if ($value !== '') {
                    $normalized[] = $value;
                }
            }
        }

        return $normalized;
    }

    private function shouldRetryForStatus(int $status): bool
    {
        if ($status === 429) {
            return true;
        }

        return $status >= 500 && $status < 600;
    }

    private function shouldRetryForException(GuzzleException $exception): bool
    {
        return $exception instanceof ConnectException;
    }

    private function sleepBeforeRetry(int $attempt, ?ResponseInterface $response): void
    {
        $delayMs = $this->calculateRetryDelay($attempt);
        if ($response instanceof ResponseInterface) {
            $retryAfter = $this->extractRetryAfterDelay($response);
            if ($retryAfter !== null) {
                $delayMs = max($delayMs, $retryAfter);
            }
        }

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function calculateRetryDelay(int $attempt): int
    {
        $base = (int) ($this->config['retry_delay_ms'] ?? 0);
        if ($base <= 0) {
            $base = 500;
        }

        $factor = (float) ($this->config['retry_backoff_factor'] ?? 2.0);
        if ($factor < 1.0) {
            $factor = 1.0;
        }

        $maxDelay = (int) ($this->config['retry_max_delay_ms'] ?? ($base * 8));
        if ($maxDelay <= 0) {
            $maxDelay = $base * 8;
        }

        $calculated = (int) round($base * pow($factor, $attempt));
        if ($calculated > $maxDelay) {
            $calculated = $maxDelay;
        }

        return max($base, $calculated);
    }

    private function extractRetryAfterDelay(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');
        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            $seconds = (int) $header;
            return $seconds > 0 ? $seconds * 1000 : null;
        }

        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }

        $diff = ($timestamp - time()) * 1000;
        return $diff > 0 ? (int) $diff : null;
    }

    private function enforceBodyLimit(ResponseInterface $response): ResponseInterface
    {
        $max = (int) ($this->config['max_body_size'] ?? 0);
        if ($max <= 0) {
            if ($response->getBody()->isSeekable()) {
                $response->getBody()->rewind();
            }
            return $response;
        }

        $lengthHeader = $response->getHeaderLine('Content-Length');
        if ($lengthHeader !== '' && (int) $lengthHeader > $max) {
            throw new Exception('HTTP response exceeds configured maximum size.');
        }

        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $buffer = '';
        $read = 0;
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $read += strlen($chunk);
            if ($read > $max) {
                $stream->close();
                throw new Exception('HTTP response exceeded maximum body size.');
            }
            $buffer .= $chunk;
        }

        $stream->close();

        return $response->withBody(Utils::streamFor($buffer));
    }
}
