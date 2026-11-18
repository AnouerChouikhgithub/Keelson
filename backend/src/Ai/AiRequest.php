<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * One provider call, already assembled by a feature through {@see Safety\PromptBuilder}.
 *
 * The organization and principal are carried for accounting, not for the provider:
 * {@see AiGateway} writes them to the ledger, and no prompt text is logged.
 */
final class AiRequest
{
    public const MAX_TOKENS_LIMIT = 4096;

    public function __construct(
        public readonly string $feature,
        public readonly string $systemPrompt,
        public readonly string $prompt,
        public readonly int $maxTokens,
        public readonly string $organizationId,
        public readonly ?string $principalId,
    ) {
        if ('' === trim($feature) || strlen($feature) > 64) {
            throw new \InvalidArgumentException('An AI feature name must contain 1 to 64 characters.');
        }
        if ('' === trim($prompt)) {
            throw new \InvalidArgumentException('An AI request needs a prompt.');
        }
        if ($maxTokens < 1 || $maxTokens > self::MAX_TOKENS_LIMIT) {
            throw new \InvalidArgumentException(sprintf('maxTokens must be between 1 and %d.', self::MAX_TOKENS_LIMIT));
        }
    }
}
