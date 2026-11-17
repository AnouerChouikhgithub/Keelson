<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses a request shape before a controller or a validator sees it.
 *
 * Three cheap checks keep expensive work off malformed input: a non-JSON media
 * type, a body over one mebibyte, and JSON nested deeper than sixteen levels.
 * The depth check matters because the decoder is recursive; the size cap matters
 * because the body is already in memory by the time Symfony hands it over, so the
 * check is a refusal to continue rather than a streaming limit (the proxy's
 * `client_max_body_size` is the real boundary, docs/deployment.md).
 *
 * It runs ahead of the CSRF and rate-limit listeners: there is no point spending
 * either budget on a request that will be rejected for its shape.
 */
final class ApiBodyGuardListener implements EventSubscriberInterface
{
    public const MAX_BODY_BYTES = 1_048_576;
    public const MAX_JSON_DEPTH = 16;

    private const JSON_MEDIA_TYPE = 'application/json';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Before the firewall: an oversized or non-JSON body must not reach the
        // authentication stack at all. A request that is merely unauthenticated
        // still gets 401, because it is rejected before any body work.
        return [KernelEvents::REQUEST => ['onKernelRequest', 48]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        if (\in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $declared = $request->headers->get('Content-Length');
        if (null !== $declared && ctype_digit($declared) && (int) $declared > self::MAX_BODY_BYTES) {
            $this->reject($event, ApiException::payloadTooLarge());
        }

        $content = (string) $request->getContent();
        if ('' === trim($content)) {
            // An empty body is a validation problem for the controller, not a
            // media-type one; the CSRF/origin guards still apply.
            return;
        }

        if (\strlen($content) > self::MAX_BODY_BYTES) {
            $this->reject($event, ApiException::payloadTooLarge());
        }

        // A declared media type must be JSON. An absent one is left to the
        // controller's decoder: BrowserKit and some HTTP clients omit it, and the
        // body is still parsed as JSON, so rejecting it would only break callers
        // that already send the right body.
        $declaredType = $request->headers->get('Content-Type');
        if (null !== $declaredType && '' !== trim($declaredType)) {
            $mediaType = strtolower(trim(explode(';', $declaredType)[0]));
            if (self::JSON_MEDIA_TYPE !== $mediaType) {
                $this->reject($event, ApiException::unsupportedMediaType());
            }
        }

        $this->assertDepth($event, $content);
    }

    private function assertDepth(RequestEvent $event, string $content): void
    {
        try {
            json_decode($content, true, self::MAX_JSON_DEPTH + 1, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            // Malformed JSON is the controller's `invalid_json`; only a body that
            // parses at the default depth but not at ours is a depth violation.
            try {
                json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return;
            }

            $this->logger->info('API request body exceeded the JSON depth limit', [
                'path' => $event->getRequest()->getPathInfo(),
                'exception' => $exception,
            ]);
            $this->reject($event, ApiException::jsonTooDeep());
        }
    }

    private function reject(RequestEvent $event, ApiException $exception): void
    {
        $this->logger->warning('API request rejected before handling', [
            'status' => $exception->getStatusCode(),
            'code' => $exception->getErrorCode(),
            'path' => $event->getRequest()->getPathInfo(),
        ]);

        // Rendering goes through ApiErrorResponse in the exception listener; the
        // request is stopped here by throwing the client-safe exception.
        throw $exception;
    }
}
