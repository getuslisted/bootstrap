<?php

declare(strict_types=1);

namespace RSS;

use JsonException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Storage helpers for SQLite access and JSONL writes.
 */
class Storage
{
    /**
     * @var array<string, mixed>
     */
    private static array $config = [];

    private static ?PDO $pdo = null;

    /**
     * Initialize storage configuration and ensure database tables exist.
     *
     * @param array<string, mixed> $config
     */
    public static function initialize(array $config): void
    {
        self::$config = $config;
        self::getPdo();
    }

    /**
     * Obtain the shared PDO connection.
     */
    public static function getPdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dbPath = self::$config['paths']['database'] ?? null;
        if (!is_string($dbPath)) {
            throw new RuntimeException('Database path is not configured.');
        }

        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create database directory.');
        }

        try {
            $pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to open SQLite database: ' . $e->getMessage(), 0, $e);
        }

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        self::migrate($pdo);
        self::$pdo = $pdo;

        return $pdo;
    }

    /**
     * Create tables if they do not exist.
     */
    private static function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS feeds (
            id TEXT PRIMARY KEY,
            url TEXT NOT NULL,
            title TEXT,
            etag TEXT,
            last_modified TEXT,
            status TEXT,
            last_seen_at INTEGER,
            fetch_interval_sec INTEGER DEFAULT 900,
            last_fetch_ts INTEGER,
            is_paused INTEGER DEFAULT 0,
            paused_at INTEGER,
            language TEXT,
            language_locked INTEGER DEFAULT 0,
            http_username TEXT,
            http_password TEXT,
            http_headers TEXT,
            is_private INTEGER DEFAULT 0,
            notes TEXT,
            error_streak INTEGER DEFAULT 0,
            success_streak INTEGER DEFAULT 0,
            backoff_until_ts INTEGER
        )');

        self::ensureColumn($pdo, 'feeds', 'is_paused', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'feeds', 'paused_at', 'INTEGER');
        self::ensureColumn($pdo, 'feeds', 'language', 'TEXT');
        self::ensureColumn($pdo, 'feeds', 'language_locked', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'feeds', 'http_username', 'TEXT');
        self::ensureColumn($pdo, 'feeds', 'http_password', 'TEXT');
        self::ensureColumn($pdo, 'feeds', 'http_headers', 'TEXT');
        self::ensureColumn($pdo, 'feeds', 'is_private', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'feeds', 'notes', 'TEXT');
        self::ensureColumn($pdo, 'feeds', 'error_streak', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'feeds', 'success_streak', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'feeds', 'backoff_until_ts', 'INTEGER');

        $pdo->exec('CREATE TABLE IF NOT EXISTS items (
            uid TEXT PRIMARY KEY,
            feed_id TEXT NOT NULL,
            url TEXT NOT NULL UNIQUE,
            title TEXT,
            published_ts INTEGER,
            is_dead INTEGER DEFAULT 0,
            last_verified_ts INTEGER,
            fail_count INTEGER DEFAULT 0
        )');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_feed_published ON items(feed_id, published_ts DESC)');
    }

    /**
     * Retrieve the configuration that seeded the storage layer.
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        if (self::$config === []) {
            throw new RuntimeException('Storage has not been initialized with configuration.');
        }

        return self::$config;
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            throw new RuntimeException('Invalid table or column name.');
        }

        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        if ($stmt === false) {
            throw new RuntimeException('Unable to inspect table: ' . $table);
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['name']) && $row['name'] === $column) {
                return;
            }
        }

        $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }

    /**
     * Ensure the JSONL directory for the given feed exists.
     */
    public static function ensureFeedStorage(string $feedId): string
    {
        $base = self::$config['paths']['storage'] . '/feeds/' . $feedId;
        if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
            throw new RuntimeException('Unable to create feed storage directory: ' . $base);
        }

        return $base;
    }

    /**
     * Append a JSON encoded line for an item.
     *
     * @param array<string, mixed> $data
     */
    public static function appendJsonLine(string $feedId, array $data): void
    {
        $dir = self::ensureFeedStorage($feedId);
        $file = $dir . '/items.jsonl';
        $fh = fopen($file, 'ab');
        if ($fh === false) {
            throw new RuntimeException('Unable to open JSONL file for writing: ' . $file);
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new RuntimeException('Failed to lock JSONL file: ' . $file);
            }

            $encoded = json_encode($data, JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($fh, $encoded) === false) {
                throw new RuntimeException('Failed to write JSONL record: ' . $file);
            }
            if (!fflush($fh)) {
                throw new RuntimeException('Failed to flush JSONL record: ' . $file);
            }
        } finally {
            if (is_resource($fh)) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
    }

    /**
     * Write content atomically by using a temporary file then rename.
     */
    public static function writeAtomic(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create directory for ' . $path);
        }

        $tmp = tempnam($dir, 'rss');
        if ($tmp === false) {
            throw new RuntimeException('Failed to create temporary file.');
        }

        if (file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            throw new RuntimeException('Failed to write temporary file.');
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to replace target file: ' . $path);
        }
        @chmod($path, 0644);
    }

    /**
     * Iterate JSONL records for a feed.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public static function iterateJsonLines(string $feedId): iterable
    {
        $path = self::$config['paths']['storage'] . '/feeds/' . $feedId . '/items.jsonl';
        if (!is_file($path)) {
            return [];
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }

        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                yield $decoded;
            } catch (\JsonException $e) {
                // Skip malformed lines but keep processing
                continue;
            }
        }
        fclose($fh);
    }

    /**
     * Load recent JSONL records in reverse order for the provided feed.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function loadRecentItems(string $feedId, int $limit = 200, ?int $minTimestamp = null): array
    {
        $limit = max(1, $limit);
        $path = self::$config['paths']['storage'] . '/feeds/' . $feedId . '/items.jsonl';
        if (!is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $items = [];
        $seen = [];
        $buffer = '';
        $chunkSize = 4096;
        $cutoffReached = false;

        if (fseek($handle, 0, SEEK_END) !== 0) {
            fclose($handle);
            return [];
        }

        $position = ftell($handle);
        if ($position === false) {
            fclose($handle);
            return [];
        }

        while ($position > 0 && count($items) < $limit && !$cutoffReached) {
            $readSize = (int) min($chunkSize, $position);
            $position -= $readSize;
            if (fseek($handle, $position, SEEK_SET) !== 0) {
                break;
            }
            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }
            $buffer = $chunk . $buffer;
            $lines = explode("\n", $buffer);
            $buffer = array_shift($lines) ?? '';
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (count($items) >= $limit) {
                    break;
                }
                $line = trim($lines[$i]);
                if ($line === '') {
                    continue;
                }

                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    continue;
                }

                if (!is_array($decoded)) {
                    continue;
                }

                $uid = $decoded['uid'] ?? null;
                if (!is_string($uid) || $uid === '' || isset($seen[$uid])) {
                    continue;
                }

                $timestamp = self::resolveItemTimestamp($decoded);
                if ($minTimestamp !== null && $timestamp !== null && $timestamp < $minTimestamp) {
                    $cutoffReached = true;
                    break 2;
                }

                $items[] = $decoded;
                $seen[$uid] = true;
            }
        }

        if (!$cutoffReached && $buffer !== '' && count($items) < $limit) {
            $line = trim($buffer);
            if ($line !== '') {
                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    $decoded = null;
                }
                if (is_array($decoded)) {
                    $uid = $decoded['uid'] ?? null;
                    if (is_string($uid) && $uid !== '' && !isset($seen[$uid])) {
                        $timestamp = self::resolveItemTimestamp($decoded);
                        if ($minTimestamp === null || $timestamp === null || $timestamp >= $minTimestamp) {
                            $items[] = $decoded;
                        }
                    }
                }
            }
        }

        fclose($handle);

        return $items;
    }

    /**
     * Determine the most relevant timestamp for a stored item.
     *
     * @param array<string, mixed> $item
     */
    public static function resolveItemTimestamp(array $item): ?int
    {
        $candidates = [];
        foreach (['published_ts', 'first_seen_ts', 'last_verified_ts'] as $field) {
            if (!array_key_exists($field, $item)) {
                continue;
            }

            $value = $item[$field];
            if (is_int($value)) {
                $candidate = $value;
            } elseif (is_numeric($value)) {
                $candidate = (int) $value;
            } else {
                continue;
            }

            if ($candidate > 0) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates === []) {
            return null;
        }

        return max($candidates);
    }

    /**
     * Load the latest JSONL records for the provided item identifiers.
     *
     * This reads the JSONL file from the end so that only the newest
     * occurrences of each UID are returned, allowing large feeds to be
     * processed without scanning the entire history.
     *
     * @param array<int, string> $uids
     * @return array<string, array<string, mixed>> keyed by UID
     */
    public static function loadItemsByUid(string $feedId, array $uids): array
    {
        if (empty($uids)) {
            return [];
        }

        $path = self::$config['paths']['storage'] . '/feeds/' . $feedId . '/items.jsonl';
        if (!is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $remaining = [];
        foreach ($uids as $uid) {
            if ($uid !== '') {
                $remaining[$uid] = true;
            }
        }

        if (empty($remaining)) {
            fclose($handle);
            return [];
        }

        $found = [];
        $chunkSize = 4096;
        $buffer = '';
        if (fseek($handle, 0, SEEK_END) !== 0) {
            fclose($handle);
            return [];
        }

        $position = ftell($handle);
        if ($position === false) {
            fclose($handle);
            return [];
        }

        while ($position > 0 && !empty($remaining)) {
            $readSize = (int) min($chunkSize, $position);
            $position -= $readSize;
            if (fseek($handle, $position, SEEK_SET) !== 0) {
                break;
            }
            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }
            $buffer = $chunk . $buffer;
            $lines = explode("\n", $buffer);
            $buffer = array_shift($lines) ?? '';
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = trim($lines[$i]);
                if ($line === '') {
                    continue;
                }
                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    continue;
                }
                if (!is_array($decoded)) {
                    continue;
                }
                $uid = $decoded['uid'] ?? null;
                if (!is_string($uid)) {
                    continue;
                }
                if (!isset($remaining[$uid])) {
                    continue;
                }
                $found[$uid] = $decoded;
                unset($remaining[$uid]);
                if (empty($remaining)) {
                    break 2;
                }
            }
        }

        if (!empty($remaining)) {
            $line = trim($buffer);
            if ($line !== '') {
                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    $decoded = null;
                }
                if (is_array($decoded)) {
                    $uid = $decoded['uid'] ?? null;
                    if (is_string($uid) && isset($remaining[$uid])) {
                        $found[$uid] = $decoded;
                        unset($remaining[$uid]);
                    }
                }
            }
        }

        fclose($handle);

        return $found;
    }

    /**
     * Remove JSONL records for the provided item identifiers.
     *
     * @param array<int, string> $uids
     */
    public static function removeItemsFromJsonl(string $feedId, array $uids): void
    {
        $map = [];
        foreach ($uids as $uid) {
            if (is_string($uid) && $uid !== '') {
                $map[$uid] = true;
            }
        }

        if (empty($map)) {
            return;
        }

        $path = self::$config['paths']['storage'] . '/feeds/' . $feedId . '/items.jsonl';
        if (!is_file($path)) {
            return;
        }

        $dir = dirname($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        $tmp = tempnam($dir, 'rss');
        if ($tmp === false) {
            fclose($handle);
            throw new RuntimeException('Failed to create temporary file for pruning JSONL data.');
        }

        $out = fopen($tmp, 'wb');
        if ($out === false) {
            fclose($handle);
            @unlink($tmp);
            throw new RuntimeException('Failed to open temporary file for pruning JSONL data.');
        }

        $success = false;

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    if (fwrite($out, $line) === false) {
                        throw new RuntimeException('Failed to write pruned JSONL record.');
                    }
                    continue;
                }

                try {
                    $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    if (fwrite($out, $line) === false) {
                        throw new RuntimeException('Failed to write fallback JSONL record.');
                    }
                    continue;
                }

                if (!is_array($decoded)) {
                    if (fwrite($out, $line) === false) {
                        throw new RuntimeException('Failed to write fallback JSONL record.');
                    }
                    continue;
                }

                $uid = $decoded['uid'] ?? null;
                if (!is_string($uid) || !isset($map[$uid])) {
                    if (fwrite($out, $line) === false) {
                        throw new RuntimeException('Failed to write retained JSONL record.');
                    }
                }
            }

            if (!fflush($out)) {
                throw new RuntimeException('Failed to flush pruned JSONL data.');
            }

            $success = true;
        } finally {
            fclose($handle);
            fclose($out);
            if (!$success) {
                @unlink($tmp);
            }
        }

        if (!$success) {
            return;
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to finalize pruned JSONL file: ' . $path);
        }

        @chmod($path, 0644);
    }
}
