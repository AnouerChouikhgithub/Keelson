<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Renders the one API error envelope: `{error: {code, message, fields?}}`.
 *
 * {@see \App\EventListener\ApiExceptionListener} uses it for every exception,
 * and the authentication handlers use it for the failures Symfony asks them to
 * answer directly. One implementation means a client parses one shape.
 */
final class ApiErrorResponse
{
    /** @param array<string, list<string>> $fields */
    public static function make(int $status, string $code, string $message, array $fields = [], array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ([] !== $fields) {
            $error['fields'] = $fields;
        }

        return new JsonResponse(['error' => $error], $status, $headers + ['Cache-Control' => 'no-store']);
    }

    public static function fromException(ApiException $exception): JsonResponse
    {
        return self::make(
            $exception->getStatusCode(),
            $exception->getErrorCode(),
            $exception->getMessage(),
            $exception->getFields(),
            $exception->getHeaders(),
        );
    }
}
