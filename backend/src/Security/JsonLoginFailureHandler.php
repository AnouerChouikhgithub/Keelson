<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\SecurityEvent;
use App\Http\ApiErrorResponse;
use App\Service\SecurityEventRecorder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Shapes the three distinct login failures.
 *
 * Unknown address, wrong password and a malformed body are one answer
 * (`invalid_credentials`): telling them apart would turn the endpoint into an
 * account oracle. `email_not_verified` is only reachable once the password has
 * been accepted (see {@see VerifiedUserChecker}), and throttling is reported with
 * the retry delay the limiter computed.
 */
final class JsonLoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(private readonly SecurityEventRecorder $events)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof EmailNotVerifiedException) {
            return ApiErrorResponse::make(
                Response::HTTP_FORBIDDEN,
                'email_not_verified',
                'Confirm your email address before signing in.',
            );
        }

        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            // Symfony reports the wait as whole minutes; a client needs seconds.
            $minutes = (int) ($exception->getMessageData()['%minutes%'] ?? 1);

            return ApiErrorResponse::make(
                Response::HTTP_TOO_MANY_REQUESTS,
                'too_many_login_attempts',
                'Too many sign-in attempts. Try again later.',
                [],
                ['Retry-After' => (string) max(60, $minutes * 60)],
            );
        }

        // Only the address fingerprint is kept: enough to correlate a spray,
        // never enough to confirm an account or to leak a credential.
        $body = json_decode((string) $request->getContent(), true);
        $email = \is_array($body) && \is_string($body['email'] ?? null) ? $body['email'] : '';
        $this->events->record(SecurityEvent::LOGIN_FAILURE, null, [
            'emailFingerprint' => SecurityEventRecorder::emailFingerprint($email),
        ]);

        return ApiErrorResponse::make(
            Response::HTTP_UNAUTHORIZED,
            'invalid_credentials',
            'The email address or password is incorrect.',
        );
    }
}
