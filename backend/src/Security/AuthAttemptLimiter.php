<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Principal;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Bounds how often one account, and one client address, may reach an
 * authentication endpoint that sends mail or checks a password.
 *
 * Sign-in consumes three buckets per attempt:
 *
 *  - email + client address, five a minute: one account's password budget as
 *    used from one place;
 *  - the client address alone, 100 a minute: one host cannot spread the same
 *    effort over many accounts;
 *  - the address alone, 50 an hour: a ceiling that survives an attacker rotating
 *    client addresses.
 *
 * Keying the five-a-minute bucket on the *pair* is what keeps a stranger from
 * locking a known account out: their failures fill their own bucket, not the
 * account's. The ceiling still bounds guessing, but at a rate that does not hand
 * anyone a five-request denial of service.
 *
 * Resend-verification uses separate pools on purpose: flooding the resend
 * endpoint must not lock a user out of the account they are trying to confirm,
 * and vice versa.
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
        #[Autowire(service: 'limiter.login_account')]
        private readonly RateLimiterFactory $loginByAccount,
        #[Autowire(service: 'limiter.resend_email')]
        private readonly RateLimiterFactory $resendByEmail,
        #[Autowire(service: 'limiter.resend_ip')]
        private readonly RateLimiterFactory $resendByClient,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * Consumes one sign-in attempt.
     *
     * @return int|null seconds to wait when the attempt must be refused, null when allowed
     */
    public function consumeLogin(Request $request, ?string $email): ?int
    {
        $client = $request->getClientIp() ?? 'unknown';
        $buckets = [$this->loginByClient->create($client)];

        $account = self::emailKey($email);
        if (null !== $account) {
            $buckets[] = $this->loginByEmail->create($account.'|'.$client);
            $buckets[] = $this->loginByAccount->create($account);
        }

        return $this->consume($buckets);
    }

    /**
     * Consumes one resend-verification request.
     *
     * @return int|null seconds to wait when the request must be refused, null when allowed
     */
    public function consumeResend(Request $request, ?string $email): ?int
    {
        $buckets = [$this->resendByClient->create($request->getClientIp() ?? 'unknown')];

        $account = self::emailKey($email);
        if (null !== $account) {
            $buckets[] = $this->resendByEmail->create($account);
        }

        return $this->consume($buckets);
    }

    /**
     * A successful sign-in frees the email+address bucket it was sent from, so a
     * user who mistyped is not locked out.
     *
     * Only that bucket: the client address budget and the per-address ceiling
     * keep counting, because an attacker who guesses one password must not
     * thereby buy a fresh budget for the next one.
     */
    public function resetFor(Principal $principal): void
    {
        $request = $this->requests->getCurrentRequest();
        $account = self::emailKey($principal->getEmail());
        if (null === $request || null === $account) {
            return;
        }

        $this->loginByEmail->create($account.'|'.($request->getClientIp() ?? 'unknown'))->reset();
    }

    /**
     * One key per account, whatever case or surrounding whitespace the request
     * used: `A@B.test` and ` a@b.test ` must share the bucket of `a@b.test`.
     *
     * The key is {@see Principal::normalizeEmail()}'s output — the same value the
     * account lookup uses — so a bucket can never disagree with the account it
     * counts. {@see RateLimitListener} uses this too, so every address-keyed
     * budget is built the same way.
     *
     * That normalization rejects an invalid address, and these limiters run
     * before validation, so a malformed one falls back to the trimmed input
     * rather than throwing in front of the firewall.
     */
    public static function emailKey(?string $email): ?string
    {
        $email = null === $email ? '' : trim($email);
        if ('' === $email) {
            return null;
        }

        try {
            return Principal::normalizeEmail($email);
        } catch (\InvalidArgumentException) {
            return mb_strtolower($email);
        }
    }

    /**
     * @param list<LimiterInterface> $buckets
     *
     * @return int|null the longest wait any exhausted bucket asks for, null when all accepted
     */
    private function consume(array $buckets): ?int
    {
        $retryAfter = null;
        foreach ($buckets as $bucket) {
            $limit = $bucket->consume();
            if ($limit->isAccepted()) {
                continue;
            }

            $seconds = (int) max(1, $limit->getRetryAfter()->getTimestamp() - time());
            $retryAfter = null === $retryAfter ? $seconds : max($retryAfter, $seconds);
        }

        return $retryAfter;
    }
}
