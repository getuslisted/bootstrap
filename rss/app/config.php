<?php

declare(strict_types=1);

$baseDir = realpath(__DIR__ . '/..');

if ($baseDir === false) {
    throw new RuntimeException('Unable to determine project base directory');
}

return [
    'timezone' => 'UTC',
    // Supports single IPs, CIDR blocks (e.g., 203.0.113.0/24), or explicit ranges (198.51.100.10-198.51.100.40)
    'admin_allowed_ips' => [
        '127.0.0.1',
        '::1',
    ],
    'http' => [
        'user_agent' => 'RSS Aggregator/1.0 (+https://example.com)',
        'timeout' => 20,
        'connect_timeout' => 10,
        'verify' => true,
        'max_body_size' => 1048576 * 8,
        'allowed_schemes' => ['http', 'https'],
        'proxy' => null,
        'default_headers' => [],
        'retries' => 2,
        'retry_delay_ms' => 500,
        'retry_backoff_factor' => 2.0,
        'retry_max_delay_ms' => 5000,
    ],
    'scheduling' => [
        'backoff' => [
            'enabled' => true,
            'multiplier' => 2.0,
            'max_multiplier' => 8.0,
            'min_interval_sec' => 300,
            'max_interval_sec' => 21_600,
        ],
    ],
    'paths' => [
        'base' => $baseDir,
        'storage' => $baseDir . '/storage',
        'backups' => $baseDir . '/storage/backups',
        'logs' => $baseDir . '/logs',
        'public' => $baseDir . '/public',
        'database' => $baseDir . '/storage/index/database.sqlite',
    ],
    'publisher' => [
        'site_title' => 'Aggregated Feeds',
        'site_url' => 'https://example.com/rss/',
        'site_description' => 'Combined feed of curated sources.',
        'language' => 'en-US',
        'owner_name' => null,
        'owner_email' => null,
    ],
    'diagnostics' => [
        'disk' => [
            'warn_free_bytes' => 500 * 1024 * 1024,
            'error_free_bytes' => 200 * 1024 * 1024,
            'path' => null,
        ],
    ],
    'integrity' => [
        'sample_size' => 25,
        'count_tolerance' => 5,
        'max_lines' => 50000,
    ],
    'maintenance' => [
        'log_max_bytes' => 5_000_000,
        'keep_log_backups' => 5,
        'prune_dead_after_days' => 45,
        'prune_batch_size' => 500,
        'backups_keep' => 5,
    ],
    'alerts' => [
        'enabled' => false,
        'email_to' => null,
        'email_from' => null,
        'subject_prefix' => '[RSS Alerts] ',
        'cooldown_minutes' => 60,
        'notify_on_recovery' => true,
        'notify_on_pause' => false,
    ],
    'status' => [
        'latest' => [
            'enabled' => true,
            'max_items' => 200,
            'max_items_per_feed' => 40,
            'lookback_days' => 14,
            'include_private' => false,
            'include_dead' => false,
            'include_summary_html' => false,
        ],
    ],
    'metrics' => [
        'history' => [
            'enabled' => true,
            'retain_days' => 30,
            'max_entries' => 5000,
        ],
        'tags' => [
            'enabled' => true,
            'lookback_days' => 30,
            'max_tags' => 100,
            'min_count' => 2,
            'max_items_per_feed' => 400,
            'include_private' => false,
        ],
    ],
];
