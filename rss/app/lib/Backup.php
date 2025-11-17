<?php

declare(strict_types=1);

namespace RSS;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Create and manage filesystem backups of the importer state.
 */
final class Backup
{
    private string $basePath;

    private string $storagePath;

    private string $logsPath;

    private string $publicPath;

    private string $backupPath;

    private string $restoreRecordPath;

    /**
     * @param array<string, string> $paths
     */
    public function __construct(array $paths)
    {
        $this->basePath = $this->requirePath($paths, 'base');
        $this->storagePath = $this->requirePath($paths, 'storage');
        $this->logsPath = $this->requirePath($paths, 'logs');
        $this->publicPath = $this->requirePath($paths, 'public');
        $this->backupPath = $this->normalizePath($paths['backups'] ?? ($this->storagePath . '/backups'));

        $this->ensureDirectory($this->backupPath);
        $this->restoreRecordPath = $this->storagePath . '/index/backup_restore.json';
    }

    /**
     * Create a new backup snapshot and return metadata about the archive.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $metadata
     *
     * @return array{name: string, path: string, manifest: array<string, mixed>}
     */
    public function create(array $options = [], array $metadata = []): array
    {
        $labelOriginal = isset($options['label']) ? trim((string) $options['label']) : null;
        $labelSlug = $this->slugify($labelOriginal);
        $timestamp = gmdate('YmdHis');
        $nameParts = ['backup', $timestamp];
        if ($labelSlug !== null) {
            $nameParts[] = $labelSlug;
        }
        $name = implode('-', $nameParts);

        $targetDir = $this->backupPath . '/' . $name;
        $tmpDir = $targetDir . '.tmp';

        if (file_exists($targetDir) || file_exists($tmpDir)) {
            throw new RuntimeException('A backup with this timestamp already exists.');
        }

        $this->ensureDirectory($tmpDir);

        $includeLogs = !empty($options['include_logs']);
        $includeStatus = !empty($options['include_public_status']);

        try {
            $this->copyFile($this->basePath . '/app/config.php', $tmpDir . '/config/config.php');
            $this->copyOptionalFile($this->basePath . '/app/config.local.php', $tmpDir . '/config/config.local.php');

            $this->copyDirectory($this->storagePath, $tmpDir . '/storage', [
                $this->backupPath,
                $this->storagePath . '/backups',
                $this->storagePath . '/tmp',
            ]);

            if ($includeLogs) {
                $this->copyDirectory($this->logsPath, $tmpDir . '/logs');
            }

            if ($includeStatus) {
                $this->copyDirectory($this->publicPath . '/status', $tmpDir . '/public/status');
            }

            $metadata = is_array($metadata) ? $metadata : [];

            $manifest = [
                'version' => 1,
                'name' => $name,
                'created_at' => time(),
                'label' => $labelOriginal,
                'paths' => [
                    'base' => $this->basePath,
                    'storage' => $this->storagePath,
                    'logs' => $this->logsPath,
                    'public' => $this->publicPath,
                    'backups' => $this->backupPath,
                ],
                'options' => [
                    'include_logs' => $includeLogs,
                    'include_public_status' => $includeStatus,
                ],
                'metadata' => $metadata,
                'stats' => $this->computeDirectoryStats($tmpDir),
                'stats_include_manifest' => false,
            ];

            $this->writeJson($tmpDir . '/manifest.json', $manifest);

            if (!rename($tmpDir, $targetDir)) {
                throw new RuntimeException('Failed to finalize backup directory.');
            }

            return [
                'name' => $name,
                'path' => $targetDir,
                'manifest' => $manifest,
            ];
        } catch (Throwable $e) {
            $this->removeDirectory($tmpDir);
            throw $e;
        }
    }

