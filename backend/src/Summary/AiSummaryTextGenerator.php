<?php

declare(strict_types=1);

namespace App\Summary;

use App\Ai\AiGateway;
use App\Ai\AiRequest;
use App\Entity\Principal;

/**
 * The production generator: one gateway call per summary.
 *
 * It holds no policy of its own. The gateway checks the caller's membership, the
 * deployment switch, the tenant's opt-in, both rate limits and the budget, and it
 * sanitizes the answer it returns; this class only names the feature so the
 * ledger records `summary_<kind>`.
 */
final class AiSummaryTextGenerator implements SummaryTextGenerator
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly SummaryPromptFactory $prompts,
    ) {
    }

    public function generate(Principal $principal, WorkspaceFacts $facts): string
    {
        $request = new AiRequest(
            $facts->kind->feature(),
            $this->prompts->system($facts->locale),
            $this->prompts->prompt($facts),
            $this->prompts->maxTokens(),
            $facts->organizationId,
            $principal->getId(),
        );

        return $this->gateway->complete($principal, $facts->organizationId, $request)->text;
    }
}
