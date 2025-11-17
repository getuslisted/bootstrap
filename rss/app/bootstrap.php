<?php

declare(strict_types=1);

use RSS\Config;
use RSS\Storage;

$autoloader = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoloader)) {
    throw new RuntimeException('Composer autoloader not found. Run `composer install` from the /rss directory.');
}

require $autoloader;

$config = Config::load(dirname(__DIR__));

// Ensure required directories exist at runtime
$requiredDirs = [
    $config['paths']['storage'],
    $config['paths']['storage'] . '/feeds',
    $config['paths']['storage'] . '/index',
    $config['paths']['storage'] . '/tmp',
    $config['paths']['backups'] ?? ($config['paths']['storage'] . '/backups'),
    $config['paths']['logs'],
    $config['paths']['public'],
    $config['paths']['public'] . '/feeds',
    $config['paths']['public'] . '/status',
];

foreach ($requiredDirs as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException(sprintf('Failed to create required directory: %s', $dir));
    }
}

Storage::initialize($config);
