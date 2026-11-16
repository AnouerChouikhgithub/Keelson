<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Principal;
use App\Service\SessionPayloadFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Answers a successful `POST /api/auth/login` with the session payload.
 *
 * It is the exact body `GET /api/session` returns, built by the same factory:
 * the SPA treats the login response as its session, so the two must not drift.
 */
final class JsonLoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly SessionPayloadFactory $payloads,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoginAttemptLimiter $attempts,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();
        if ($user instanceof Principal) {
            $user->recordLogin();
            $this->entityManager->flush();
            // Knowing the right password clears this address' failed attempts so
            // a legitimate user who mistyped is not locked out afterwards.
            $this->attempts->resetFor($user);
        }

        return new JsonResponse(
            $this->payloads->forUser($user),
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store, private'],
        );
    }
}
