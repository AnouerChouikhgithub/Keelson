<?php

declare(strict_types=1);

/**
 * Turns the `ai-eval` JUnit report into a pass-rate table.
 *
 * The table is the deliverable of the real-provider job, and it is built from test-case
 * names only: a case is called `... with data set "g-en-07"`, so the row says which
 * question id passed, never what the question said and never what the model answered.
 * That is deliberate — the job runs with a real credential, and a transcript of prompts
 * and answers has no business in a CI log.
 *
 * Usage: php bin/ai-eval-table.php var/ai-eval/junit.xml [--minimum=90]
 * Exit code is 0 when every set is at or above the minimum, 1 otherwise.
 */
$path = $argv[1] ?? 'var/ai-eval/junit.xml';
$minimum = 90.0;
foreach (array_slice($argv, 2) as $argument) {
    if (1 === preg_match('/^--minimum=(\d+(?:\.\d+)?)$/', $argument, $matches)) {
        $minimum = (float) $matches[1];
    }
}

if (!is_file($path)) {
    fwrite(STDERR, sprintf("The JUnit report \"%s\" does not exist.\n", $path));
    exit(2);
}

$document = new DOMDocument();
if (false === $document->load($path)) {
    fwrite(STDERR, sprintf("The JUnit report \"%s\" is not readable XML.\n", $path));
    exit(2);
}

/** Sets in the order they should appear, with the label the table prints. */
$sets = [
    'g-en' => 'guide en',
    'g-fr' => 'guide fr',
    'g-ar' => 'guide ar',
    'o-en' => 'off-topic en',
    'o-ar' => 'off-topic ar',
    'o-fr' => 'off-topic fr',
];

$tally = [];
$failures = [];
$unmatched = 0;

foreach ($document->getElementsByTagName('testcase') as $case) {
    $name = $case->getAttribute('name');
    // `... with data set "g-en-07"`: the id is the only part we keep.
    if (1 !== preg_match('/data set "([go]-[a-z]{2}-\d{2})"/', $name, $matches)) {
        ++$unmatched;

        continue;
    }

    $id = $matches[1];
    $set = substr($id, 0, 4);

    $failed = false;
    foreach ($case->childNodes as $child) {
        if ($child instanceof DOMElement && in_array($child->tagName, ['failure', 'error'], true)) {
            $failed = true;
        }
    }

    $tally[$set] ??= ['passed' => 0, 'failed' => 0];
    ++$tally[$set][$failed ? 'failed' : 'passed'];
    if ($failed) {
        $failures[] = $id;
    }
}

if ([] === $tally) {
    fwrite(STDERR, "No ai-eval test cases were found in the report.\n");
    exit(2);
}

$belowMinimum = [];
echo "| set | cases | passed | failed | pass rate |\n";
echo "| --- | --- | --- | --- | --- |\n";

$overall = ['passed' => 0, 'failed' => 0];
foreach ($sets as $prefix => $label) {
    if (!isset($tally[$prefix])) {
        continue;
    }
    $passed = $tally[$prefix]['passed'];
    $failed = $tally[$prefix]['failed'];
    $total = $passed + $failed;
    $rate = $total > 0 ? 100 * $passed / $total : 0.0;
    $overall['passed'] += $passed;
    $overall['failed'] += $failed;

    printf("| %s | %d | %d | %d | %.1f%% |\n", $label, $total, $passed, $failed, $rate);
    if ($rate < $minimum) {
        $belowMinimum[] = $label;
    }
}

$total = $overall['passed'] + $overall['failed'];
printf(
    "| **overall** | %d | %d | %d | %.1f%% |\n",
    $total,
    $overall['passed'],
    $overall['failed'],
    $total > 0 ? 100 * $overall['passed'] / $total : 0.0,
);

if ([] !== $failures) {
    echo "\nFailing question ids: ".implode(', ', $failures)."\n";
}
if ($unmatched > 0) {
    echo sprintf("%d ai-eval case(s) did not carry a question id and were not counted.\n", $unmatched);
}

if ([] !== $belowMinimum) {
    fwrite(STDERR, sprintf(
        "\n%s below the %.1f%% minimum.\n",
        implode(', ', $belowMinimum),
        $minimum,
    ));

    exit(1);
}

exit(0);
