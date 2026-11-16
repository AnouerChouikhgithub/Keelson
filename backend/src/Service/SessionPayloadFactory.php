<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Principal;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The one definition of the session payload.
 *
 * `GET /api/session` and a successful `POST /api/auth/login` must return the
 * same body: the SPA stores what it received and cannot tell which call
 * produced it. Keeping the shape here is what stops the two drifting apart.
 */
final class SessionPayloadFactory
{
    /** Token id the CSRF listener validates on unsafe API requests. */
    public const CSRF_TOKEN_ID = 'api';

    public function __construct(private readonly CsrfTokenManagerInterface $csrfTokens)
    {
    }

    /** @return array{authenticated: bool, user: array{id: string, email: string, displayName: ?string}|null, csrfToken: ?string} */
    public function forUser(?UserInterface $user): array
    {
        if (!$user instanceof Principal) {
            // Nothing to protect while signed out, and no session is created
            // just to hand out a token.
            return ['authenticated' => false, 'user' => null, 'csrfToken' => null];
        }

        return [
            'authenticated' => true,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'displayName' => $user->getDisplayName(),
            ],
            'csrfToken' => $this->csrfTokens->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ];
    }
}
