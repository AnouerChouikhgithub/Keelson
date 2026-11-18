<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Principal;
use App\Security\SessionVersion;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Signs a session out once the password it was opened with has changed.
 *
 * The check runs on every authenticated request because every one of them can be
 * the first after a reset: a person resetting a forgotten password is usually not
 * the person holding the open session this ends.
 *
 * The session body is invalidated, not just the token, so the id cannot be
 * replayed against another request; the client is answered as an anonymous
 * caller, which is what `GET /api/session` then reports.
 */
final class SessionVersionListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall (8): there has to be a token before it can be checked.
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        // Reading the token can start the session, so this precedes isStarted().
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof Principal) {
            return;
        }

        $session = $request->getSession();
        if (!$session->isStarted()) {
            return;
        }

        if (!SessionVersion::isStale($session, $user)) {
            SessionVersion::stamp($session, $user);

            return;
        }

        $this->tokenStorage->setToken(null);
        $session->invalidate();
    }
}
