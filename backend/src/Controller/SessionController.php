<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SessionPayloadFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Session probe for the SPA shell.
 *
 * The shell cannot invent authentication state: it must ask the server. This
 * endpoint is reachable while signed out, performs no database query and
 * exposes only the caller's own identity. When a session exists it also returns
 * the CSRF token an unsafe request must echo, which is why the same payload is
 * reused by a successful login.
 */
final class SessionController
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly SessionPayloadFactory $payloads,
    ) {
    }

    #[Route('/api/session', name: 'app_api_session', methods: ['GET'])]
    public function session(): JsonResponse
    {
        return new JsonResponse(
            $this->payloads->forUser($this->tokenStorage->getToken()?->getUser()),
            200,
            ['Cache-Control' => 'no-store, private'],
        );
    }
}
