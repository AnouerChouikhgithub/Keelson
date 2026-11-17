<?php

declare(strict_types=1);

namespace App\Ai\Exception;

use Symfony\Component\HttpFoundation\Response;

/** The principal or organization AI bucket is empty. */
final class AiRateLimitedException extends \App\Exception\ApiException
{
    private function __construct(int $retryAfter)
    {
        parent::__construct(
            Response::HTTP_TOO_MANY_REQUESTS,
            'ai_rate_limited',
            'Too many AI requests; try again shortly.',
            [],
            null,
            ['Retry-After' => (string) max(1, $retryAfter)],
        );
    }

    public static function create(int $retryAfter): self
    {
        return new self($retryAfter);
    }
}
