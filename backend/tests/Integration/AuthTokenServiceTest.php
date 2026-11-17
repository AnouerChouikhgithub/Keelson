<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AuthToken;
use App\Entity\Principal;
use App\Exception\AuthTokenException;
use App\Service\AuthTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The token service is the whole security boundary of the mailed links: the
 * plaintext never reaches the database, and every way a link can be unusable
 * answers identically.
 *
 * It runs against the real repository because the lookup, the "still unused"
 * condition and the invalidation of previous tokens are SQL, not object state.
 */
final class AuthTokenServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private AuthTokenService $tokens;
    private Principal $principal;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $tokens = self::getContainer()->get(AuthTokenService::class);
        self::assertInstanceOf(AuthTokenService::class, $tokens);
        $this->tokens = $tokens;

        $this->principal = new Principal('token-'.bin2hex(random_bytes(6)).'@example.test', 'Token Test User');
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM principals WHERE id = ?',
                [$this->principal->getId()],
            );
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    public function testOnlyTheDigestOfTheTokenIsStored(): void
    {
        $plaintext = $this->tokens->issue($this->principal, AuthToken::TYPE_VERIFY);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $plaintext, 'A URL-safe 32-byte token is expected.');

        $stored = (string) $this->entityManager->getConnection()->fetchOne(
            'SELECT token_hash FROM auth_tokens WHERE principal_id = ?',
            [$this->principal->getId()],
        );
        self::assertSame(hash('sha256', $plaintext), $stored);
        self::assertStringNotContainsString($plaintext, $stored);
    }

    public function testRedeemingATokenReturnsItsPrincipalAndMarksItUsed(): void
    {
        $plaintext = $this->tokens->issue($this->principal, AuthToken::TYPE_RESET);

        $principal = $this->tokens->redeem($plaintext, AuthToken::TYPE_RESET, static function (Principal $principal): void {
            // No account mutation needed to prove the redemption itself.
        });

        self::assertSame($this->principal->getId(), $principal->getId());
        $usedAt = $this->entityManager->getConnection()->fetchOne(
            'SELECT used_at FROM auth_tokens WHERE principal_id = ?',
            [$this->principal->getId()],
        );
        self::assertNotNull($usedAt);
    }

    public function testAUsedTokenCannotBeReplayed(): void
    {
        $plaintext = $this->tokens->issue($this->principal, AuthToken::TYPE_VERIFY);
        $this->redeem($plaintext, AuthToken::TYPE_VERIFY);

        $this->expectException(AuthTokenException::class);
        $this->redeem($plaintext, AuthToken::TYPE_VERIFY);
    }

    public function testAnExpiredTokenIsRejectedLikeAnUnknownOne(): void
    {
        $expired = $this->tokens->issue(
            $this->principal,
            AuthToken::TYPE_VERIFY,
            new \DateTimeImmutable('-25 hours'),
        );

        $this->expectException(AuthTokenException::class);
        $this->redeem($expired, AuthToken::TYPE_VERIFY);
    }

    public function testATokenIsBoundToItsType(): void
    {
        $verify = $this->tokens->issue($this->principal, AuthToken::TYPE_VERIFY);

        $this->expectException(AuthTokenException::class);
        $this->redeem($verify, AuthToken::TYPE_RESET);
    }

    public function testAnUnknownOrEmptyTokenIsRejected(): void
    {
        $this->expectException(AuthTokenException::class);
        $this->redeem('', AuthToken::TYPE_VERIFY);
    }

    public function testIssuingANewTokenRetiresThePreviousOne(): void
    {
        $first = $this->tokens->issue($this->principal, AuthToken::TYPE_RESET);
        $second = $this->tokens->issue($this->principal, AuthToken::TYPE_RESET);

        self::assertNotSame($first, $second);

        try {
            $this->redeem($first, AuthToken::TYPE_RESET);
            self::fail('The superseded token must not be usable.');
        } catch (AuthTokenException) {
            // Expected: only the newest link works.
        }

        self::assertSame($this->principal->getId(), $this->redeem($second, AuthToken::TYPE_RESET)->getId());
    }

    public function testATokenOfAnotherTypeIsNotRetiredByIssuingANewOne(): void
    {
        $verify = $this->tokens->issue($this->principal, AuthToken::TYPE_VERIFY);
        $this->tokens->issue($this->principal, AuthToken::TYPE_RESET);

        self::assertSame($this->principal->getId(), $this->redeem($verify, AuthToken::TYPE_VERIFY)->getId());
    }

    public function testTheExposedExpiryWindowsAreTheDocumentedOnes(): void
    {
        $this->tokens->issue($this->principal, AuthToken::TYPE_VERIFY);
        $this->tokens->issue($this->principal, AuthToken::TYPE_RESET);

        $expiries = $this->entityManager->getConnection()->fetchAllKeyValue(
            'SELECT type, EXTRACT(EPOCH FROM expires_at - created_at)::bigint AS lifetime FROM auth_tokens WHERE principal_id = ?',
            [$this->principal->getId()],
        );

        self::assertSame(86400, (int) $expiries[AuthToken::TYPE_VERIFY]);
        self::assertSame(3600, (int) $expiries[AuthToken::TYPE_RESET]);
    }

    public function testAnUnsupportedTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tokens->issue($this->principal, 'magic');
    }

    private function redeem(string $plaintext, string $type): Principal
    {
        return $this->tokens->redeem($plaintext, $type, static function (Principal $principal): void {
            // The token flows under test only need the redemption itself.
        });
    }
}
