<?php

declare(strict_types=1);

namespace App\Ai\Http;

/** The raw result of one HTTP attempt. */
final class AiHttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }
}
