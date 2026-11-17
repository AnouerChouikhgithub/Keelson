<?php

declare(strict_types=1);

namespace App\Ai;

/** What a provider returned: text plus the token counts the ledger records. */
final class AiResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly ?string $model,
    ) {
        if ($promptTokens < 0 || $completionTokens < 0) {
            throw new \InvalidArgumentException('Token counts cannot be negative.');
        }
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
