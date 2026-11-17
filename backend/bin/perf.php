<?php

declare(strict_types=1);

/**
 * Measures list and dashboard latency over HTTP against a running instance.
 *
 *   php bin/perf.php --base=http://127.0.0.1:8000 --email=owner@example.test
 *
 * It signs in the way a browser does (session cookie plus CSRF token), then
 * issues a fixed number of sequential requests per endpoint and reports p50 and
 * p95. Sequential requests are deliberate: concurrency would measure the queue
 * depth of PHP's built-in server rather than the query.
 *
 * Timing is the client-observed wall time of a completed request, which includes
 * PHP's own startup, so the numbers are pessimistic compared with a tuned FPM
 * worker. They are for comparing one run with the next on the same machine.
 */
$options = getopt('', ['base::', 'email::', 'password::', 'requests::', 'organization::']);
$base = rtrim((string) ($options['base'] ?? 'http://127.0.0.1:8000'), '/');
$email = (string) ($options['email'] ?? 'owner@example.test');
$password = (string) ($options['password'] ?? 'Keelson-Demo-2026!');
$requests = max(1, (int) ($options['requests'] ?? 20));

$cookieJar = tempnam(sys_get_temp_dir(), 'keelson-perf-cookies');
if (false === $cookieJar) {
    fwrite(STDERR, "Could not create a cookie jar.\n");
    exit(2);
}

/**
 * @param array{method?: string, body?: ?string, headers?: list<string>} $options
 *
 * @return array{status: int, body: string, seconds: float}
 */
function call(string $url, string $cookieJar, array $options = []): array
{
    $handle = curl_init($url);
    $method = $options['method'] ?? 'GET';
    $headers = $options['headers'] ?? [];
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if (isset($options['body'])) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $options['body']);
    }

    $started = microtime(true);
    $body = curl_exec($handle);
    $seconds = microtime(true) - $started;
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if (false === $body) {
        fwrite(STDERR, sprintf("Request failed: %s %s\n", $url, $error));
        exit(2);
    }

    return ['status' => $status, 'body' => (string) $body, 'seconds' => $seconds];
}

$login = call($base.'/api/auth/login', $cookieJar, [
    'method' => 'POST',
    'body' => json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
    'headers' => ['Content-Type: application/json', 'Origin: '.$base],
]);
if (200 !== $login['status']) {
    fwrite(STDERR, sprintf("Sign-in failed with HTTP %d: %s\n", $login['status'], $login['body']));
    exit(2);
}

$session = json_decode(call($base.'/api/session', $cookieJar)['body'], true, 512, JSON_THROW_ON_ERROR);
$organizationId = (string) ($options['organization'] ?? '');
if ('' === $organizationId) {
    $organizations = call($base.'/api/me/organizations', $cookieJar);
    $items = json_decode($organizations['body'], true, 512, JSON_THROW_ON_ERROR)['items'] ?? [];
    $organizationId = (string) ($items[0]['id'] ?? '');
}
if ('' === $organizationId) {
    fwrite(STDERR, "Could not determine an organization id; pass --organization=.\n");
    exit(2);
}

$endpoints = [
    'customers list' => sprintf('/api/organizations/%s/customers?limit=50', $organizationId),
    'invoices list (q)' => sprintf('/api/organizations/%s/invoices?limit=50&q=Bulk%%20Customer%%20001', $organizationId),
    'audit list' => sprintf('/api/organizations/%s/invoice-audit-events?limit=50', $organizationId),
    'dashboard' => sprintf('/api/organizations/%s/dashboard', $organizationId),
    'members list' => sprintf('/api/organizations/%s/members', $organizationId),
];

function percentile(array $samples, float $percentile): float
{
    sort($samples);
    $index = (int) ceil($percentile / 100 * count($samples)) - 1;

    return $samples[max(0, min($index, count($samples) - 1))];
}

printf("Keelson API latency — %s, %d requests per endpoint, user %s\n", $base, $requests, $email);
printf("%-20s %10s %10s %10s %10s\n", 'endpoint', 'p50 ms', 'p95 ms', 'min ms', 'max ms');

$worst = 0.0;
foreach ($endpoints as $label => $path) {
    $samples = [];
    for ($index = 0; $index < $requests; ++$index) {
        $response = call($base.$path, $cookieJar);
        if ($response['status'] >= 400) {
            fwrite(STDERR, sprintf("%s returned HTTP %d: %s\n", $path, $response['status'], substr($response['body'], 0, 300)));
            exit(2);
        }
        $samples[] = $response['seconds'];
    }
    $p95 = percentile($samples, 95) * 1000;
    $worst = max($worst, $p95);
    printf(
        "%-20s %10.1f %10.1f %10.1f %10.1f\n",
        $label,
        percentile($samples, 50) * 1000,
        $p95,
        min($samples) * 1000,
        max($samples) * 1000,
    );
}

@unlink($cookieJar);

printf("\nWorst p95: %.1f ms (%s the 100 ms target)\n", $worst, $worst < 100.0 ? 'within' : 'ABOVE');
