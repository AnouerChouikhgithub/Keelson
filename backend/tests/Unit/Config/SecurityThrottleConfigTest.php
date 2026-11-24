<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Who owns the sign-in limit.
 *
 * `security.yaml` declared the framework's `login_throttling` while
 * `App\EventListener\AuthThrottleListener` enforced the real policy. The framework's
 * listener counts a different key, answers after the password comparison and returns
 * a login failure rather than this API's error body — a second, invisible policy.
 * It is gone; this test keeps it gone and keeps the three buckets the listener
 * consumes declared, so the integration test that observes the 429 has exactly one
 * mechanism that can produce it.
 */
final class SecurityThrottleConfigTest extends TestCase
{
    public function testTheFirewallDeclaresNoFrameworkLoginThrottle(): void
    {
        $config = $this->load('security.yaml');
        $firewalls = $config['security']['firewalls'] ?? [];
        self::assertIsArray($firewalls);

        foreach ($firewalls as $name => $firewall) {
            self::assertIsArray($firewall, sprintf('Firewall "%s" must be a mapping.', $name));
            self::assertArrayNotHasKey(
                'login_throttling',
                $firewall,
                sprintf('Firewall "%s" must not declare login_throttling: AuthThrottleListener is the only throttle.', $name),
            );
        }
    }

    public function testTheApplicationLimitersAreDeclared(): void
    {
        $limiters = $this->load('rate_limiter.yaml')['framework']['rate_limiter'] ?? [];
        self::assertIsArray($limiters);

        // Consumed by AuthAttemptLimiter::consumeLogin() and consumeResend().
        foreach (['login_email', 'login_ip', 'login_account', 'resend_email', 'resend_ip'] as $id) {
            self::assertArrayHasKey($id, $limiters, sprintf('The limiter "%s" the throttle consumes must be declared.', $id));
            self::assertSame(5, $limiters['login_email']['limit'] ?? null, 'The documented sign-in policy is five attempts.');
            self::assertSame('1 minute', $limiters['login_email']['interval'] ?? null);
        }
    }

    /** @return array<string, mixed> */
    private function load(string $file): array
    {
        $path = __DIR__.'/../../../config/packages/'.$file;
        $parsed = Yaml::parseFile($path);
        self::assertIsArray($parsed);

        return $parsed;
    }
}
