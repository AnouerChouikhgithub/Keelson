<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\PrincipalRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A person who can sign in.
 *
 * The account is identified by its email address: it is normalized
 * (trimmed, lower-cased) here rather than by callers, because the unique index
 * and the login provider both look it up verbatim. Passwords are never stored:
 * `password_hash` holds the output of Symfony's password hasher, and the
 * plaintext only ever exists in the request that submitted it.
 */
#[ORM\Entity(repositoryClass: PrincipalRepository::class)]
#[ORM\Table(name: 'principals')]
#[ORM\UniqueConstraint(name: 'uniq_principals_email', fields: ['email'])]
class Principal implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const EMAIL_MAX_LENGTH = 320;
    public const DISPLAY_NAME_MAX_LENGTH = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(length: self::EMAIL_MAX_LENGTH)]
    private string $email;

    #[ORM\Column(name: 'display_name', length: 255, nullable: true)]
    private ?string $displayName;

    #[ORM\Column(name: 'password_hash', length: 255, nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(name: 'email_verified_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(name: 'last_login_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $email, ?string $displayName = null, ?\DateTimeImmutable $now = null)
    {
        $now ??= new \DateTimeImmutable();
        $this->email = self::normalizeEmail($email);
        $this->displayName = self::normalizeDisplayName($displayName);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Single place that decides what an email address looks like.
     *
     * Registration, login and the password-reset request all normalize through
     * here so `A@B.test` and `a@b.test` are the same account.
     */
    public static function normalizeEmail(string $email): string
    {
        $normalized = mb_strtolower(trim($email));
        if ('' === $normalized || mb_strlen($normalized) > self::EMAIL_MAX_LENGTH || false === filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(sprintf('Email must be a valid address of at most %d characters.', self::EMAIL_MAX_LENGTH));
        }

        return $normalized;
    }

    public static function normalizeDisplayName(?string $displayName): ?string
    {
        if (null === $displayName) {
            return null;
        }

        $normalized = trim($displayName);
        if ('' === $normalized) {
            return null;
        }
        if (mb_strlen($normalized) > self::DISPLAY_NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Display name must contain at most %d characters.', self::DISPLAY_NAME_MAX_LENGTH));
        }

        return $normalized;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setPasswordHash(string $passwordHash, ?\DateTimeImmutable $now = null): void
    {
        if ('' === trim($passwordHash)) {
            throw new \InvalidArgumentException('A password hash is required.');
        }

        $this->passwordHash = $passwordHash;
        $this->touch($now);
    }

    public function markEmailVerified(?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $this->emailVerifiedAt ??= $now;
        $this->touch($now);
    }

    public function recordLogin(?\DateTimeImmutable $now = null): void
    {
        $this->lastLoginAt = $now ?? new \DateTimeImmutable();
    }

    public function changeDisplayName(?string $displayName, ?\DateTimeImmutable $now = null): void
    {
        $this->displayName = self::normalizeDisplayName($displayName);
        $this->touch($now);
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
        // Nothing transient is held: only the hash is stored.
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** Required by {@see PasswordAuthenticatedUserInterface}. */
    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    private function touch(?\DateTimeImmutable $now = null): void
    {
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }
}
