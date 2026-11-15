<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Guarantees a JSON error document for uncaught `/api/...` failures.
 *
 * The API controllers return their own error document for expected business
 * outcomes; this listener only covers what escapes them (routing errors,
 * database outages, unexpected exceptions). Without it Symfony would answer an
 * XHR with an HTML error page, which the SPA can only report as an unknown
 * failure.
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
        $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        if ($status < 400 || $status > 599) {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        [$code, $message] = $this->describe($status);

        $this->logger->log(
            $status >= 500 ? LogLevel::CRITICAL : LogLevel::WARNING,
            'API request failed',
            [
                'status' => $status,
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'exception' => $throwable,
            ],
        );

        // No exception message, class, trace or DSN is ever copied to the client.
        $event->setResponse(new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
            ['Cache-Control' => 'no-store'],
        ));
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
