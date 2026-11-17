<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The one error type controllers throw.
 *
 * {@see \App\EventListener\ApiExceptionListener} is the only place that turns
 * an exception into a response body, so every API error has the same
 * `{error: {code, message, fields?}}` shape. The class carries the status, the
 * machine-readable code and optionally per-field messages; the message is
 * always safe to show, because controllers construct it from text they own
 * rather than from an internal exception.
 */
class ApiException extends \RuntimeException implements HttpExceptionInterface
{
    /**
     * @param array<string, list<string>> $fields
     * @param array<string, string>       $headers
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $errorCode,
        string $message,
        private readonly array $fields = [],
        ?\Throwable $previous = null,
        private readonly array $headers = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @param array<string, list<string>> $fields */
    public static function validationFailed(string $message, array $fields = []): self
    {
        return new self(Response::HTTP_UNPROCESSABLE_ENTITY, 'validation_failed', $message, $fields);
    }

    public static function invalidJson(): self
    {
        return new self(Response::HTTP_BAD_REQUEST, 'invalid_json', 'The request body must be a JSON object.');
    }

    public static function jsonTooDeep(): self
    {
        return new self(Response::HTTP_BAD_REQUEST, 'invalid_json', 'The request body must not be nested deeper than 16 levels.');
    }

    public static function unsupportedMediaType(): self
    {
        return new self(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'unsupported_media_type', 'Requests with a body must use Content-Type: application/json.');
    }

    public static function payloadTooLarge(): self
    {
        return new self(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'payload_too_large', 'The request body must not exceed 1 MiB.');
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(Response::HTTP_TOO_MANY_REQUESTS, 'rate_limited', 'Too many requests; try again shortly.', [], null, ['Retry-After' => (string) max(1, $retryAfter)]);
    }

    public static function invalidId(): self
    {
        return new self(Response::HTTP_BAD_REQUEST, 'invalid_id', 'The requested identifier is not valid.');
    }

    public static function invalidCursor(string $field = 'cursor'): self
    {
        return new self(Response::HTTP_BAD_REQUEST, 'invalid_cursor', sprintf('The %s value is not a valid cursor.', $field));
    }

    public static function invalidToken(): self
    {
        return new self(Response::HTTP_BAD_REQUEST, 'invalid_token', 'This link is no longer valid.');
    }

    public static function badRequest(string $message): self
    {
        return new self(Response::HTTP_BAD_REQUEST, 'invalid_request', $message);
    }

    public static function csrfInvalid(): self
    {
        return new self(Response::HTTP_FORBIDDEN, 'csrf_invalid', 'A valid CSRF token is required for this request.');
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self(Response::HTTP_NOT_FOUND, 'not_found', $message);
    }

    public static function conflict(string $message, string $code = 'conflict'): self
    {
        return new self(Response::HTTP_CONFLICT, $code, $message);
    }

    public static function lastOwner(): self
    {
        return new self(Response::HTTP_CONFLICT, 'last_owner', 'An organization must keep at least one Owner.');
    }

    public static function conflictAlreadyMember(): self
    {
        return new self(Response::HTTP_CONFLICT, 'already_member', 'That address already belongs to this organization.');
    }

    public static function unauthenticated(): self
    {
        return new self(Response::HTTP_UNAUTHORIZED, 'unauthenticated', 'Authentication is required.');
    }

    public static function signInRequired(): self
    {
        return new self(Response::HTTP_UNAUTHORIZED, 'sign_in_required', 'Sign in as the invited address to accept this invitation.');
    }

    public static function tooManyRequests(string $code = 'too_many_requests'): self
    {
        return new self(Response::HTTP_TOO_MANY_REQUESTS, $code, 'Too many requests; try again in a minute.');
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return ['Cache-Control' => 'no-store'] + $this->headers;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, list<string>> */
    public function getFields(): array
    {
        return $this->fields;
    }
}
