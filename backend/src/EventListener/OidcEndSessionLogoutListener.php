<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\ProviderEndSessionRedirector;
use Drenso\OidcBundle\Security\Token\OidcToken;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Ends the provider session in addition to the local one.
 *
 * Runs after Symfony's DefaultLogoutListener (priority 64, sets the configured
 * logout target) and SessionLogoutListener (priority 0, invalidates the
 * session). That ordering is what makes the local sign-out authoritative: the
 * fallback response is already in place, and a provider failure simply leaves
 * it untouched.
 */
final class OidcEndSessionLogoutListener implements EventSubscriberInterface
{
    public function __construct(private readonly ProviderEndSessionRedirector $redirector)
    {
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if (!$token instanceof OidcToken) {
            return;
        }

        // The default listener already resolved the configured target to an
        // absolute URL; the provider must return the browser to exactly that.
        $response = $event->getResponse();
        $postLogoutRedirectUrl = $response instanceof RedirectResponse ? $response->getTargetUrl() : null;

        $redirect = $this->redirector->redirectFor($token, $postLogoutRedirectUrl);
        if (null !== $redirect) {
            $event->setResponse($redirect);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => ['onLogout', -10]];
    }
}
