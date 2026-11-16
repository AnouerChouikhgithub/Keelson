<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Validates that the values the application cannot run without are present.
 *
 * It reports variable *names* and problems only: no value is ever included in a
 * message, so the result is safe to print in a build log or startup error.
 *
 * Requirements are environment-aware on purpose. A missing mailer must not stop
 * a console command or a local test run, but it must stop a production
 * deployment from warming its cache with a configuration that cannot deliver a
 * verification or password-reset link.
 */
final class EnvironmentValidator
{
    private const PLACEHOLDERS = [
        'replace-with',
        'changeme',
        'example.test',
        'ci-only',
        'your-',
        'keelson-dev',
        'dev-secret',
        'test-secret',
        'not-a-secret',
    ];

    /**
     * Exact values that are published in this repository or in a framework
     * recipe. A production secret must never be one of them, even when it is long
     * enough and contains no placeholder substring.
     */
    private const KNOWN_DEV_SECRETS = [
        'secret',
        'app-secret',
        'symfony',
        'keelson',
        'change-me',
        'password',
        'this-is-not-a-secret',
        '00000000000000000000000000000000',
    ];

    /**
     * @param array<string, mixed> $configuration
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(array $configuration, string $environment, bool $debug = false): array
    {
        $production = 'prod' === $environment;
        $errors = [];
        $warnings = [];

        // Test secrets are synthetic and intentionally short; only production
        // is held to the length that makes the secret hard to guess.
        $secret = $this->value($configuration, 'APP_SECRET');
        if ('' === $secret) {
            $errors[] = 'APP_SECRET is not set.';
        } elseif ($this->isPlaceholder($secret)) {
            $errors[] = 'APP_SECRET still contains a placeholder value.';
        } elseif ($production && \in_array(strtolower($secret), self::KNOWN_DEV_SECRETS, true)) {
            $errors[] = 'APP_SECRET is a known development value.';
        } elseif ($production && strlen($secret) < 32) {
            $errors[] = 'APP_SECRET must be at least 32 characters long in production.';
        }

        $databaseUrl = $this->value($configuration, 'DATABASE_URL');
        if ('' === $databaseUrl) {
            $errors[] = 'DATABASE_URL is not set.';
        } elseif (1 !== preg_match('#^postgres(ql)?://#i', $databaseUrl)) {
            $errors[] = 'DATABASE_URL must use the postgresql:// scheme.';
        } elseif ($production && 1 === preg_match('#://[^/@]*:app@#', $databaseUrl)) {
            $errors[] = 'DATABASE_URL still uses the development app:app credentials.';
        }

        $defaultUri = $this->value($configuration, 'DEFAULT_URI');
        if ('' === $defaultUri) {
            $errors[] = 'DEFAULT_URI is not set; CLI URL generation and absolute redirects need it.';
        } elseif (1 !== preg_match('#^https?://#i', $defaultUri)) {
            $errors[] = 'DEFAULT_URI must be an absolute http(s) URL.';
        } elseif ($production && !str_starts_with(strtolower($defaultUri), 'https://')) {
            $errors[] = 'DEFAULT_URI must use https in production.';
        }

        // Verification and reset links point at the SPA; a malformed origin
        // would send users to a URL that does not exist.
        $frontendUrl = $this->value($configuration, 'FRONTEND_URL');
        if ('' === $frontendUrl) {
            $this->report($errors, $warnings, $production, 'FRONTEND_URL is not set; verification and reset links cannot be built.');
        } elseif (1 !== preg_match('#^https?://#i', $frontendUrl)) {
            $errors[] = 'FRONTEND_URL must be an absolute http(s) URL.';
        } elseif ($production && !str_starts_with(strtolower($frontendUrl), 'https://')) {
            $errors[] = 'FRONTEND_URL must use https in production.';
        }

        // Without a transport, registration cannot deliver the verification
        // link, so a production instance would create accounts nobody can use.
        $mailerDsn = $this->value($configuration, 'MAILER_DSN');
        if ('' === $mailerDsn) {
            $this->report($errors, $warnings, $production, 'MAILER_DSN is not set; verification and reset emails cannot be sent.');
        } elseif ($production && str_starts_with(strtolower($mailerDsn), 'null://')) {
            $errors[] = 'MAILER_DSN must not use the null transport in production.';
        }

        $trustedHosts = $this->value($configuration, 'TRUSTED_HOSTS');
        if ('' === $trustedHosts && $production) {
            $errors[] = 'TRUSTED_HOSTS is not set; production must not answer arbitrary Host headers.';
        }

        $trustedProxies = $this->value($configuration, 'TRUSTED_PROXIES');
        $trustedHeaders = $this->value($configuration, 'TRUSTED_HEADERS');
        if ('' !== $trustedProxies && '' === $trustedHeaders) {
            $errors[] = 'TRUSTED_HEADERS must be set when TRUSTED_PROXIES is set, otherwise forwarded HTTPS is ignored.';
        }

        if ($production && $debug) {
            $errors[] = 'APP_DEBUG must be disabled in production.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Missing mail or frontend configuration blocks sign-in but not console
     * work, so it is fatal only where the application serves users.
     *
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function report(array &$errors, array &$warnings, bool $production, string $finding): void
    {
        if ($production) {
            $errors[] = $finding;
        } else {
            $warnings[] = $finding;
        }
    }

    /** @param array<string, mixed> $configuration */
    private function value(array $configuration, string $name): string
    {
        $value = $configuration[$name] ?? '';

        return \is_string($value) ? trim($value) : '';
    }

    private function isPlaceholder(string $value): bool
    {
        $normalized = strtolower($value);
        foreach (self::PLACEHOLDERS as $placeholder) {
            if (str_contains($normalized, $placeholder)) {
                return true;
            }
        }

        return false;
    }
}
