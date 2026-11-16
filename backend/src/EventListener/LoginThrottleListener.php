<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Http\ApiErrorResponse;
use App\Security\LoginAttemptLimiter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses a sign-in attempt that is over the limit, before any credentials are
 * looked at.
 *
 * A request that reaches the authenticator has its password hashed and
 * compared, which is exactly the work an attacker wants to make us repeat, so
 * the check has to sit in front of the firewall (priority 8). It is also ahead
 * of it for a second reason: the answer must be the same whether or not the
 * account exists.
 */
final class LoginThrottleListener implements EventSubscriberInterface
{
    private const LOGIN_PATH = '/api/auth/login';

    public function __construct(private readonly LoginAttemptLimiter $limiter)
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
        if ('POST' !== $request->getMethod() || self::LOGIN_PATH !== rtrim($request->getPathInfo(), '/')) {
            return;
        }

        $retryAfter = $this->limiter->consume($request, $this->email($request));
        if (null === $retryAfter) {
            return;
        }

        $event->setResponse(ApiErrorResponse::make(
            Response::HTTP_TOO_MANY_REQUESTS,
            'too_many_login_attempts',
            'Too many sign-in attempts. Try again later.',
            [],
            ['Retry-After' => (string) $retryAfter],
        ));
    }

    /**
     * Reads the identifier from the JSON body without failing the request when
     * the body is not JSON: a malformed body is the authenticator's problem and
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
