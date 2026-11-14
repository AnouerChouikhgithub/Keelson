<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Principal;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Session probe for the SPA shell.
 *
 * The browser shell cannot invent authentication state: it must ask the
 * server. This endpoint is reachable while signed out (see access_control) so
 * the shell can render the signed-out state without being bounced through
 * OIDC, and it exposes the CSRF token the /logout route requires when a
 * session does exist.
 *
 * It deliberately performs no database query and returns no tenant data: the
 * only identity fields exposed are the caller's own principal. Dependencies
 * are injected rather than pulled from the container so the payload contract
 * can be tested without booting a kernel.
 */
final class SessionController
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly CsrfTokenManagerInterface $csrfTokens,
    ) {
    }

    #[Route('/api/session', name: 'app_api_session', methods: ['GET'])]
    public function session(): JsonResponse
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        $authenticated = $user instanceof Principal;

        return new JsonResponse([
            'authenticated' => $authenticated,
            'user' => $authenticated ? [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'displayName' => $user->getDisplayName(),
            ] : null,
            // Null while signed out: /logout requires ROLE_USER, so there is
            // nothing to protect and no session should be created just to
            // hand out a token.
            'logout' => $authenticated ? [
                'url' => '/logout',
                'csrfToken' => $this->csrfTokens->getToken('logout')->getValue(),
            ] : null,
        ], 200, ['Cache-Control' => 'no-store, private']);
    }
}
