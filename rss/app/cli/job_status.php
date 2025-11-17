<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RSS\JobStatus;

$options = getopt('', ['json', 'public', 'job:', 'help']);
if ($options === false) {
    fwrite(STDERR, "Failed to parse options." . PHP_EOL);
    exit(1);
}

if (isset($options['help'])) {
    echo <<<TXT
Usage: php app/cli/job_status.php [--job=name] [--public] [--json]

Options:
  --job      Filter output to a specific job key (e.g. importer, publish).
  --public   Only show the public summary payload (hides internal-only fields).
  --json     Emit JSON output instead of the human-readable report.
  --help     Display this help text.
TXT;
    exit(0);
}

$jobFilter = null;
if (isset($options['job'])) {
    $jobOption = $options['job'];
    if (is_array($jobOption)) {
        $jobOption = end($jobOption);
    }

    $jobFilter = trim((string) $jobOption);
    if ($jobFilter === '') {
        fwrite(STDERR, "--job requires a non-empty value." . PHP_EOL);
        exit(1);
    }
}

$records = isset($options['public']) ? JobStatus::getPublicSummary() : JobStatus::getAll();

if ($jobFilter !== null) {
    if (!isset($records[$jobFilter])) {
        if (isset($options['json'])) {
            echo json_encode([], JSON_THROW_ON_ERROR) . PHP_EOL;
        } else {
            fwrite(STDERR, 'Job not found: ' . $jobFilter . PHP_EOL);
        }
        exit(0);
    }

    $records = [$jobFilter => $records[$jobFilter]];
}

if (isset($options['json'])) {
    echo json_encode($records, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

if ($records === []) {
    echo 'No job status records found.' . PHP_EOL;
    exit(0);
}

foreach ($records as $name => $info) {
    echo 'Job: ' . $name . PHP_EOL;
    echo '  Status: ' . ($info['status'] ?? 'unknown') . PHP_EOL;
    echo '  Last started: ' . formatTimestamp($info['last_started_at'] ?? null) . PHP_EOL;
    echo '  Last finished: ' . formatTimestamp($info['last_finished_at'] ?? null) . PHP_EOL;
    if (isset($info['duration_sec'])) {
        echo '  Duration: ' . number_format((float) $info['duration_sec'], 2) . 's' . PHP_EOL;
    }

    if (!empty($info['message'])) {
        echo '  Message: ' . (string) $info['message'] . PHP_EOL;
    }

    if (!empty($info['context'])) {
        $context = $info['context'];
        if (is_array($context)) {
            foreach ($context as $key => $value) {
                if (is_string($key)) {
                    echo '    - ' . $key . ': ' . formatScalar($value) . PHP_EOL;
                }
            }
        } else {
            echo '  Context: ' . formatScalar($context) . PHP_EOL;
        }
    }

    echo PHP_EOL;
}

/**
 * @param scalar|null $value
 */
function formatScalar($value): string
{
    if ($value === null) {
        return 'null';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_string($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return json_encode($value, JSON_THROW_ON_ERROR);
}

function formatTimestamp(mixed $value): string
{
    if ($value === null || $value === '' || $value === 0) {
        return '—';
    }

    $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
    if ($timestamp === false) {
        return (string) $value;
    }

    return gmdate('c', $timestamp);
}
