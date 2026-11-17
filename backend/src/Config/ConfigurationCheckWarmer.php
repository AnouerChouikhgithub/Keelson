<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Fails a production cache warmup when required configuration is missing.
 *
 * `cache:warmup` (and therefore `cache:clear`) is a deliberate deployment step;
 * failing there turns a broken environment into a failed deploy instead of a
 * process that accepts traffic and returns 500 for every sign-in. Local and
 * test environments are skipped: they legitimately run without mail delivery.
 */
final class ConfigurationCheckWarmer implements CacheWarmerInterface
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        private readonly EnvironmentValidator $validator,
        private readonly string $environment,
        private readonly bool $debug,
        private readonly array $configuration,
    ) {
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ('prod' !== $this->environment) {
            return [];
        }

        $result = $this->validator->validate($this->configuration, $this->environment, $this->debug);
        foreach ($result['warnings'] as $warning) {
            error_log('[keelson config] '.$warning);
        }

        if ([] !== $result['errors']) {
            throw new \RuntimeException("Refusing to warm the production cache with incomplete configuration:\n - ".implode("\n - ", $result['errors'])."\nSee docs/deployment.md, section 2 (Environment).");
        }

        return [];
    }
}
