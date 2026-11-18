<?php

declare(strict_types=1);

namespace App\Summary;

use App\Entity\Principal;

/**
 * Turns facts into prose. The one implementation calls {@see \App\Ai\AiGateway};
 * the interface exists so a test can script an answer (including a lying one)
 * without a network, a provider or a fake gateway.
 */
interface SummaryTextGenerator
{
    /**
     * @throws \App\Exception\ApiException when the gateway refuses the call
     *                                     (disabled, budget, rate limit) or the
     *                                     provider is unavailable
     */
    public function generate(Principal $principal, WorkspaceFacts $facts): string;
}
