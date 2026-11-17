<?php

declare(strict_types=1);

/**
 * Fails when line coverage in a critical namespace drops below a threshold.
 *
 *   php bin/coverage-check.php var/coverage/clover.xml [--threshold=80]
 *
 * PHPUnit reports one number for the whole source tree, which lets a well-tested
 * calculation service hide an untested controller. This reads the Clover report
 * and checks `src/Service`, `src/Security` and `src/Controller` separately.
 *
 * Only executable statements are counted (`type="stmt"`): a file's "lines" total
 * includes comments and blank lines in some reporters, which inflates the ratio.
 */
const NAMESPACES = ['src/Service/', 'src/Security/', 'src/Controller/'];

$arguments = array_slice($argv, 1);
$cloverPath = null;
$threshold = 80.0;

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--threshold=')) {
        $threshold = (float) substr($argument, strlen('--threshold='));
        continue;
    }
    $cloverPath = $argument;
}

if (null === $cloverPath || !is_file($cloverPath)) {
    fwrite(STDERR, "usage: php bin/coverage-check.php <clover.xml> [--threshold=80]\n");
    exit(2);
}

$document = new DOMDocument();
if (false === @$document->load($cloverPath)) {
    fwrite(STDERR, sprintf("Could not parse %s as XML.\n", $cloverPath));
    exit(2);
}

/** @var array<string, array{covered: int, total: int, files: int}> $totals */
$totals = [];
foreach (NAMESPACES as $namespace) {
    $totals[$namespace] = ['covered' => 0, 'total' => 0, 'files' => 0];
}

$files = $document->getElementsByTagName('file');
foreach ($files as $file) {
    $name = str_replace('\\', '/', $file->getAttribute('name'));
    $namespace = null;
    foreach (NAMESPACES as $candidate) {
        if (str_contains($name, '/'.$candidate) || str_starts_with($name, $candidate)) {
            $namespace = $candidate;
            break;
        }
    }
    if (null === $namespace) {
        continue;
    }

    ++$totals[$namespace]['files'];
    foreach ($file->getElementsByTagName('line') as $line) {
        if ('stmt' !== $line->getAttribute('type')) {
            continue;
        }
        ++$totals[$namespace]['total'];
        if ((int) $line->getAttribute('count') > 0) {
            ++$totals[$namespace]['covered'];
        }
    }
}

$failed = false;
printf("%-16s %9s %9s %8s  %s\n", 'namespace', 'covered', 'total', 'lines', 'result');
foreach ($totals as $namespace => $counts) {
    $percentage = 0 === $counts['total'] ? 0.0 : 100 * $counts['covered'] / $counts['total'];
    // A namespace with no measurable statements cannot prove anything, so it
    // fails rather than passing vacuously.
    $ok = $counts['total'] > 0 && $percentage >= $threshold;
    $failed = $failed || !$ok;
    printf(
        "%-16s %9d %9d %7.1f%%  %s\n",
        $namespace,
        $counts['covered'],
        $counts['total'],
        $percentage,
        $ok ? 'ok' : sprintf('below %.1f%%', $threshold),
    );
}

exit($failed ? 1 : 0);
