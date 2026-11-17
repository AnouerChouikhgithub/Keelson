<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Principal;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Bounds how often one account, and one client address, may reach an
 * authentication endpoint that sends mail or checks a password.
 *
 * Two buckets are consumed per attempt: the first makes guessing a single
 * password (or replaying one address) expensive, the second stops one host from
 * spreading the same effort over many accounts. A rejection reports how long the
 * caller must wait, so the SPA can back off instead of retrying blindly.
 *
 * Sign-in and resend-verification use separate pools on purpose: flooding the
 * resend endpoint must not lock a user out of the account they are trying to
 * confirm, and vice versa.
 *
 * The framework's own `login_throttling` is declared in security.yaml as well,
 * but its listener never runs in this setup (verified over real HTTP: no counter
 * is ever written), so the policy is enforced here, before the password is
 * checked at all.
 */
final class AuthAttemptLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.login_email')]
        private readonly RateLimiterFactory $loginByEmail,
        #[Autowire(service: 'limiter.login_ip')]
        private readonly RateLimiterFactory $loginByClient,
        #[Autowire(service: 'limiter.resend_email')]
        private readonly RateLimiterFactory $resendByEmail,
        #[Autowire(service: 'limiter.resend_ip')]
        private readonly RateLimiterFactory $resendByClient,
    ) {
    }

    /**
     * Consumes one sign-in attempt.
     *
     * @return int|null seconds to wait when the attempt must be refused, null when allowed
     */
    public function consumeLogin(Request $request, ?string $email): ?int
    {
        return $this->consume($this->loginByEmail, $this->loginByClient, $request, $email);
    }

    /**
     * Consumes one resend-verification request.
     *
     * @return int|null seconds to wait when the request must be refused, null when allowed
     */
    public function consumeResend(Request $request, ?string $email): ?int
    {
        return $this->consume($this->resendByEmail, $this->resendByClient, $request, $email);
    }

    /** The address of a successful sign-in stops counting against its bucket. */
    public function resetFor(Principal $principal): void
    {
        $this->loginByEmail->create($principal->getEmail())->reset();
    }

    private function consume(
        RateLimiterFactory $byEmail,
        RateLimiterFactory $byClient,
        Request $request,
        ?string $email,
    ): ?int {
        $buckets = [$byClient->create($request->getClientIp() ?? 'unknown')];
        if (null !== $email && '' !== $email) {
            // The same normalization the account lookup uses: `A@B.test` and
            // `a@b.test` must share one bucket.
            $normalized = mb_strtolower(trim($email));
            $buckets[] = $byEmail->create($normalized);
        }

        $retryAfter = null;
        foreach ($buckets as $bucket) {
            $limit = $bucket->consume();
            if ($limit->isAccepted()) {
                continue;
            }

            $seconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            $retryAfter = null === $retryAfter ? $seconds : max($retryAfter, $seconds);
        }

        return $retryAfter;
    }
}
