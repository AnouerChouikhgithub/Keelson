<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when a token cannot be consumed.
 *
 * Unknown, expired and already-used tokens all raise this identical exception
 * with the same message on purpose: anything more specific would let a caller
 * distinguish "this link existed" from "this link never existed". It extends
 * {@see ApiException} so the response shape is produced in one place, and it
 * deliberately carries no token material.
 */
final class AuthTokenException extends ApiException
{
    public static function unusable(): self
    {
        return new self(400, 'invalid_token', 'This link is no longer valid.');
    }
}
