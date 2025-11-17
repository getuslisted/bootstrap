<?php

declare(strict_types=1);

namespace RSS;

use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Feed registry access helpers.
 */
class FeedRegistry
{
    private const DISALLOWED_HEADER_NAMES = [
        'connection',
        'content-length',
        'transfer-encoding',
        'host',
        'proxy-connection',
        'te',
        'upgrade',
        'via',
    ];

    private const BACKOFF_DEFAULTS = [
        'enabled' => true,
        'multiplier' => 2.0,
        'max_multiplier' => 8.0,
        'max_interval_sec' => 21600,
        'min_interval_sec' => 300,
    ];

    private PDO $pdo;

    /**
     * @var array<string, float|int|bool>
     */
    private array $backoffConfig;

    public function __construct()
    {
        $this->pdo = Storage::getPdo();
        $config = [];
        try {
            $config = Storage::getConfig();
        } catch (RuntimeException $e) {
            $config = [];
        }

        $backoff = [];
        if (isset($config['scheduling']) && is_array($config['scheduling'])) {
            $schedule = $config['scheduling'];
            if (isset($schedule['backoff']) && is_array($schedule['backoff'])) {
                $backoff = $schedule['backoff'];
            }
        }

        $this->backoffConfig = self::normalizeBackoffConfig($backoff);
    }


