<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Chooses the provider from `AI_PROVIDER`.
 *
 * `fake` is the default so a checkout works without a credential; any other value
 * routes to the HTTP provider, whose missing configuration then answers
 * `ai_unavailable` instead of silently calling the network with empty settings.
 */
final class AiProviderSelector
{
    public function __construct(
        private readonly HttpAiProvider $http,
        private readonly FakeAiProvider $fake,
        private readonly string $provider,
    ) {
    }

    public function provider(): AiProvider
    {
        return 'fake' === strtolower(trim($this->provider)) ? $this->fake : $this->http;
    }
}
