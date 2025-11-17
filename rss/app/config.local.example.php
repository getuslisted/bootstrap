<?php

declare(strict_types=1);

return [
    // Example overrides for a production deployment. Copy to config.local.php and adjust values as needed.
    'timezone' => 'America/New_York',
    'admin_allowed_ips' => [
        '203.0.113.25',
        '203.0.113.0/24',
    ],
    'http' => [
        'proxy' => 'http://proxy.internal:3128',
        'default_headers' => [
            'X-Forwarded-For' => '203.0.113.25',
        ],
        'retries' => 4,
        'retry_delay_ms' => 750,
    ],
    'alerts' => [
        'enabled' => true,
        'email_from' => 'alerts@example.com',
        'email_to' => 'ops@example.com',
    ],
    'paths' => [
        'backups' => '/home/example/backups/rss',
    ],
    'publisher' => [
        'language' => 'en-GB',
    ],
    'maintenance' => [
        'backups_keep' => 10,
    ],
    'diagnostics' => [
        'disk' => [
            'warn_free_bytes' => 750 * 1024 * 1024,
            'error_free_bytes' => 300 * 1024 * 1024,
            'path' => '/home/example/rss/storage',
        ],
    ],
    'integrity' => [
        'sample_size' => 50,
        'count_tolerance' => 2,
        'max_lines' => 100000,
    ],
    'scheduling' => [
        'backoff' => [
            'multiplier' => 1.5,
            'max_interval_sec' => 3600,
        ],
    ],
    'metrics' => [
        'tags' => [
            'lookback_days' => 14,
            'min_count' => 3,
            'include_private' => true,
        ],
    ],
    'status' => [
        'latest' => [
            'max_items' => 100,
            'max_items_per_feed' => 20,
            'lookback_days' => 7,
            'include_summary_html' => true,
        ],
    ],
];