    /**
     * Normalize a header name into canonical case.
     */
    private static function canonicalizeHeaderName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return $name;
        }

        $segments = explode('-', $name);
        $normalized = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $lower = strtolower($segment);
            $normalized[] = strtoupper(substr($lower, 0, 1)) . substr($lower, 1);
        }

        return $normalized === [] ? trim($name) : implode('-', $normalized);
    }

    /**
     * Normalize custom HTTP headers into a sanitized associative array.
     *
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    public static function normalizeHttpHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]*$/', $name)) {
                throw new InvalidArgumentException('Invalid HTTP header name: ' . $name);
            }

            $lower = strtolower($name);
            if (in_array($lower, self::DISALLOWED_HEADER_NAMES, true)) {
                throw new InvalidArgumentException('The HTTP header is not permitted: ' . $name);
            }

            if ($value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new InvalidArgumentException('HTTP header values must be scalar for ' . $name);
            }
            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                throw new InvalidArgumentException('HTTP header value cannot be empty for ' . $name);
            }
            if (preg_match('/[\r\n]/', $stringValue)) {
                throw new InvalidArgumentException('HTTP header value contains invalid characters for ' . $name);
            }

            $normalized[self::canonicalizeHeaderName($name)] = $stringValue;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $config
     *
     * @return array<string, float|int|bool>
     */
    private static function normalizeBackoffConfig(?array $config): array
    {
        $config = is_array($config) ? $config : [];
        $defaults = self::BACKOFF_DEFAULTS;

        $enabled = array_key_exists('enabled', $config)
            ? (bool) $config['enabled']
            : (bool) $defaults['enabled'];
        $multiplier = array_key_exists('multiplier', $config)
            ? max(1.0, (float) $config['multiplier'])
            : (float) $defaults['multiplier'];
        $maxMultiplier = array_key_exists('max_multiplier', $config)
            ? max(1.0, (float) $config['max_multiplier'])
            : (float) $defaults['max_multiplier'];
        if ($maxMultiplier < $multiplier) {
            $maxMultiplier = $multiplier;
        }

        $maxInterval = array_key_exists('max_interval_sec', $config)
            ? (int) $config['max_interval_sec']
            : (int) $defaults['max_interval_sec'];
        if ($maxInterval < 300) {
            $maxInterval = 300;
        }

        $minInterval = array_key_exists('min_interval_sec', $config)
            ? (int) $config['min_interval_sec']
            : (int) $defaults['min_interval_sec'];
        if ($minInterval < 300) {
            $minInterval = 300;
        }

        if ($maxInterval < $minInterval) {
            $maxInterval = $minInterval;
        }

        return [
            'enabled' => $enabled,
            'multiplier' => $multiplier,
            'max_multiplier' => $maxMultiplier,
            'max_interval_sec' => $maxInterval,
            'min_interval_sec' => $minInterval,
        ];
    }

    private function computeBackoffUntil(int $baseInterval, int $errorStreak, int $currentBackoffTs): ?int
    {
        if (empty($this->backoffConfig['enabled'])) {
            return null;
        }

        $errorStreak = max(1, $errorStreak);
        $power = max(0, $errorStreak - 1);
        $multiplier = pow((float) $this->backoffConfig['multiplier'], $power);
        $multiplier = min($multiplier, (float) $this->backoffConfig['max_multiplier']);

        $seconds = (int) round($baseInterval * $multiplier);
        $seconds = max($seconds, max($baseInterval, (int) $this->backoffConfig['min_interval_sec']));
        $seconds = min($seconds, (int) $this->backoffConfig['max_interval_sec']);

        $candidate = time() + $seconds;
        if ($currentBackoffTs > $candidate) {
            $candidate = $currentBackoffTs;
        }

        return $candidate > time() ? $candidate : null;
    }

    /**
     * Parse newline-delimited header definitions in "Name: Value" format.
     *
     * @return array<string, string>
     */
    public static function parseHeaderLines(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return [];
        }

        $lines = preg_split('/\r?\n/', $input);
        if ($lines === false) {
            return [];
        }

        $headers = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                throw new InvalidArgumentException('Header lines must include a colon separator.');
            }
            $name = substr($line, 0, $pos);
            $value = substr($line, $pos + 1);
            $headers[$name] = $value;
        }

        return self::normalizeHttpHeaders($headers);
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array<string, string>
     */
    private static function extractHttpHeaders(?array $row): array
    {
        if ($row === null || !array_key_exists('http_headers', $row)) {
            return [];
        }

        $raw = $row['http_headers'];
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            try {
                return self::normalizeHttpHeaders($raw);
            } catch (InvalidArgumentException $e) {
                return [];
            }
        }

        if (!is_string($raw)) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $headers = [];
        foreach ($decoded as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            $headers[$name] = $value;
        }

        try {
            return self::normalizeHttpHeaders($headers);
        } catch (InvalidArgumentException $e) {
            return [];
        }
    }

    /**
     * Normalize database feed rows to include decoded header metadata.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function hydrateFeedRow(array $row): array
    {
        $row['http_headers'] = self::extractHttpHeaders($row);
        $row['is_private'] = !empty($row['is_private']);
        $notes = null;
        if (array_key_exists('notes', $row) && $row['notes'] !== null) {
            $notes = self::normalizeNotes((string) $row['notes']);
        }
        $row['notes'] = $notes;
        return $row;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function encodeHttpHeadersForStorage(array $headers): ?string
    {
        if ($headers === []) {
            return null;
        }

        try {
            return json_encode($headers, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to encode HTTP headers for storage.', 0, $e);
        }
    }

    public static function normalizeLanguage(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }

        $language = str_replace('_', '-', trim($language));
        if ($language === '') {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9\-]{2,20}$/', $language)) {
            return null;
        }

        $parts = preg_split('/-+/', $language);
        if ($parts === false) {
            return null;
        }

        $normalized = [];
        foreach ($parts as $index => $part) {
            if ($part === '') {
                continue;
            }
            $partLower = strtolower($part);
            if ($index === 0) {
                $normalized[] = $partLower;
                continue;
            }

            if (strlen($part) === 2) {
                $normalized[] = strtoupper($partLower);
                continue;
            }

            $normalized[] = ucfirst($partLower);
        }

        if ($normalized === []) {
            return null;
        }

        return implode('-', $normalized);
    }

    public static function normalizeNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $notes = str_replace("\r\n", "\n", $notes);
        $notes = str_replace("\r", "\n", $notes);
        $notes = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $notes) ?? '';
        $notes = trim($notes);
        if ($notes === '') {
            return null;
        }
        if (mb_strlen($notes) > 2000) {
            $notes = mb_substr($notes, 0, 2000);
        }

        return $notes;
    }

    /**
     * Build a sanitized HTTP auth summary for UI and status exports.
     *
     * @param array<string, mixed>|null $row
     * @return array{username: string, has_password: bool}|null
     */
    private static function summarizeHttpAuth(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $username = isset($row['http_username']) ? trim((string) $row['http_username']) : '';
        if ($username === '') {
            return null;
        }

        $hasPassword = array_key_exists('http_password', $row) && $row['http_password'] !== null;

        return [
            'username' => $username,
            'has_password' => $hasPassword,
        ];
    }

    /**
     * Extract a base64 encoded HTTP password suitable for metadata storage.
     *
     * @param array<string, mixed>|null $row
     */
    private static function encodeHttpPassword(?array $row): ?string
    {
        if ($row === null) {
            return null;
        }

        if (!array_key_exists('http_password', $row)) {
            return null;
        }

        $password = $row['http_password'];
        if ($password === null) {
            return null;
        }

        $passwordString = (string) $password;
        return base64_encode($passwordString);
    }

    /**
     * Register or update a feed for the provided URL.
     *
     * @return array{id: string, created: bool}
     */
    public function registerFeed(string $url, ?string $title = null, int $interval = 900): array
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('A valid feed URL is required.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '' || !in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Feed URL must use http or https.');
        }

        $interval = max(300, $interval);
        $feedId = hash('sha256', strtolower($url));
        $existing = $this->getFeed($feedId);
        $created = $existing === null;
        $now = time();

        $authSummary = self::summarizeHttpAuth($existing);
        $authPasswordB64 = self::encodeHttpPassword($existing);
        $headers = self::extractHttpHeaders($existing);

        $this->upsertFeed([
            'id' => $feedId,
            'url' => $url,
            'title' => $title !== null && $title !== ''
                ? $title
                : ($existing['title'] ?? null),
            'status' => $created ? 'pending' : ($existing['status'] ?? 'ok'),
            'fetch_interval_sec' => $interval,
            'last_fetch_ts' => $created ? 0 : ($existing['last_fetch_ts'] ?? null),
            'last_seen_at' => $existing['last_seen_at'] ?? $now,
            'etag' => $existing['etag'] ?? null,
            'last_modified' => $existing['last_modified'] ?? null,
            'is_paused' => (int) ($existing['is_paused'] ?? 0),
            'paused_at' => $existing['paused_at'] ?? null,
            'http_username' => $existing['http_username'] ?? null,
            'http_password' => $existing['http_password'] ?? null,
            'http_headers' => $headers,
            'is_private' => !empty($existing['is_private']) ? 1 : 0,
            'notes' => $existing['notes'] ?? null,
            'error_streak' => isset($existing['error_streak']) ? (int) $existing['error_streak'] : 0,
            'success_streak' => isset($existing['success_streak']) ? (int) $existing['success_streak'] : 0,
            'backoff_until_ts' => isset($existing['backoff_until_ts']) ? $existing['backoff_until_ts'] : null,
        ]);

        $currentLanguage = $existing !== null ? self::normalizeLanguage($existing['language'] ?? null) : null;
        $languageLocked = !empty($existing['language_locked']);

        $this->updateFeedMetadata($feedId, [
            'url' => $url,
            'title' => $title !== null && $title !== ''
                ? $title
                : ($existing['title'] ?? null),
            'language' => $currentLanguage,
            'language_locked' => $languageLocked,
            'http_auth' => $authSummary,
            'http_auth_password_b64' => $authPasswordB64,
            'http_headers' => $headers,
            'is_private' => !empty($existing['is_private']),
            'notes' => $existing['notes'] ?? null,
        ]);

        return [
            'id' => $feedId,
            'created' => $created,
        ];
    }

    /**
     * Update persisted metadata for a feed.
     *
     * @param array<string, mixed> $data
     */
    public function updateFeedMetadata(string $feedId, array $data): void
    {
        $dir = Storage::ensureFeedStorage($feedId);
        $path = $dir . '/feed.json';
        $existing = [];

        if (is_file($path)) {
            try {
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            } catch (JsonException $e) {
                $existing = [];
            }
        }

        $now = time();
        $existing['id'] = $existing['id'] ?? $feedId;
        if (!isset($existing['created_at'])) {
            $existing['created_at'] = $now;
        }

        foreach ($data as $key => $value) {
            $existing[$key] = $value;
        }

        $existing['updated_at'] = $now;

        Storage::writeAtomic($path, json_encode($existing, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    public function setLanguagePreference(string $feedId, ?string $language, bool $locked): void
    {
        $feed = $this->getFeed($feedId);
        if ($feed === null) {
            throw new InvalidArgumentException('Feed not found: ' . $feedId);
        }

        $normalized = self::normalizeLanguage($language);
        if ($locked && $normalized === null) {
            throw new InvalidArgumentException('A valid language code is required when locking a feed language.');
        }

        $lockedValue = $locked ? 1 : 0;
        $stmt = $this->pdo->prepare('UPDATE feeds SET language = :language, language_locked = :locked WHERE id = :id');
        $stmt->execute([
            ':language' => $normalized,
            ':locked' => $lockedValue,
            ':id' => $feedId,
        ]);

        $this->updateFeedMetadata($feedId, [
            'language' => $normalized,
            'language_locked' => $locked,
        ]);
    }

    public function applyDetectedLanguage(array $feed, ?string $language): ?string
    {
        $normalized = self::normalizeLanguage($language);
        $current = isset($feed['language']) ? self::normalizeLanguage((string) $feed['language']) : null;

        if (!empty($feed['language_locked'])) {
            return $current;
        }

        if ($normalized === null) {
            return $current;
        }

        if ($current === $normalized) {
            return $current;
        }

        $stmt = $this->pdo->prepare('UPDATE feeds SET language = :language, language_locked = 0 WHERE id = :id');
        $stmt->execute([
            ':language' => $normalized,
            ':id' => $feed['id'],
        ]);

        $this->updateFeedMetadata($feed['id'], [
            'language' => $normalized,
            'language_locked' => false,
        ]);

        return $normalized;
    }

    public function setHttpHeaders(string $feedId, ?array $headers): void
    {
        $feed = $this->getFeed($feedId);
        if ($feed === null) {
            throw new InvalidArgumentException('Feed not found: ' . $feedId);
        }

        $normalized = [];
        if ($headers !== null) {
            $normalized = self::normalizeHttpHeaders($headers);
        }

        $encoded = self::encodeHttpHeadersForStorage($normalized);

        $stmt = $this->pdo->prepare('UPDATE feeds SET http_headers = :headers WHERE id = :id');
        $stmt->execute([
            ':headers' => $encoded,
            ':id' => $feedId,
        ]);

        $this->updateFeedMetadata($feedId, [
            'http_headers' => $normalized,
        ]);
    }

    public function setHttpCredentials(string $feedId, ?string $username, ?string $password): void
    {
        $feed = $this->getFeed($feedId);
        if ($feed === null) {
            throw new InvalidArgumentException('Feed not found: ' . $feedId);
        }

        $username = $username !== null ? trim($username) : null;
        if ($username === '') {
            $username = null;
        }

        if ($username === null) {
            $password = null;
        }

        $stmt = $this->pdo->prepare('UPDATE feeds SET http_username = :username, http_password = :password WHERE id = :id');
        $stmt->execute([
            ':username' => $username,
            ':password' => $password,
            ':id' => $feedId,
        ]);

        $this->updateFeedMetadata($feedId, [
            'http_auth' => self::summarizeHttpAuth([
                'http_username' => $username,
                'http_password' => $password,
            ]),
            'http_auth_password_b64' => $password !== null ? base64_encode($password) : null,
        ]);
    }

    public function setPrivacy(string $feedId, bool $isPrivate): void
    {
        $feed = $this->getFeed($feedId);
        if ($feed === null) {
            throw new InvalidArgumentException('Feed not found: ' . $feedId);
        }

        $value = $isPrivate ? 1 : 0;
        $stmt = $this->pdo->prepare('UPDATE feeds SET is_private = :private WHERE id = :id');
        $stmt->execute([
            ':private' => $value,
            ':id' => $feedId,
        ]);

        $this->updateFeedMetadata($feedId, [
            'is_private' => $isPrivate,
        ]);
    }

    public function setNotes(string $feedId, ?string $notes): void
    {
        $feed = $this->getFeed($feedId);
        if ($feed === null) {
            throw new InvalidArgumentException('Feed not found: ' . $feedId);
        }

        $normalized = self::normalizeNotes($notes);

        $stmt = $this->pdo->prepare('UPDATE feeds SET notes = :notes WHERE id = :id');
        $stmt->execute([
            ':notes' => $normalized,
            ':id' => $feedId,
        ]);

        $this->updateFeedMetadata($feedId, [
            'notes' => $normalized,
        ]);
    }

    /**
     * Create or update a feed definition.
     *
     * @param array<string, mixed> $data
     */
    public function upsertFeed(array $data): void
    {
        $sql = 'INSERT INTO feeds (id, url, title, etag, last_modified, status, last_seen_at, fetch_interval_sec, last_fetch_ts, is_paused, paused_at, http_username, http_password, http_headers, is_private, notes, error_streak, success_streak, backoff_until_ts)'
            . ' VALUES (:id, :url, :title, :etag, :last_modified, :status, :last_seen_at, :fetch_interval_sec, :last_fetch_ts, :is_paused, :paused_at, :http_username, :http_password, :http_headers, :is_private, :notes, :error_streak, :success_streak, :backoff_until)'
            . ' ON CONFLICT(id) DO UPDATE SET'
            . ' url=excluded.url,'
            . ' title=excluded.title,'
            . ' etag=excluded.etag,'
            . ' last_modified=excluded.last_modified,'
            . ' status=excluded.status,'
            . ' last_seen_at=excluded.last_seen_at,'
            . ' fetch_interval_sec=excluded.fetch_interval_sec,'
            . ' last_fetch_ts=excluded.last_fetch_ts,'
            . ' is_paused=excluded.is_paused,'
            . ' paused_at=excluded.paused_at,'
            . ' http_username=excluded.http_username,'
            . ' http_password=excluded.http_password,'
            . ' http_headers=excluded.http_headers,'
            . ' is_private=excluded.is_private,'
            . ' notes=excluded.notes,'
            . ' error_streak=excluded.error_streak,'
            . ' success_streak=excluded.success_streak,'
            . ' backoff_until_ts=excluded.backoff_until_ts';

        $existing = $this->getFeed((string) $data['id']);
        $headersProvided = array_key_exists('http_headers', $data);
        if ($headersProvided) {
            $rawHeaders = $data['http_headers'];
            if (is_array($rawHeaders)) {
                $normalizedHeaders = self::normalizeHttpHeaders($rawHeaders);
            } elseif (is_string($rawHeaders) && $rawHeaders !== '') {
                $normalizedHeaders = self::extractHttpHeaders(['http_headers' => $rawHeaders]);
            } else {
                $normalizedHeaders = [];
            }
        } else {
            $normalizedHeaders = self::extractHttpHeaders($existing);
        }

        $notes = null;
        if (array_key_exists('notes', $data)) {
            $notesValue = $data['notes'];
            if ($notesValue === null) {
                $notes = null;
            } elseif (is_string($notesValue)) {
                $notes = self::normalizeNotes($notesValue);
            } elseif (is_scalar($notesValue)) {
                $notes = self::normalizeNotes((string) $notesValue);
            } else {
                $notes = null;
            }
        } elseif ($existing !== null && isset($existing['notes'])) {
            $existingNotes = $existing['notes'];
            $notes = is_string($existingNotes) ? self::normalizeNotes($existingNotes) : null;
        }

        $encodedHeaders = self::encodeHttpHeadersForStorage($normalizedHeaders);

        $errorStreak = array_key_exists('error_streak', $data)
            ? max(0, (int) $data['error_streak'])
            : (isset($existing['error_streak']) ? max(0, (int) $existing['error_streak']) : 0);
        $successStreak = array_key_exists('success_streak', $data)
            ? max(0, (int) $data['success_streak'])
            : (isset($existing['success_streak']) ? max(0, (int) $existing['success_streak']) : 0);

        $backoffUntil = null;
        if (array_key_exists('backoff_until_ts', $data)) {
            $value = $data['backoff_until_ts'];
            if ($value !== null && $value !== '') {
                $candidate = (int) $value;
                if ($candidate > 0) {
                    $backoffUntil = $candidate;
                }
            }
        } elseif ($existing !== null && isset($existing['backoff_until_ts'])) {
            $candidate = (int) $existing['backoff_until_ts'];
            if ($candidate > 0) {
                $backoffUntil = $candidate;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $data['id'],
            ':url' => $data['url'],
            ':title' => $data['title'] ?? null,
            ':etag' => $data['etag'] ?? null,
            ':last_modified' => $data['last_modified'] ?? null,
            ':status' => $data['status'] ?? null,
            ':last_seen_at' => $data['last_seen_at'] ?? null,
            ':fetch_interval_sec' => $data['fetch_interval_sec'] ?? 900,
            ':last_fetch_ts' => $data['last_fetch_ts'] ?? null,
            ':is_paused' => isset($data['is_paused']) ? (int) $data['is_paused'] : 0,
            ':paused_at' => $data['paused_at'] ?? null,
            ':http_username' => $data['http_username'] ?? null,
            ':http_password' => $data['http_password'] ?? null,
            ':http_headers' => $encodedHeaders,
            ':is_private' => isset($data['is_private']) ? (int) $data['is_private'] : 0,
            ':notes' => $notes,
            ':error_streak' => $errorStreak,
            ':success_streak' => $successStreak,
            ':backoff_until' => $backoffUntil,
        ]);
    }

    /**
     * Delete a feed and associated items.
     */
    public function deleteFeed(string $feedId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM items WHERE feed_id = :id');
        $stmt->execute([':id' => $feedId]);
        $stmt = $this->pdo->prepare('DELETE FROM feeds WHERE id = :id');
        $stmt->execute([':id' => $feedId]);
    }

    /**
     * Retrieve feed rows, optionally filtered by ID.
     *
     * @param array<int, string>|null $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFeeds(?array $ids = null): array
    {
        if ($ids === null || $ids === []) {
            $stmt = $this->pdo->query('SELECT * FROM feeds ORDER BY url');
            $rows = $stmt->fetchAll() ?: [];
            foreach ($rows as $index => $row) {
                if (is_array($row)) {
                    $rows[$index] = self::hydrateFeedRow($row);
                }
            }
            return $rows;
        }

        $ids = array_values(array_unique(array_filter($ids, static function ($value): bool {
            return is_string($value) && $value !== '';
        })));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT * FROM feeds WHERE id IN (' . $placeholders . ') ORDER BY url';
        $stmt = $this->pdo->prepare($sql);
        foreach ($ids as $index => $id) {
            $stmt->bindValue($index + 1, $id, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as $index => $row) {
            if (is_array($row)) {
                $rows[$index] = self::hydrateFeedRow($row);
            }
        }

        $order = array_flip($ids);
        usort($rows, static function (array $a, array $b) use ($order): int {
            $left = $order[$a['id']] ?? PHP_INT_MAX;
            $right = $order[$b['id']] ?? PHP_INT_MAX;
            return $left <=> $right;
        });

        return $rows;
    }

    /**
     * Retrieve feeds along with aggregated item statistics.
     *
     * @return array<string, array<string, mixed>> keyed by feed ID
     */
    public function getFeedStatistics(): array
    {
        $sql = 'SELECT feed_id, COUNT(*) AS total_items, '
            . 'SUM(CASE WHEN is_dead = 1 THEN 1 ELSE 0 END) AS dead_items, '
            . 'MAX(COALESCE(published_ts, last_verified_ts, 0)) AS latest_ts '
            . 'FROM items GROUP BY feed_id';

        $stmt = $this->pdo->query($sql);
        $stats = [];
        while ($row = $stmt->fetch()) {
            $feedId = (string) $row['feed_id'];
            $stats[$feedId] = [
                'total_items' => isset($row['total_items']) ? (int) $row['total_items'] : 0,
                'dead_items' => isset($row['dead_items']) ? (int) $row['dead_items'] : 0,
                'latest_item_ts' => isset($row['latest_ts']) ? (int) $row['latest_ts'] : null,
            ];
        }

        return $stats;
    }

    /**
     * Return status metadata suitable for dashboards and JSON exports.
     *
     * @return array<int, array<string, scalar|null>>
     */
    public function getFeedStatusSummary(): array
    {
        $feeds = $this->listFeeds();
        $stats = $this->getFeedStatistics();
        $summary = [];

        foreach ($feeds as $feed) {
            $feedId = (string) $feed['id'];
            $stat = $stats[$feedId] ?? ['total_items' => 0, 'dead_items' => 0, 'latest_item_ts' => null];
            $interval = max(300, (int) ($feed['fetch_interval_sec'] ?? 900));
            $lastFetch = isset($feed['last_fetch_ts']) ? (int) $feed['last_fetch_ts'] : 0;
            $authSummary = self::summarizeHttpAuth($feed);
            $headers = [];
            if (isset($feed['http_headers']) && is_array($feed['http_headers'])) {
                $headers = $feed['http_headers'];
            }
            $headerCount = is_array($headers) ? count($headers) : 0;
            $notes = null;
            if (isset($feed['notes']) && $feed['notes'] !== null) {
                $notes = is_string($feed['notes']) ? self::normalizeNotes($feed['notes']) : null;
            }

            $errorStreak = isset($feed['error_streak']) ? max(0, (int) $feed['error_streak']) : 0;
            $successStreak = isset($feed['success_streak']) ? max(0, (int) $feed['success_streak']) : 0;
            $backoffUntilRaw = isset($feed['backoff_until_ts']) ? (int) $feed['backoff_until_ts'] : 0;
            $backoffUntil = $backoffUntilRaw > 0 ? $backoffUntilRaw : null;
            $isPaused = !empty($feed['is_paused']);

            $nextFetch = null;
            if (!$isPaused) {
                if ($lastFetch > 0) {
                    $nextFetch = $lastFetch + $interval;
                }
                if ($backoffUntil !== null) {
                    $nextFetch = $nextFetch === null ? $backoffUntil : max($nextFetch, $backoffUntil);
                }
            }

            $summary[] = [
                'id' => $feedId,
                'title' => $feed['title'] ?? null,
                'url' => $feed['url'] ?? null,
                'status' => $feed['status'] ?? null,
                'last_fetch_ts' => $lastFetch > 0 ? $lastFetch : null,
                'last_seen_at' => isset($feed['last_seen_at']) ? (int) $feed['last_seen_at'] : null,
                'fetch_interval_sec' => $interval,
                'next_fetch_ts' => $nextFetch,
                'total_items' => $stat['total_items'],
                'dead_items' => $stat['dead_items'],
                'latest_item_ts' => $stat['latest_item_ts'],
                'is_paused' => $isPaused,
                'paused_at' => isset($feed['paused_at']) && (int) $feed['paused_at'] > 0
                    ? (int) $feed['paused_at']
                    : null,
                'is_private' => !empty($feed['is_private']),
                'language' => self::normalizeLanguage($feed['language'] ?? null),
                'language_locked' => !empty($feed['language_locked']),
                'http_auth' => $authSummary,
                'has_http_credentials' => $authSummary !== null,
                'http_headers' => $headers,
                'has_custom_headers' => $headerCount > 0,
                'custom_headers_count' => $headerCount,
                'notes' => $notes,
                'has_notes' => $notes !== null,
                'error_streak' => $errorStreak,
                'success_streak' => $successStreak,
                'backoff_until_ts' => $backoffUntil,
            ];
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFeed(string $feedId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM feeds WHERE id = :id');
        $stmt->execute([':id' => $feedId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return self::hydrateFeedRow($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param array<int, string>|null $feedIds
     */
    public function getFeedsDueForFetch(int $limit = 100, ?array $feedIds = null, bool $force = false): array
    {
        $now = time();
        $ids = null;
        if ($feedIds !== null) {
            $ids = array_values(array_unique(array_filter($feedIds, static function ($value): bool {
                return is_string($value) && $value !== '';
            })));
        }

        $conditions = ['COALESCE(is_paused, 0) = 0'];
        $params = [];

        if ($ids !== null && $ids !== []) {
            $placeholders = [];
            foreach ($ids as $index => $id) {
                $placeholder = ':id' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $id;
            }
            if ($placeholders !== []) {
                $conditions[] = 'id IN (' . implode(',', $placeholders) . ')';
            }
        }

        $sql = 'SELECT * FROM feeds';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY COALESCE(last_fetch_ts, 0) ASC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $feeds = [];
        while ($row = $stmt->fetch()) {
            if (is_array($row)) {
                $row = self::hydrateFeedRow($row);
            }
            $lastFetch = (int) ($row['last_fetch_ts'] ?? 0);
            $interval = max(300, (int) ($row['fetch_interval_sec'] ?? 900));
            if ($force) {
                $feeds[] = $row;
                continue;
            }

            $backoffUntil = isset($row['backoff_until_ts']) ? (int) $row['backoff_until_ts'] : 0;
            if ($lastFetch === 0 && $backoffUntil === 0) {
                $feeds[] = $row;
                continue;
            }

            $nextFetchAt = 0;
            if ($lastFetch > 0) {
                $nextFetchAt = $lastFetch + $interval;
            }
            if ($backoffUntil > 0) {
                $nextFetchAt = max($nextFetchAt, $backoffUntil);
            }

            if ($nextFetchAt === 0 || $nextFetchAt <= $now) {
                $feeds[] = $row;
            }
        }

        if ($ids !== null && $ids !== []) {
            $order = array_flip($ids);
            usort($feeds, static function (array $a, array $b) use ($order): int {
                $left = $order[$a['id']] ?? PHP_INT_MAX;
                $right = $order[$b['id']] ?? PHP_INT_MAX;
                return $left <=> $right;
            });
        }

        return $feeds;
    }

    public function pauseFeed(string $feedId): void
    {
        $stmt = $this->pdo->prepare('UPDATE feeds SET is_paused = 1, paused_at = :ts, status = :status WHERE id = :id');
        $stmt->execute([
            ':ts' => time(),
            ':status' => 'paused',
            ':id' => $feedId,
        ]);
    }

    public function resumeFeed(string $feedId, bool $queueFetch = true): void
    {
        $stmt = $this->pdo->prepare('UPDATE feeds SET is_paused = 0, paused_at = NULL, status = :status, last_fetch_ts = CASE WHEN :queue = 1 THEN 0 ELSE last_fetch_ts END, error_streak = 0, success_streak = CASE WHEN :queue = 1 THEN 0 ELSE success_streak END, backoff_until_ts = NULL WHERE id = :id');
        $stmt->execute([
            ':status' => $queueFetch ? 'queued' : 'ok',
            ':queue' => $queueFetch ? 1 : 0,
            ':id' => $feedId,
        ]);
    }

    public function recordFetchSuccess(string $feedId, array $meta): void
    {
        $now = time();
        $stmt = $this->pdo->prepare('UPDATE feeds SET etag=:etag, last_modified=:last_modified, last_fetch_ts=:last_fetch_ts, status=:status, last_seen_at=:last_seen_at, title=COALESCE(:title, title), error_streak = 0, success_streak = COALESCE(success_streak, 0) + 1, backoff_until_ts = NULL WHERE id=:id');
        $stmt->execute([
            ':etag' => $meta['etag'] ?? null,
            ':last_modified' => $meta['last_modified'] ?? null,
            ':last_fetch_ts' => $meta['last_fetch_ts'] ?? $now,
            ':status' => $meta['status'] ?? 'ok',
            ':last_seen_at' => $meta['last_seen_at'] ?? $now,
            ':title' => $meta['title'] ?? null,
            ':id' => $feedId,
        ]);
    }

    public function updateFetchMetadata(string $feedId, array $meta): void
    {
        $this->recordFetchSuccess($feedId, $meta);
    }

    public function recordFetchFailure(string $feedId, string $status): void
    {
        $feed = $this->getFeed($feedId);
        if ($feed === null) {
            return;
        }

        $status = trim($status);
        if ($status === '') {
            $status = 'error';
        }
        if (strlen($status) > 255) {
            $status = substr($status, 0, 255);
        }

        $baseInterval = max(300, (int) ($feed['fetch_interval_sec'] ?? 900));
        $previousStreak = isset($feed['error_streak']) ? (int) $feed['error_streak'] : 0;
        $errorStreak = $previousStreak + 1;
        $currentBackoff = isset($feed['backoff_until_ts']) ? (int) $feed['backoff_until_ts'] : 0;
        $backoffUntil = $this->computeBackoffUntil($baseInterval, $errorStreak, $currentBackoff);

        $stmt = $this->pdo->prepare('UPDATE feeds SET status = :status, last_fetch_ts = :last_fetch_ts, error_streak = :error_streak, success_streak = 0, backoff_until_ts = :backoff_until WHERE id = :id');
        $stmt->execute([
            ':status' => $status,
            ':last_fetch_ts' => time(),
            ':error_streak' => $errorStreak,
            ':backoff_until' => $backoffUntil,
            ':id' => $feedId,
        ]);
    }

    public function updateFetchInterval(string $feedId, int $interval): void
    {
        $interval = max(300, $interval);
        $stmt = $this->pdo->prepare('UPDATE feeds SET fetch_interval_sec = :interval WHERE id = :id');
        $stmt->execute([
            ':interval' => $interval,
            ':id' => $feedId,
        ]);
    }

    public function resetLastFetch(string $feedId, ?string $status = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE feeds SET last_fetch_ts = 0, status = COALESCE(:status, status), error_streak = 0, success_streak = 0, backoff_until_ts = NULL WHERE id = :id');
        $stmt->execute([
            ':status' => $status,
            ':id' => $feedId,
        ]);
    }

    /**
     * Insert or update an item record.
     *
     * @param array<string, mixed> $item
     *
     * @throws PDOException
     */
    public function upsertItem(array $item): void
    {
        $sql = 'INSERT INTO items (uid, feed_id, url, title, published_ts, is_dead, last_verified_ts, fail_count)
                VALUES (:uid, :feed_id, :url, :title, :published_ts, :is_dead, :last_verified_ts, :fail_count)
                ON CONFLICT(uid) DO UPDATE SET
                    feed_id=excluded.feed_id,
                    url=excluded.url,
                    title=excluded.title,
                    published_ts=excluded.published_ts,
                    is_dead=excluded.is_dead,
                    last_verified_ts=excluded.last_verified_ts,
                    fail_count=excluded.fail_count';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':uid' => $item['uid'],
            ':feed_id' => $item['feed_id'],
            ':url' => $item['url'],
            ':title' => $item['title'] ?? null,
            ':published_ts' => $item['published_ts'] ?? null,
            ':is_dead' => $item['is_dead'] ?? 0,
            ':last_verified_ts' => $item['last_verified_ts'] ?? null,
            ':fail_count' => $item['fail_count'] ?? 0,
        ]);
    }

    /**
     * Determine whether a PDO exception originated from the unique URL constraint.
     */
    public static function isUniqueUrlViolation(PDOException $exception): bool
    {
        $code = (string) $exception->getCode();
        if ($code !== '' && $code !== '19' && $code !== '23000') {
            return false;
        }

        $message = $exception->getMessage();
        if ($message === '') {
            return false;
        }

        return stripos($message, 'items.url') !== false
            || stripos($message, 'UNIQUE constraint failed: items.url') !== false
            || stripos($message, 'items.url)') !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentItems(string $feedId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM items WHERE feed_id = :feed_id AND is_dead = 0 ORDER BY COALESCE(published_ts, last_verified_ts) DESC LIMIT :limit');
        $stmt->bindValue(':feed_id', $feedId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCombinedItems(int $limit = 200): array
    {
        $sql = 'SELECT items.* FROM items INNER JOIN feeds ON feeds.id = items.feed_id '
            . 'WHERE items.is_dead = 0 AND COALESCE(feeds.is_private, 0) = 0 '
            . 'ORDER BY COALESCE(items.published_ts, items.last_verified_ts) DESC LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Retrieve dead items that are older than the provided timestamp.
     *
     * @return array<int, array{uid:string, feed_id:string}>
     */
    public function getDeadItemsOlderThan(int $threshold, int $limit = 500): array
    {
        $sql = 'SELECT uid, feed_id FROM items WHERE is_dead = 1 '
            . 'AND COALESCE(last_verified_ts, published_ts, 0) <= :threshold '
            . 'ORDER BY COALESCE(last_verified_ts, published_ts, 0) ASC '
            . 'LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':threshold', $threshold, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array<int, string>|string|null $feedId
     *
     * @return array<int, array<string, mixed>>
     */
    public function getItemsDueForVerification(int $limit = 100, $feedId = null, bool $includeDead = false, bool $force = false): array
    {
        $conditions = ['1=1'];
        $params = [];

        if (!$includeDead) {
            $conditions[] = 'items.is_dead = 0';
        }

        if (!$force) {
            $conditions[] = 'COALESCE(feeds.is_paused, 0) = 0';
        }

        if ($feedId !== null && $feedId !== '') {
            if (is_array($feedId)) {
                $ids = array_values(array_unique(array_filter($feedId, static function ($value): bool {
                    return is_string($value) && $value !== '';
                })));
                if ($ids !== []) {
                    $placeholders = [];
                    foreach ($ids as $index => $id) {
                        $placeholder = ':feed_' . $index;
                        $placeholders[] = $placeholder;
                        $params[$placeholder] = $id;
                    }
                    $conditions[] = 'items.feed_id IN (' . implode(',', $placeholders) . ')';
                }
            } else {
                $conditions[] = 'items.feed_id = :feed_id';
                $params[':feed_id'] = (string) $feedId;
            }
        }

        $sql = 'SELECT items.* FROM items INNER JOIN feeds ON feeds.id = items.feed_id '
            . 'WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY COALESCE(items.last_verified_ts, 0) ASC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function getFeedByUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $id = hash('sha256', strtolower($url));
        return $this->getFeed($id);
    }

    public function markItemVerification(string $uid, bool $ok): void
    {
        if ($ok) {
            $stmt = $this->pdo->prepare('UPDATE items SET last_verified_ts = :ts, fail_count = 0, is_dead = 0 WHERE uid = :uid');
            $stmt->execute([
                ':ts' => time(),
                ':uid' => $uid,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE items SET last_verified_ts = :ts, fail_count = fail_count + 1, is_dead = CASE WHEN fail_count + 1 >= 3 THEN 1 ELSE is_dead END WHERE uid = :uid');
        $stmt->execute([
            ':ts' => time(),
            ':uid' => $uid,
        ]);
    }

    public function reviveItem(string $uid): void
    {
        $stmt = $this->pdo->prepare('UPDATE items SET is_dead = 0, fail_count = 0, last_verified_ts = :ts WHERE uid = :uid');
        $stmt->execute([
            ':ts' => time(),
            ':uid' => $uid,
        ]);
    }

    /**
     * Remove items from the index by UID.
     *
     * @param array<int, string> $uids
     */
    public function deleteItemsByUid(array $uids): int
    {
        $filtered = [];
        foreach ($uids as $uid) {
            if (is_string($uid) && $uid !== '') {
                $filtered[] = $uid;
            }
        }

        if (empty($filtered)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($filtered), '?'));
        $stmt = $this->pdo->prepare('DELETE FROM items WHERE uid IN (' . $placeholders . ')');
        $stmt->execute($filtered);

        return $stmt->rowCount();
    }

    /**
     * Retrieve item records for the provided UIDs.
     *
     * @param array<int, string> $uids
     *
     * @return array<int, array<string, mixed>>
     */
    public function getItemsByUid(array $uids): array
    {
        $filtered = [];
        foreach ($uids as $uid) {
            if (is_string($uid) && $uid !== '') {
                $filtered[] = $uid;
            }
        }

        if (empty($filtered)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($filtered), '?'));
        $stmt = $this->pdo->prepare('SELECT * FROM items WHERE uid IN (' . $placeholders . ')');
        $stmt->execute($filtered);

        return $stmt->fetchAll() ?: [];
    }
}
