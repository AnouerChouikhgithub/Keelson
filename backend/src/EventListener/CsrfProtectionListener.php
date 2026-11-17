<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Principal;
use App\Exception\ApiException;
use App\Service\SessionPayloadFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * One guard for every state-changing API request.
 *
 * It replaces the per-controller `invoice_api` token check: a new endpoint could
 * forget to validate a token, a global listener cannot. Two rules apply:
 *
 *  - every unsafe method (POST/PUT/PATCH/DELETE) must come from the same origin
 *    when it sends an `Origin` header, which stops a cross-site form post;
 *  - an *authenticated* unsafe request must also carry `X-CSRF-Token` matching
 *    the `api` token of the current session. Cookie-authenticated requests are
 *    the ones a browser can be tricked into sending.
 *
 * The unauthenticated auth endpoints are exempt because their caller has no
 * session to protect yet; they are still covered by the origin rule. Logout is
 * deliberately *not* exempt.
 */
final class CsrfProtectionListener implements EventSubscriberInterface
{
    private const EXEMPT_PATHS = [
        '/api/auth/login',
        '/api/auth/register',
        '/api/auth/verify-email',
        '/api/auth/forgot-password',
        '/api/auth/reset-password',
    ];

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokens,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // The origin rule runs before the firewall (priority 8): a request from
        // another site is refused without its credentials being checked. The
        // token rule runs after it, because only then is the session populated.
        return [KernelEvents::REQUEST => [['enforceSameOrigin', 32], ['enforceCsrfToken', 5]]];
    }

    public function enforceSameOrigin(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->isMethodSafe()) {
            return;
        }

        $origin = $request->headers->get('Origin');
        if (null !== $origin && !hash_equals($request->getSchemeAndHttpHost(), $origin)) {
            throw ApiException::csrfInvalid();
        }
    }

    public function enforceCsrfToken(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->isMethodSafe()) {
            return;
        }

        $path = rtrim($request->getPathInfo(), '/') ?: '/';
        if (!str_starts_with($path, '/api') || in_array($path, self::EXEMPT_PATHS, true)) {
            return;
        }

        if (!$this->tokenStorage->getToken()?->getUser() instanceof Principal) {
            // Unauthenticated: the entry point answers 401, so there is no
            // session this request could abuse.
            return;
        }

        $value = (string) $request->headers->get('X-CSRF-Token', '');
        if (!$this->csrfTokens->isTokenValid(new CsrfToken(SessionPayloadFactory::CSRF_TOKEN_ID, $value))) {
            throw ApiException::csrfInvalid();
        }
    }
}