    /**
     * Restore a snapshot either into a temporary extraction directory or the live paths.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function restore(string $name, array $options = []): array
    {
        $name = trim($name, '/');
        if ($name === '') {
            throw new RuntimeException('Backup name is required for restore operations.');
        }

        $source = $this->normalizePath($this->backupPath . '/' . $name);
        if (!is_dir($source)) {
            throw new RuntimeException('Backup not found: ' . $name);
        }

        $manifest = $this->readManifest($source);
        $overwrite = !empty($options['overwrite']);
        $warnings = [];
        $restoredAt = time();

        if (!empty($options['in_place'])) {
            if (empty($options['force'])) {
                throw new RuntimeException('In-place restores require force confirmation.');
            }

            $copied = [];

            if (($options['restore_storage'] ?? true) && is_dir($source . '/storage')) {
                $this->replaceDirectory($source . '/storage', $this->storagePath, $overwrite);
                $copied[] = 'storage';
            }

            if (!empty($options['restore_config']) && is_dir($source . '/config')) {
                $this->replaceDirectory($source . '/config', $this->basePath . '/app', $overwrite);
                $copied[] = 'config';
            }

            if (!empty($options['restore_logs']) && is_dir($source . '/logs')) {
                $this->replaceDirectory($source . '/logs', $this->logsPath, $overwrite);
                $copied[] = 'logs';
            }

            if (!empty($options['restore_public_status']) && is_dir($source . '/public/status')) {
                $this->replaceDirectory($source . '/public/status', $this->publicPath . '/status', $overwrite);
                $copied[] = 'public/status';
            }

            $result = [
                'mode' => 'in_place',
                'source' => $source,
                'target' => $this->basePath,
                'copied' => $copied,
                'manifest' => $manifest,
                'restored_at' => $restoredAt,
            ];

            try {
                $this->recordRestoreMetadata($name, $result);
            } catch (Throwable $recordError) {
                $warnings[] = 'Failed to record restore metadata: ' . $recordError->getMessage();
            }

            if ($warnings !== []) {
                $result['warnings'] = $warnings;
            }

            return $result;
        }

        $targetBase = isset($options['target_base']) ? $this->normalizePath((string) $options['target_base']) : '';
        if ($targetBase === '') {
            $targetBase = $this->storagePath . '/tmp/restore-' . $name . '-' . gmdate('YmdHis');
        }

        if (file_exists($targetBase)) {
            if (!$overwrite) {
                throw new RuntimeException('Target path already exists: ' . $targetBase);
            }
            $this->removeDirectory($targetBase);
        }

        $this->ensureDirectory($targetBase);

        $copied = [];

        if (($options['copy_storage'] ?? true) && is_dir($source . '/storage')) {
            $this->copyDirectory($source . '/storage', $targetBase . '/storage');
            $copied[] = 'storage';
        }

        if (!empty($options['copy_config']) && is_dir($source . '/config')) {
            $this->copyDirectory($source . '/config', $targetBase . '/config');
            $copied[] = 'config';
        }

        if (!empty($options['copy_logs']) && is_dir($source . '/logs')) {
            $this->copyDirectory($source . '/logs', $targetBase . '/logs');
            $copied[] = 'logs';
        }

        if (!empty($options['copy_public_status']) && is_dir($source . '/public/status')) {
            $this->copyDirectory($source . '/public/status', $targetBase . '/public/status');
            $copied[] = 'public/status';
        }

        if (is_file($source . '/manifest.json')) {
            $this->copyFile($source . '/manifest.json', $targetBase . '/manifest.json');
        }

        $result = [
            'mode' => 'extract',
            'source' => $source,
            'target' => $targetBase,
            'copied' => $copied,
            'manifest' => $manifest,
            'restored_at' => $restoredAt,
        ];

        try {
            $this->recordRestoreMetadata($name, $result);
        } catch (Throwable $recordError) {
            $warnings[] = 'Failed to record restore metadata: ' . $recordError->getMessage();
        }

        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        return $result;
    }

    /**
     * Return known backups ordered from newest to oldest.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBackups(): array
    {
        if (!is_dir($this->backupPath)) {
            return [];
        }

        $entries = glob($this->backupPath . '/*', GLOB_ONLYDIR);
        if ($entries === false) {
            return [];
        }

        $backups = [];
        foreach ($entries as $entry) {
            $manifestPath = $entry . '/manifest.json';
            $manifest = [];
            if (is_file($manifestPath)) {
                $content = file_get_contents($manifestPath);
                if ($content !== false && trim($content) !== '') {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        $manifest = $decoded;
                    }
                }
            }

            $created = isset($manifest['created_at']) ? (int) $manifest['created_at'] : filemtime($entry);
            $backups[] = [
                'name' => basename($entry),
                'path' => $entry,
                'created_at' => $created ?: null,
                'manifest' => $manifest,
            ];
        }

        usort($backups, static function (array $a, array $b): int {
            $aTime = isset($a['created_at']) ? (int) $a['created_at'] : 0;
            $bTime = isset($b['created_at']) ? (int) $b['created_at'] : 0;
            return $bTime <=> $aTime;
        });

        return $backups;
    }

    /**
     * Prune backups, keeping only the newest N snapshots.
     *
     * @return array{removed: int, kept: int}
     */
    public function prune(int $keep): array
    {
        if ($keep < 0) {
            throw new RuntimeException('Backups to keep must be zero or greater.');
        }

        $backups = $this->listBackups();
        $removed = 0;

        if ($keep === 0) {
            foreach ($backups as $backup) {
                $this->removeDirectory($backup['path']);
                $removed++;
            }

            return ['removed' => $removed, 'kept' => 0];
        }

        $kept = 0;
        foreach ($backups as $index => $backup) {
            if ($index < $keep) {
                $kept++;
                continue;
            }

            $this->removeDirectory($backup['path']);
            $removed++;
        }

        return ['removed' => $removed, 'kept' => $kept];
    }

