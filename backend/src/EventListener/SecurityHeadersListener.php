<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Adds the browser-facing hardening headers to every `/api` response.
 *
 * The API only serves JSON, so its policy can be the strictest one: no scripts,
 * no frames, no plugins. The SPA is a separate origin and gets its own policy
 * from the reverse proxy (docs/deployment.md). HSTS is emitted only where it can
 * be honoured — HTTPS behind a proxy Symfony already trusts as secure — because
 * sending it over plain HTTP or in development would pin localhost in the
 * developer's browser for a year.
 */
final class SecurityHeadersListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly string $environment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Late: the controller (or the exception listener) has already set the
        // status, and these headers must be present on errors too.
        return [KernelEvents::RESPONSE => ['onKernelResponse', -1024]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $response = $event->getResponse();
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        if ('prod' === $this->environment && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // A personalised answer must never be cached by a browser or a shared
        // proxy. Errors can still carry a field name, so they are covered too.
        if (null !== $this->tokenStorage->getToken()?->getUser()) {
            $headers->set('Cache-Control', 'no-store, private');
        }
    }
}
