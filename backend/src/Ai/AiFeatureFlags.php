<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The deployment-level AI switch.
 *
 * Kept as a service rather than read at each call site so the value is resolved once
 * and every feature asks the same object. The setter is a test seam: an integration
 * test has to exercise both states in one PHP process, and recompiling the container
 * per state would test the environment loader instead of the gateway.
 */
final class AiFeatureFlags
{
    public function __construct(private bool $enabled)
    {
    }

    public function isGloballyEnabled(): bool
    {
        return $this->enabled;
    }

    // @phpstan-ignore shipmonk.deadMethod (driven by tests/Integration/AiGatewayTest)
    public function overrideGlobalEnabledForTests(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
}
