<?php

declare(strict_types=1);

namespace RSS;

use JsonException;

/**
 * Manage email alert notifications for feed status transitions.
 */
class AlertManager
{
    /** @var array{enabled:bool,email_to:string,email_from:string,subject_prefix:string,cooldown_seconds:int,notify_on_recovery:bool,notify_on_pause:bool} */
    private array $settings;

    /** @var array{feeds:array<string, array<string, mixed>>, history:array<int, array<string, mixed>>} */
    private array $state = ['feeds' => [], 'history' => []];

    private string $statePath;

    private bool $dirty = false;

    public function __construct(?array $config, array $paths)
    {
        $config = is_array($config) ? $config : [];

        $emailTo = isset($config['email_to']) ? trim((string) $config['email_to']) : '';
        $emailFrom = isset($config['email_from']) ? trim((string) $config['email_from']) : $emailTo;
        $enabledFlag = !empty($config['enabled']);
        $enabled = $enabledFlag && $this->isValidEmail($emailTo) && $this->isValidEmail($emailFrom);

        $cooldownMinutes = (int) ($config['cooldown_minutes'] ?? 60);
        $cooldownSeconds = max(60, $cooldownMinutes * 60);

        $this->settings = [
            'enabled' => $enabled,
            'email_to' => $enabled ? $emailTo : '',
            'email_from' => $enabled ? $emailFrom : '',
            'subject_prefix' => (string) ($config['subject_prefix'] ?? '[RSS Alerts] '),
            'cooldown_seconds' => $cooldownSeconds,
            'notify_on_recovery' => !empty($config['notify_on_recovery']),
            'notify_on_pause' => !empty($config['notify_on_pause']),
        ];

        $this->statePath = self::resolveStatePath($paths);
        $this->state = self::readStateFile($this->statePath);
    }

    public function isEnabled(): bool
    {
        return $this->settings['enabled'];
    }

    /**
     * Handle a feed status transition and send alerts when required.
     *
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $current
     */
    public function handleFeedStatusChange(?array $previous, array $current): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $feedId = isset($current['id']) ? (string) $current['id'] : (string) ($previous['id'] ?? '');
        if ($feedId === '') {
            return;
        }

        $newStatusRaw = isset($current['status']) ? (string) $current['status'] : null;
        $prevStatusRaw = isset($previous['status']) ? (string) $previous['status'] : null;

        if ($newStatusRaw !== null && $prevStatusRaw !== null && $newStatusRaw === $prevStatusRaw) {
            return;
        }

        $newClassification = self::classifyStatus($newStatusRaw);
        $previousClassification = self::classifyStatus($prevStatusRaw);

        $type = null;
        if ($newClassification === 'error') {
            $type = 'alert';
        } elseif ($newClassification === 'paused' && $this->settings['notify_on_pause']) {
            $type = 'alert';
        } elseif (
            $this->settings['notify_on_recovery']
            && $newClassification === 'ok'
            && in_array($previousClassification, ['error', 'paused'], true)
        ) {
            $type = 'recovery';
        }

        if ($type === null) {
            return;
        }

        $now = time();
        $state = $this->state['feeds'][$feedId] ?? [];
        $lastType = isset($state['last_type']) ? (string) $state['last_type'] : null;
        $lastStatus = isset($state['last_status']) ? (string) $state['last_status'] : null;
        $lastSent = isset($state['last_sent_ts']) ? (int) $state['last_sent_ts'] : 0;

        if (
            $lastType === $type
            && $lastStatus === $newStatusRaw
            && $lastSent > 0
            && ($now - $lastSent) < $this->settings['cooldown_seconds']
        ) {
            return;
        }

        $title = (string) ($current['title'] ?? ($previous['title'] ?? $feedId));
        $url = (string) ($current['url'] ?? ($previous['url'] ?? ''));

        $subject = $this->buildSubject($type, $title, $newStatusRaw);
        $body = $this->buildBody($feedId, $title, $url, $prevStatusRaw, $newStatusRaw, $type, $now);

        $headers = [
            'From: ' . $this->settings['email_from'],
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: RSS AlertManager',
        ];

        $sent = @mail(
            $this->settings['email_to'],
            $subject,
            $body,
            implode("\r\n", $headers)
        );

        if (!$sent) {
            error_log('RSS AlertManager: failed to send alert email for feed ' . $feedId);
        }

        $this->state['feeds'][$feedId] = [
            'last_status' => $newStatusRaw,
            'last_type' => $type,
            'last_subject' => $subject,
            'last_sent_ts' => $now,
        ];

        $historyEntry = [
            'feed_id' => $feedId,
            'status' => $newStatusRaw,
            'type' => $type,
            'subject' => $subject,
            'sent_ts' => $now,
        ];
        $this->state['history'][] = $historyEntry;
        if (count($this->state['history']) > 50) {
            $this->state['history'] = array_slice($this->state['history'], -50);
        }

