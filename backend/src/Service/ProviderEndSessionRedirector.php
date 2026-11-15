<?php

declare(strict_types=1);

namespace App\Service;

use Drenso\OidcBundle\OidcClientInterface;
use Drenso\OidcBundle\Security\Token\OidcToken;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Builds the provider's end-session redirect for an OIDC session.
 *
 * The local session is invalidated by Symfony's SessionLogoutListener before
 * this runs. Signing out must therefore never depend on the provider being
 * reachable: a discovery failure, a missing `end_session_endpoint` or a token
 * without an ID token degrades to the configured local logout target instead
 * of failing the request and leaving the browser on an error page.
 */
final class ProviderEndSessionRedirector
{
    public function __construct(
        private readonly OidcClientInterface $oidcClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function redirectFor(OidcToken $token, ?string $postLogoutRedirectUrl): ?RedirectResponse
    {
        try {
            return $this->oidcClient->generateEndSessionEndpointRedirect($token->getAuthData(), $postLogoutRedirectUrl);
        } catch (\Throwable $exception) {
            // The exception object can contain the discovery URL but never a
            // credential; it is useful for diagnosing provider configuration.
            $this->logger->warning('OIDC provider end-session redirect unavailable; completed the local sign-out only.', [
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
