<?php

declare(strict_types=1);

namespace RSS;

use JsonException;

/**
 * Track cron job executions and expose public status summaries.
 */
class JobStatus
{
    /**
     * Mark a job as running.
     *
     * @param array<string, scalar|null> $context
     */
    public static function markStart(string $job, array $context = []): void
    {
        self::update($job, static function (array $record) use ($context): array {
            $record['last_started_at'] = time();
            $record['status'] = 'running';
            $record['message'] = self::truncateMessage($context['message'] ?? null);
            $record['context'] = self::sanitizeContext($context);
            unset($record['duration_sec']);

            return $record;
        });
    }

    /**
     * Mark a job as completed with the provided status and context.
     *
     * @param array<string, scalar|null> $context
     */
    public static function markFinish(string $job, string $status, array $context = []): void
    {
        self::update($job, static function (array $record) use ($status, $context): array {
            $finishedAt = time();
            $startedAt = isset($record['last_started_at']) ? (int) $record['last_started_at'] : $finishedAt;

            $record['last_started_at'] = $startedAt;
            $record['last_finished_at'] = $finishedAt;
            $record['status'] = $status;
            $record['message'] = self::truncateMessage($context['message'] ?? null);
            $record['context'] = self::sanitizeContext($context);
            $record['duration_sec'] = max(0, $finishedAt - $startedAt);

            return $record;
        });
    }

    /**
     * @return array<string, array<string, scalar|null|array>>
     */
    public static function getAll(): array
    {
        return self::load();
    }

    /**
     * @return array<string, array<string, scalar|null>>
     */
    public static function getPublicSummary(): array
    {
        $data = self::load();
        $summary = [];
        foreach ($data as $job => $info) {
            $summary[$job] = [
                'status' => $info['status'] ?? null,
                'last_started_at' => $info['last_started_at'] ?? null,
                'last_finished_at' => $info['last_finished_at'] ?? null,
                'duration_sec' => $info['duration_sec'] ?? null,
                'message' => $info['message'] ?? null,
                'context' => $info['context'] ?? [],
            ];
        }

        return $summary;
    }

    public static function writePublicStatus(string $path): void
    {
        $summary = self::getPublicSummary();
        Storage::writeAtomic($path, json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * Apply an update callback to the job record and persist it.
     *
     * @param callable(array<string, scalar|null|array>):array<string, scalar|null|array> $callback
     */
    private static function update(string $job, callable $callback): void
    {
        $lock = Lock::acquire('job-status');

        try {
            $data = self::load();
            $record = $data[$job] ?? [];
            $record = $callback($record);
            $data[$job] = $record;
            $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            Storage::writeAtomic(self::getStoragePath(), $encoded);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, array<string, scalar|null|array>>
     */
    private static function load(): array
    {
        $path = self::getStoragePath();
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function getStoragePath(): string
    {
        $config = Storage::getConfig();

        return $config['paths']['storage'] . '/index/job_status.json';
    }

    private static function truncateMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return mb_substr($message, 0, 240);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, scalar|null>
     */
    private static function sanitizeContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) ? mb_substr($value, 0, 240) : $value;
            }
        }

        return $clean;
    }
}
