<?php

declare(strict_types=1);

namespace App\Ai\Exception;

use Symfony\Component\HttpFoundation\Response;

/** The global switch is off, or this organization has not opted in. */
final class AiDisabledException extends \App\Exception\ApiException
{
    private function __construct()
    {
        parent::__construct(
            Response::HTTP_FORBIDDEN,
            'ai_disabled',
            'AI features are not enabled for this organization.',
        );
    }

    public static function create(): self
    {
        return new self();
    }
}
