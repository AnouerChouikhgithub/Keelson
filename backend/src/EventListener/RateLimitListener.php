<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Per-endpoint abuse budgets for the endpoints that are expensive or revealing.
 *
 * Each bucket is keyed by what an attacker would have to vary: the client
 * address for anonymous routes, the authenticated principal for change-password
 * (a shared office address must not lock out colleagues). The login and resend
 * budgets live in {@see AuthThrottleListener}, which runs before the firewall
 * and must not know whether the account exists; these run after it.
 *
 * The refusal is the same envelope as every other error, with a Retry-After the
 * client can honour.
 */
final class RateLimitListener implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.register_ip')]
        private readonly RateLimiterFactoryInterface $register,
        #[Autowire(service: 'limiter.forgot_password_email')]
        private readonly RateLimiterFactoryInterface $forgotByEmail,
        #[Autowire(service: 'limiter.forgot_password_ip')]
        private readonly RateLimiterFactoryInterface $forgotByClient,
        #[Autowire(service: 'limiter.accept_invitation_ip')]
        private readonly RateLimiterFactoryInterface $acceptInvitation,
        #[Autowire(service: 'limiter.change_password_principal')]
        private readonly RateLimiterFactoryInterface $changePassword,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall (8), so a change-password request has a principal.
        return [KernelEvents::REQUEST => ['onKernelRequest', 4]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ('POST' !== $request->getMethod()) {
            return;
        }

        $path = rtrim($request->getPathInfo(), '/');
        $client = (string) ($request->getClientIp() ?? 'unknown');

        match ($path) {
            '/api/auth/register' => $this->consume($this->register->create($client)->consume(1)),
            '/api/auth/accept-invitation' => $this->consume($this->acceptInvitation->create($client)->consume(1)),
            '/api/me/password' => $this->consume($this->changePassword->create($this->principalKey())->consume(1)),
            '/api/auth/forgot-password' => $this->consumeForgot($request, $client),
            default => null,
        };
    }

    private function consumeForgot(Request $request, string $client): void
    {
        $email = $this->email($request);
        if (null !== $email) {
            $this->consume($this->forgotByEmail->create(strtolower($email))->consume(1));
        }
        $this->consume($this->forgotByClient->create($client)->consume(1));
    }

    private function consume(\Symfony\Component\RateLimiter\RateLimit $limit): void
    {
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();

        throw ApiException::rateLimited($retryAfter);
    }

    private function principalKey(): string
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof \App\Entity\Principal ? $user->getId() : 'anonymous';
    }

    private function email(Request $request): ?string
    {
        $content = (string) $request->getContent();
        if ('' === trim($content)) {
            return null;
        }

        try {
            $data = json_decode($content, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $email = \is_array($data) ? ($data['email'] ?? null) : null;

        return \is_string($email) && '' !== $email ? $email : null;
    }
}
