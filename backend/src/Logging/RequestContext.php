<?php

declare(strict_types=1);

namespace App\Logging;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Read-only view of the correlation id assigned by RequestIdListener.
 *
 * A logger is also used outside an HTTP request (console commands, cache
 * warmup), so the id is nullable rather than invented there.
 */
final class RequestContext
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function requestId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        $requestId = $request->attributes->get('_request_id');

        return \is_string($requestId) && '' !== $requestId ? $requestId : null;
    }
}
