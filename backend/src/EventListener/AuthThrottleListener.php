<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Http\ApiErrorResponse;
use App\Security\AuthAttemptLimiter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses an authentication attempt that is over the limit, before any work is
 * done on it.
 *
 * A request that reaches the authenticator has its password hashed and
 * compared, and a request that reaches the resend endpoint issues a token and
 * sends a mail. Both are exactly the work an attacker wants to make us repeat,
 * so the check sits in front of the firewall (priority 8). It is also ahead of it
 * for a second reason: the answer must be the same whether or not the account
 * exists.
 */
final class AuthThrottleListener implements EventSubscriberInterface
{
    private const LOGIN_PATH = '/api/auth/login';
    private const RESEND_PATH = '/api/auth/resend-verification';

    public function __construct(private readonly AuthAttemptLimiter $limiter)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // After the same-origin guard (32), before the firewall (8).
        return [KernelEvents::REQUEST => ['onKernelRequest', 24]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = rtrim($request->getPathInfo(), '/');
        if ('POST' !== $request->getMethod() || (self::LOGIN_PATH !== $path && self::RESEND_PATH !== $path)) {
            return;
        }

        [$retryAfter, $code] = self::LOGIN_PATH === $path
            ? [$this->limiter->consumeLogin($request, $this->email($request)), 'too_many_login_attempts']
            : [$this->limiter->consumeResend($request, $this->email($request)), 'too_many_resend_attempts'];

        if (null === $retryAfter) {
            return;
        }

        $event->setResponse(ApiErrorResponse::make(
            Response::HTTP_TOO_MANY_REQUESTS,
            $code,
            'Too many attempts. Try again later.',
            [],
            ['Retry-After' => (string) $retryAfter],
        ));
    }

    /**
     * Reads the identifier from the JSON body without failing the request when
     * the body is not JSON: a malformed body is the controller's problem and
     * must still count against the client bucket only.
     */
    private function email(Request $request): ?string
    {
        $content = (string) $request->getContent();
        if ('' === trim($content)) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $email = \is_array($data) ? ($data['email'] ?? null) : null;

        return \is_string($email) ? $email : null;
    }
}