        $this->dirty = true;
        $this->persist();
    }

    /**
     * Provide insight into the stored alert state for dashboards.
     *
     * @return array{feeds:array<string, array<string, mixed>>, history:array<int, array<string, mixed>>}
     */
    public static function loadState(array $paths): array
    {
        return self::readStateFile(self::resolveStatePath($paths));
    }

    public function __destruct()
    {
        $this->persist();
    }

    private function persist(): void
    {
        if (!$this->dirty) {
            return;
        }

        try {
            Storage::writeAtomic(
                $this->statePath,
                json_encode($this->state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
            );
            $this->dirty = false;
        } catch (JsonException $e) {
            error_log('RSS AlertManager: failed to encode alert state - ' . $e->getMessage());
        } catch (\Throwable $e) {
            error_log('RSS AlertManager: failed to persist alert state - ' . $e->getMessage());
        }
    }

    private function buildSubject(string $type, string $title, ?string $status): string
    {
        $label = $type === 'recovery' ? 'Recovered' : 'Alert';
        if ($status !== null && $status !== '') {
            $label .= ' ' . $this->shortenStatus($status);
        }
        $subject = $this->settings['subject_prefix'] . $label . ' — ' . $this->sanitizeSubjectText($title);
        return $subject;
    }

    private function buildBody(
        string $feedId,
        string $title,
        string $url,
        ?string $previousStatus,
        ?string $newStatus,
        string $type,
        int $timestamp
    ): string {
        $lines = [
            'Feed: ' . $title,
            'Feed ID: ' . $feedId,
        ];
        if ($url !== '') {
            $lines[] = 'URL: ' . $url;
        }
        $lines[] = 'New status: ' . ($newStatus ?? 'unknown');
        $lines[] = 'Previous status: ' . ($previousStatus ?? 'unknown');
        $lines[] = 'Event: ' . ($type === 'recovery' ? 'Recovery' : 'Alert');
        $lines[] = 'Generated at: ' . gmdate('c', $timestamp);

        if ($type === 'alert') {
            $minutes = (int) round($this->settings['cooldown_seconds'] / 60);
            $lines[] = 'Alerts for this status repeat after ~' . max(1, $minutes) . ' minutes if unresolved.';
        }

        return implode("\n", $lines) . "\n";
    }

    private function sanitizeSubjectText(string $text): string
    {
        $text = preg_replace('/[\r\n]+/', ' ', $text) ?? $text;
        return trim($text);
    }

    private function shortenStatus(string $status): string
    {
        $status = trim($status);
        if (strlen($status) <= 60) {
            return $status;
        }
        return substr($status, 0, 57) . '...';
    }

    private function isValidEmail(string $email): bool
    {
        if ($email === '') {
            return false;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function resolveStatePath(array $paths): string
    {
        $storage = '';
        if (isset($paths['storage']) && is_string($paths['storage']) && $paths['storage'] !== '') {
            $storage = $paths['storage'];
        } else {
            $config = Storage::getConfig();
            if (isset($config['paths']['storage']) && is_string($config['paths']['storage'])) {
                $storage = $config['paths']['storage'];
            }
        }

        if ($storage === '') {
            $storage = sys_get_temp_dir();
        }

        return rtrim($storage, '/\\') . '/tmp/alert_state.json';
    }

    /**
     * @return array{feeds:array<string, array<string, mixed>>, history:array<int, array<string, mixed>>}
     */
    private static function readStateFile(string $path): array
    {
        if (!is_file($path)) {
            return ['feeds' => [], 'history' => []];
        }

        try {
            $raw = (string) file_get_contents($path);
            if ($raw === '') {
                return ['feeds' => [], 'history' => []];
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $feeds = isset($decoded['feeds']) && is_array($decoded['feeds']) ? $decoded['feeds'] : [];
            $history = isset($decoded['history']) && is_array($decoded['history']) ? array_values($decoded['history']) : [];
            return ['feeds' => $feeds, 'history' => $history];
        } catch (JsonException $e) {
            error_log('RSS AlertManager: failed to decode alert state - ' . $e->getMessage());
            return ['feeds' => [], 'history' => []];
        }
    }

    public static function classifyStatus(?string $status): string
    {
        if ($status === null) {
            return 'unknown';
        }
        $normalized = strtolower(trim($status));
        if ($normalized === '') {
            return 'unknown';
        }
        if (str_starts_with($normalized, 'error') || str_contains($normalized, 'fail')) {
            return 'error';
        }
        if ($normalized === 'paused') {
            return 'paused';
        }
        if (in_array($normalized, ['queued', 'pending'], true)) {
            return 'pending';
        }
        return 'ok';
    }
}
