<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthToken;
use App\Entity\Principal;
use App\Exception\AuthTokenException;
use App\Repository\AuthTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues and consumes the hashed single-use tokens behind email verification
 * and password reset.
 *
 * The plaintext token exists only in the outgoing email and in the request that
 * redeems it; the database stores `hash('sha256', $plaintext)`. Tokens are
 * 32 random bytes encoded as base64url, so they survive a link without escaping.
 */
final class AuthTokenService
{
    /** Verification links are valid for a day, reset links for an hour. */
    private const EXPIRY = [
        AuthToken::TYPE_VERIFY => 'PT24H',
        AuthToken::TYPE_RESET => 'PT1H',
    ];

    public function __construct(
        private readonly AuthTokenRepository $tokens,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Issues a token and returns its plaintext.
     *
     * `$now` is only for tests that need an already-expired token; production
     * callers omit it.
     */
    public function issue(Principal $principal, string $type, ?\DateTimeImmutable $now = null): string
    {
        if (!isset(self::EXPIRY[$type])) {
            throw new \InvalidArgumentException(sprintf('Auth token type "%s" is not supported.', $type));
        }

        $now ??= new \DateTimeImmutable();
        // A new link retires the previous one for this principal.
        $this->tokens->invalidateUnused($principal, $type, $now);

        $plaintext = self::encode(random_bytes(32));
        $token = new AuthToken(
            $principal,
            $type,
            self::hash($plaintext),
            $now->add(new \DateInterval(self::EXPIRY[$type])),
            $now,
        );
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $plaintext;
    }

    /**
     * Redeems a token, marking it used.
     *
     * Unknown, expired and already-used tokens all throw the same exception, so
     * a caller cannot learn whether a link ever existed.
     */
    public function consume(string $plaintext, string $type, ?\DateTimeImmutable $now = null): Principal
    {
        $now ??= new \DateTimeImmutable();
        $token = '' === $plaintext ? null : $this->tokens->findUsable(self::hash($plaintext), $type, $now);
        if (null === $token) {
            throw AuthTokenException::unusable();
        }

        $token->markUsed($now);
        $this->entityManager->flush();

        return $token->getPrincipal();
    }

    private static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