    /**
     * @param array<string, string> $paths
     */
    private function requirePath(array $paths, string $key): string
    {
        if (!isset($paths[$key])) {
            throw new RuntimeException('Missing path configuration for ' . $key);
        }

        $value = $this->normalizePath($paths[$key]);
        if ($value === '') {
            throw new RuntimeException('Invalid path configuration for ' . $key);
        }

        return $value;
    }

    private function ensureDirectory(string $path): void
    {
        if ($path === '') {
            throw new RuntimeException('Cannot create an empty directory path.');
        }

        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create directory: ' . $path);
        }
    }

    private function copyFile(string $source, string $destination): void
    {
        if (!is_file($source)) {
            throw new RuntimeException('Source file not found: ' . $source);
        }

        $this->ensureDirectory(dirname($destination));
        if (!copy($source, $destination)) {
            throw new RuntimeException('Failed to copy file: ' . $source);
        }
    }

    private function copyOptionalFile(string $source, string $destination): void
    {
        if (!is_file($source)) {
            return;
        }

        $this->copyFile($source, $destination);
    }

    /**
     * @param array<int, string> $exclude
     */
    private function copyDirectory(string $source, string $destination, array $exclude = []): void
    {
        if (!is_dir($source)) {
            return;
        }

        $source = $this->normalizePath($source);
        $destination = $this->normalizePath($destination);
        $this->ensureDirectory($destination);

        $exclusions = [];
        foreach ($exclude as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $normalized = $this->normalizePath($path);
            if ($normalized !== '') {
                $exclusions[] = $normalized;
            }
        }

        $directoryIterator = new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (SplFileInfo $file, $key, $iterator) use ($exclusions): bool {
                $path = $this->normalizePath($file->getPathname());
                foreach ($exclusions as $excluded) {
                    if ($path === $excluded || str_starts_with($path, $excluded . '/')) {
                        return false;
                    }
                }
                return true;
            }
        );

        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
        $sourceLength = strlen($source);

        foreach ($iterator as $item) {
            $path = $this->normalizePath($item->getPathname());
            $relative = $sourceLength > 0 ? substr($path, $sourceLength + 1) : false;
            if ($relative === false || $relative === '') {
                if ($item->isDir()) {
                    continue;
                }
                $relative = basename($path);
            }

            $target = $destination . '/' . $relative;
            if ($item->isDir()) {
                $this->ensureDirectory($target);
                continue;
            }

            $this->ensureDirectory(dirname($target));
            if (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Failed to copy file: ' . $item->getPathname());
            }
        }
    }

    private function replaceDirectory(string $source, string $target, bool $overwrite): void
    {
        if (!is_dir($source)) {
            return;
        }

        if (file_exists($target)) {
            if (!$overwrite) {
                throw new RuntimeException('Target already exists: ' . $target);
            }
            $this->removeDirectory($target);
        }

        $this->copyDirectory($source, $target);
    }

    /**
     * @return array{files: int, directories: int, bytes: int}
     */
    private function computeDirectoryStats(string $path): array
    {
        $files = 0;
        $directories = 0;
        $bytes = 0;

        if (!is_dir($path)) {
            return ['files' => 0, 'directories' => 0, 'bytes' => 0];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $directories++;
                continue;
            }

            $files++;
            $bytes += $item->getSize();
        }

        // Include the root directory in the directory count.
        return [
            'files' => $files,
            'directories' => $directories + 1,
            'bytes' => $bytes,
        ];
    }

    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $this->ensureDirectory(dirname($path));
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException('Failed to write file: ' . $path);
        }
    }

    private function removeDirectory(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $source): array
    {
        $path = $this->normalizePath($source) . '/manifest.json';
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function recordRestoreMetadata(string $name, array $result): void
    {
        $payload = [
            'name' => $name,
            'mode' => isset($result['mode']) ? (string) $result['mode'] : 'extract',
            'restored_at' => isset($result['restored_at']) ? (int) $result['restored_at'] : time(),
            'target' => isset($result['target'])
                ? (string) $result['target']
                : ($result['mode'] === 'in_place' ? $this->basePath : null),
            'copied' => [],
            'warnings' => [],
        ];

        if (isset($result['copied']) && is_array($result['copied'])) {
            foreach ($result['copied'] as $item) {
                if (is_string($item) && $item !== '') {
                    $payload['copied'][] = $item;
                }
            }
        }

        if (isset($result['warnings']) && is_array($result['warnings'])) {
            foreach ($result['warnings'] as $warning) {
                if (is_string($warning) && $warning !== '') {
                    $payload['warnings'][] = $warning;
                }
            }
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        Storage::writeAtomic($this->restoreRecordPath, $json);
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return rtrim($path, '/');
    }

    private function slugify(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        if ($value === null) {
            return null;
        }

        $value = trim($value, '-');

        return $value === '' ? null : $value;
    }
}
