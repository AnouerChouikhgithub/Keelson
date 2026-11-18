<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Principal;
use App\Security\AuthAttemptLimiter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What one sign-in attempt costs, and which counter a successful one clears.
 *
 * The failure this guards against: a bucket keyed on the address alone lets a
 * stranger spend five requests to lock the account's owner out for a minute
 * (a cheap denial of service), and a bucket cleared by any successful sign-in
 * lets an attacker restart the budget for an address they already know.
 *
 * The buckets live in the rate-limiter cache, so every test uses an address of
 * its own: a leftover counter would otherwise make an assertion order-dependent.
 */
final class AuthThrottlingTest extends KernelTestCase
{
    private AuthAttemptLimiter $limiter;
    private RequestStack $requests;
    private string $suffix;

    protected function setUp(): void
    {
        self::bootKernel();

        $limiter = self::getContainer()->get(AuthAttemptLimiter::class);
        self::assertInstanceOf(AuthAttemptLimiter::class, $limiter);
        $this->limiter = $limiter;

        $requests = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requests);
        $this->requests = $requests;

        $this->suffix = bin2hex(random_bytes(6));
    }

    /**
     * Five failures count against the address they were sent from, not against
     * the account, so another client address is unaffected.
     */
    public function testFiveFailuresBlockOnlyTheAddressThatSentThem(): void
    {
        $email = 'throttle-'.$this->suffix.'-pair@example.test';

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            self::assertNull(
                $this->limiter->consumeLogin($this->from('10.20.0.1'), $email),
                sprintf('Attempt %d is inside the budget.', $attempt),
            );
        }

        self::assertNotNull(
            $this->limiter->consumeLogin($this->from('10.20.0.1'), $email),
            'The sixth attempt from one address must be refused.',
        );
        self::assertNull(
            $this->limiter->consumeLogin($this->from('10.20.0.2'), $email),
            'A stranger must not be able to lock an account out by failing from somewhere else.',
        );
    }

    /**
     * A per-address ceiling still bounds a grind spread over many hosts, but at
     * 50 attempts an hour so a legitimate user is not locked out by one.
     */
    public function testThePerAddressCeilingStopsADistributedGrind(): void
    {
        $email = 'throttle-'.$this->suffix.'-ceiling@example.test';

        for ($attempt = 1; $attempt <= 50; ++$attempt) {
            // One fresh client address per attempt: no email+address bucket is
            // exhausted before the ceiling is.
            $ip = sprintf('10.21.%d.%d', intdiv($attempt - 1, 250), (($attempt - 1) % 250) + 1);
            self::assertNull(
                $this->limiter->consumeLogin($this->from($ip), $email),
                sprintf('Attempt %d is inside the per-address ceiling.', $attempt),
            );
        }

        self::assertNotNull(
            $this->limiter->consumeLogin($this->from('10.22.0.9'), $email),
            'The 51st attempt against one address must be refused.',
        );
    }

    /**
     * Knowing the password clears the budget for that address only: it must not
     * hand an attacker a fresh budget for a bucket they were filling from
     * elsewhere, and it must not clear the address-wide ceiling.
     */
    public function testASuccessfulSignInClearsTheEmailAndAddressBucketOnly(): void
    {
        $email = 'throttle-'.$this->suffix.'-reset@example.test';
        $principal = new Principal($email, 'Throttle User');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->limiter->consumeLogin($this->from('10.23.0.1'), $email);
        }
        self::assertNotNull($this->limiter->consumeLogin($this->from('10.23.0.1'), $email));

        $this->requests->push($this->from('10.23.0.1'));
        try {
            $this->limiter->resetFor($principal);
        } finally {
            $this->requests->pop();
        }

        self::assertNull(
            $this->limiter->consumeLogin($this->from('10.23.0.1'), $email),
            'The successful sign-in frees the bucket for the address it came from.',
        );

        // The other address keeps its own budget: somebody else's success is not
        // its reset.
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            self::assertNull(
                $this->limiter->consumeLogin($this->from('10.23.0.2'), $email),
                sprintf('The other address still has %d attempts of its own.', 6 - $attempt),
            );
        }
        self::assertNotNull($this->limiter->consumeLogin($this->from('10.23.0.2'), $email));
    }

    /**
     * An address that is not a valid email still has to be counted, and must not
     * turn the pre-firewall listener into a 500.
     */
    public function testAnInvalidAddressIsCountedWithoutThrowing(): void
    {
        self::assertNull($this->limiter->consumeLogin($this->from('10.24.0.1'), 'not-an-address'));
    }

    /**
     * Case and surrounding whitespace are the cheap way to mint a fresh bucket
     * out of one address; every key goes through Principal::normalizeEmail().
     */
    public function testAddressVariantsShareOneBucket(): void
    {
        $email = 'Throttle-'.$this->suffix.'-Mixed@Example.Test';
        $variants = [$email, strtoupper($email), strtolower($email), '  '.$email.'  ', strtolower($email)];

        foreach ($variants as $attempt => $variant) {
            self::assertNull(
                $this->limiter->consumeLogin($this->from('10.25.0.1'), $variant),
                sprintf('Attempt %d uses the same account key.', $attempt + 1),
            );
        }

        self::assertNotNull(
            $this->limiter->consumeLogin($this->from('10.25.0.1'), strtoupper($email)),
            'A sixth spelling of the same address must find the bucket full.',
        );
    }

    /**
     * The key a budget is stored under is the account key, not an approximation
     * of it: every address-keyed limiter asks this method.
     */
    public function testTheBucketKeyIsTheNormalizedAccountEmail(): void
    {
        self::assertSame('owner@example.test', AuthAttemptLimiter::emailKey('  Owner@Example.Test  '));
        self::assertNull(AuthAttemptLimiter::emailKey('   '));
        self::assertNull(AuthAttemptLimiter::emailKey(null));
        // Malformed input still produces a stable key instead of an exception:
        // the limiters run before validation.
        self::assertSame('not-an-address', AuthAttemptLimiter::emailKey('Not-An-Address'));
    }

    private function from(string $ip): Request
    {
        return Request::create('/api/auth/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
    }
}
