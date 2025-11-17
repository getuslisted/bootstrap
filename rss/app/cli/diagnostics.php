<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\Diagnostics;

$options = array_slice($argv, 1);
$jsonOutput = false;
foreach ($options as $option) {
    if ($option === '--json') {
        $jsonOutput = true;
    }
}

$checks = Diagnostics::runChecks($config);
$summary = Diagnostics::summarize($checks);

if ($jsonOutput) {
    try {
        $payload = [
            'summary' => $summary,
            'checks' => $checks,
        ];
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
    } catch (\JsonException $e) {
        fwrite(STDERR, 'Failed to encode diagnostics as JSON: ' . $e->getMessage() . PHP_EOL);
        echo Diagnostics::formatCli($checks);
        echo sprintf(
            'Summary: %d OK, %d WARN, %d ERROR',
            $summary['ok'] ?? 0,
            $summary['warn'] ?? 0,
            $summary['error'] ?? 0
        ) . PHP_EOL;
    }
} else {
    echo Diagnostics::formatCli($checks);
    echo sprintf(
        'Summary: %d OK, %d WARN, %d ERROR',
        $summary['ok'] ?? 0,
        $summary['warn'] ?? 0,
        $summary['error'] ?? 0
    ) . PHP_EOL;
}

exit(Diagnostics::hasErrors($checks) ? 1 : 0);
