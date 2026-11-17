<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuthToken;
use App\Entity\Principal;
use App\Http\InputHydrator;
use App\Http\JsonBody;
use App\Input\ForgotPasswordInput;
use App\Input\RegisterInput;
use App\Input\ResendVerificationInput;
use App\Input\ResetPasswordInput;
use App\Input\VerifyEmailInput;
use App\Repository\PrincipalRepository;
use App\Service\AuthTokenService;
use App\Service\MailFactory;
use App\Service\RegistrationService;
use App\Service\SessionPayloadFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Email + password authentication, JSON only.
 *
 * Two properties shape the responses here:
 *
 *  - Registration and password reset are enumeration-safe. Registering an
 *    existing address returns the same `201 verification_sent` and sends
 *    nothing; requesting a reset for an unknown address returns the same `202`.
 *    A client cannot use these endpoints to discover accounts.
 *  - Redeeming a token proves control of the address, so both the verification
 *    and the reset flow can mark the address verified. Verification also writes
 *    the password the mailbox owner chooses: the hash stored at registration
 *    belongs to whoever typed the address, which is not necessarily its owner.
 *
 * Both token flows redeem through {@see AuthTokenService::redeem()}, which makes
 * the token single-use under concurrency and rolls the "used" mark back if the
 * account mutation fails.
 */
final class AuthController
{
    public function __construct(
        private readonly InputHydrator $hydrator,
        private readonly RegistrationService $registration,
        private readonly AuthTokenService $tokens,
        private readonly PrincipalRepository $principals,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MailerInterface $mailer,
        private readonly MailFactory $mails,
        private readonly SessionPayloadFactory $payloads,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route('/api/auth/register', name: 'app_api_auth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $input = $this->hydrator->hydrate(RegisterInput::class, JsonBody::decode($request));

        // Returns silently when the address already exists; a subdomain
        // collision that survives allocation is an explicit 409.
        $this->registration->register($input);

        return new JsonResponse(['status' => 'verification_sent'], Response::HTTP_CREATED, ['Cache-Control' => 'no-store']);
    }

    /**
     * Target of the `json_login` authenticator.
     *
     * A request carrying credentials is answered by the firewall before this
     * runs; the method exists so the path is routable and so an already
     * authenticated caller gets the session payload instead of a 405.
     */
    #[Route('/api/auth/login', name: 'app_api_auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return new JsonResponse(
            $this->payloads->forUser($this->tokenStorage->getToken()?->getUser()),
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store, private'],
        );
    }

    #[Route('/api/auth/verify-email', name: 'app_api_auth_verify_email', methods: ['POST'])]
    public function verifyEmail(Request $request): Response
    {
        $input = $this->hydrator->hydrate(VerifyEmailInput::class, JsonBody::decode($request));

        $this->tokens->redeem($input->token, AuthToken::TYPE_VERIFY, function (Principal $principal) use ($input): void {
            // The hash stored at registration was chosen by whoever submitted the
            // address. The person reading this link owns the mailbox, so their
            // password replaces it before the account ever becomes usable, and
            // both writes commit together with the token consumption.
            $principal->setPasswordHash($this->passwordHasher->hashPassword($principal, $input->password));
            $principal->markEmailVerified();
        });

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Ends the current session.
     *
     * Implemented as a controller rather than the firewall's `logout` so the
     * CSRF listener, which runs after the firewall, also guards it: a logout
     * CSRF still forces a sign-out, which is a denial of service a user did not
     * ask for.
     */
    #[Route('/api/auth/logout', name: 'app_api_auth_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $this->tokenStorage->setToken(null);
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/auth/forgot-password', name: 'app_api_auth_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): Response
    {
        $input = $this->hydrator->hydrate(ForgotPasswordInput::class, JsonBody::decode($request));

        $principal = $this->principals->findOneByEmail(Principal::normalizeEmail($input->email));
        if (null !== $principal) {
            $token = $this->tokens->issue($principal, AuthToken::TYPE_RESET);
            $this->mailer->send($this->mails->resetPassword($principal, $token));
        }

        // Always the same answer: "we sent it if the address exists".
        return new Response(null, Response::HTTP_ACCEPTED);
    }

    /**
     * Sends a fresh verification link to an address that has not confirmed yet.
     *
     * Answers the same `202` for an unknown address and for one that is already
     * verified, so the endpoint cannot be used to discover, or to probe the state
     * of, an account. Issuing the new token retires the previous unused one.
     * Throttled by {@see \App\EventListener\AuthThrottleListener}, like sign-in.
     */
    #[Route('/api/auth/resend-verification', name: 'app_api_auth_resend_verification', methods: ['POST'])]
    public function resendVerification(Request $request): Response
    {
        $input = $this->hydrator->hydrate(ResendVerificationInput::class, JsonBody::decode($request));

        $principal = $this->principals->findOneByEmail(Principal::normalizeEmail($input->email));
        if (null !== $principal && !$principal->isEmailVerified()) {
            $token = $this->tokens->issue($principal, AuthToken::TYPE_VERIFY);
            $this->mailer->send($this->mails->verifyEmail($principal, $token));
        }

        return new Response(null, Response::HTTP_ACCEPTED);
    }

    #[Route('/api/auth/reset-password', name: 'app_api_auth_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): Response
    {
        $input = $this->hydrator->hydrate(ResetPasswordInput::class, JsonBody::decode($request));

        $this->tokens->redeem($input->token, AuthToken::TYPE_RESET, function (Principal $principal) use ($input): void {
            $principal->setPasswordHash($this->passwordHasher->hashPassword($principal, $input->password));
            // Receiving the link is proof the address is reachable.
            $principal->markEmailVerified();
        });

        // The stored session token carries the previous hash; Symfony's
        // ContextListener compares it on the next request and signs other
        // sessions out without any extra code here.
        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
