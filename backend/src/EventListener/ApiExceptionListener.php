<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ApiException;
use App\Http\ApiErrorResponse;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The single place that turns a failure under `/api/...` into a response.
 *
 * Controllers throw {@see ApiException} (carrying status, code and optional
 * field messages) instead of building their own error documents; anything else
 * that escapes (routing errors, database outages, unexpected exceptions) is
 * mapped from its HTTP status to a generic code. Either way the client gets
 * `{error: {code, message, fields?}}` and never an HTML page, an exception
 * message or a stack trace.
 *
 * It logs exactly once per failure. Symfony's own error listener would log the
 * same exception, so propagation is stopped after the response is set; that is
 * deliberate, not swallowed: the response is a real 4xx/5xx and the record is
 * written at warning (client error) or critical (server error).
 */
final class ApiExceptionListener implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Runs before Symfony's ErrorListener (-128) but after the security
        // firewall's exception listener (1), which resolves access denials.
        return [KernelEvents::EXCEPTION => ['onKernelException', 0]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();

        if ($throwable instanceof ApiException) {
            // Already client-safe: the controller wrote the message itself.
            $response = ApiErrorResponse::fromException($throwable);
        } else {
            $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            if ($status < 400 || $status > 599) {
                $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            }
            [$code, $message] = $this->describe($status);
            $response = ApiErrorResponse::make($status, $code, $message);
        }

        $this->logger->log(
            $response->getStatusCode() >= 500 ? LogLevel::CRITICAL : LogLevel::WARNING,
            'API request failed',
            [
                'status' => $response->getStatusCode(),
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'exception' => $throwable,
            ],
        );

        $event->setResponse($response);
        $event->stopPropagation();
    }

    /** @return array{0: string, 1: string} */
    private function describe(int $status): array
    {
        return match ($status) {
            400 => ['invalid_request', 'The request could not be understood.'],
            401 => ['unauthenticated', 'Authentication is required.'],
            403 => ['forbidden', 'You are not allowed to perform this action.'],
            404 => ['not_found', 'The requested resource does not exist.'],
            405 => ['method_not_allowed', 'That method is not supported for this resource.'],
            406 => ['not_acceptable', 'The requested response format is not available.'],
            409 => ['conflict', 'The request conflicted with the current state.'],
            415 => ['unsupported_media_type', 'The request body format is not supported.'],
            422 => ['unprocessable_entity', 'The request could not be processed.'],
            429 => ['too_many_requests', 'Too many requests. Try again later.'],
            503 => ['service_unavailable', 'The service is temporarily unavailable.'],
            default => ['internal_error', 'An unexpected error occurred.'],
        };
    }
}
