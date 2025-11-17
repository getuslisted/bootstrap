<?php

declare(strict_types=1);

namespace RSS;

use RuntimeException;

/**
 * Simple filesystem lock to prevent overlapping cron executions.
 */
class Lock
{
    private string $filePath;

    /**
     * @var resource|null
     */
    private $handle;

    private function __construct(string $filePath, $handle)
    {
        $this->filePath = $filePath;
        $this->handle = $handle;
    }

    public function __destruct()
    {
        $this->release();
    }

    public static function acquire(string $name): self
    {
        $config = Storage::getConfig();
        $tmpDir = $config['paths']['storage'] . '/tmp';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Unable to prepare lock directory: ' . $tmpDir);
        }

        $safeName = preg_replace('/[^a-z0-9_-]+/i', '_', $name);
        if ($safeName === null || $safeName === '') {
            throw new RuntimeException('Invalid lock name.');
        }

        $filePath = $tmpDir . '/lock_' . $safeName . '.lock';
        $handle = fopen($filePath, 'c');
        if ($handle === false) {
            throw new RuntimeException('Unable to open lock file: ' . $filePath);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another process is already running for lock: ' . $name);
        }

        fwrite($handle, (string) getmypid());
        fflush($handle);

        return new self($filePath, $handle);
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
        @unlink($this->filePath);
    }
}
