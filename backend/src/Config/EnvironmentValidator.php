<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Validates that the values the application cannot run without are present.
 *
 * It reports variable *names* and problems only: no value is ever included in a
 * message, so the result is safe to print in a build log or startup error.
 *
 * Requirements are environment-aware on purpose. A missing OIDC client must not
 * stop a console command or a local test run, but it must stop a production
 * deployment from warming its cache with a configuration that cannot sign
 * anyone in.
 */
final class EnvironmentValidator
{
    private const PLACEHOLDERS = [
        'replace-with',
        'your-logto-tenant',
        'changeme',
        'example.test',
        'ci-only',
        'your-',
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

        // Collected first because the severity depends on the environment:
        // OIDC is optional for console work locally, mandatory in production.
        $oidcFindings = [];
        $oidc = [];
        foreach (['OIDC_ISSUER', 'OIDC_WELL_KNOWN_URL', 'OIDC_CLIENT_ID', 'OIDC_CLIENT_SECRET'] as $name) {
            $oidc[$name] = $this->value($configuration, $name);
            if ('' === $oidc[$name]) {
                $oidcFindings[] = sprintf('%s is not set; browser sign-in will be unavailable.', $name);
            } elseif ($this->isPlaceholder($oidc[$name])) {
                $oidcFindings[] = sprintf('%s still contains a placeholder value.', $name);
            }
        }

        if ('' !== $oidc['OIDC_ISSUER'] && 1 !== preg_match('#^https?://#i', $oidc['OIDC_ISSUER'])) {
            $oidcFindings[] = 'OIDC_ISSUER must be an absolute http(s) URL.';
        } elseif ($production && '' !== $oidc['OIDC_ISSUER'] && !str_starts_with(strtolower($oidc['OIDC_ISSUER']), 'https://')) {
            $errors[] = 'OIDC_ISSUER must use https in production.';
        }

        foreach ($oidcFindings as $finding) {
            if ($production) {
                $errors[] = $finding;
            } else {
                $warnings[] = $finding;
            }
        }

        if ('' !== $oidc['OIDC_ISSUER'] && '' !== $oidc['OIDC_WELL_KNOWN_URL'] && !str_starts_with($oidc['OIDC_WELL_KNOWN_URL'], $oidc['OIDC_ISSUER'])) {
            $warnings[] = 'OIDC_WELL_KNOWN_URL does not start with OIDC_ISSUER; confirm the provider metadata URL.';
        }

        $trustedHosts = $this->value($configuration, 'TRUSTED_HOSTS');
        if ('' === $trustedHosts) {
            if ($production) {
                $errors[] = 'TRUSTED_HOSTS is not set; production must not answer arbitrary Host headers.';
            }
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
