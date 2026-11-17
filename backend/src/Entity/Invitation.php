<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvitationRepository;
use App\Util\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * A pending offer to join an organization.
 *
 * The token is stored as a SHA-256 digest only, like the auth tokens; the
 * plaintext exists in the invitation email and in the request that redeems it.
 * `status` is the single source of truth for the lifecycle — `pending`,
 * `accepted`, `revoked` — and `expiresAt` decides whether a still-pending
 * invitation can be redeemed. Expiry is never written by a read: a GET reports
 * `expired` and leaves the row alone, so two readers cannot fight over it.
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitations')]
#[ORM\Index(name: 'idx_invitations_organization_status', fields: ['organization', 'status'])]
#[ORM\Index(name: 'idx_invitations_email_status', fields: ['email', 'status'])]
#[ORM\UniqueConstraint(name: 'uniq_invitations_token_hash', fields: ['tokenHash'])]
class Invitation
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REVOKED = 'revoked';

    public const ROLE_ADMIN = 'Admin';
    public const ROLE_MEMBER = 'Member';
    public const ROLE_VIEWER = 'Viewer';

    /** How long a link stays usable; resending starts a fresh window. */
    public const LIFETIME = 'P7D';

    // The id is assigned when the invitation is created: the token is the
    // identity that matters outside, and an unset id would make the entity
    // unusable before its first flush.
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 320)]
    private string $email;

    #[ORM\Column(length: 16)]
    private string $role;

    #[ORM\Column(length: 64)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'token_hash', length: 255)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: Principal::class)]
    #[ORM\JoinColumn(name: 'invited_by_principal_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Principal $invitedBy;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'accepted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(name: 'revoked_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Organization $organization,
        string $email,
        string $role,
        string $tokenHash,
        Principal $invitedBy,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $now = null,
    ) {
        if (!in_array($role, [self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_VIEWER], true)) {
            throw new \InvalidArgumentException('Invitation role is not supported.');
        }
        if (strlen($tokenHash) < 32 || strlen($tokenHash) > 255) {
            throw new \InvalidArgumentException('Invitation token verifier must be a non-trivial hash.');
        }
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invitation email is invalid.');
        }
        if ($expiresAt <= ($now ?? new \DateTimeImmutable())) {
            throw new \InvalidArgumentException('Invitation expiry must be in the future.');
        }

        $this->id = Uuid::v4();
        $now ??= new \DateTimeImmutable();
        $this->organization = $organization;
        $this->email = Principal::normalizeEmail($email);
        $this->role = $role;
        $this->tokenHash = $tokenHash;
        $this->invitedBy = $invitedBy;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getInvitedBy(): Principal
    {
        return $this->invitedBy;
    }

    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->status;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    /** Redeemable: still pending and inside its window. */
    public function isUsable(?\DateTimeImmutable $now = null): bool
    {
        return $this->isPending() && !$this->isExpired($now);
    }

    /** A pending invitation can change role; anything else is history. */
    public function changeRole(string $role): void
    {
        if (!$this->isPending()) {
            throw new \DomainException('Only a pending invitation may change role.');
        }
        if (!in_array($role, [self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_VIEWER], true)) {
            throw new \InvalidArgumentException('Invitation role is not supported.');
        }
        $this->role = $role;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Resend: a brand-new token and a fresh window.
     *
     * The old link stops working immediately because the stored digest is
     * replaced, which is what "resend" has to mean for a leaked link.
     */
    public function reissue(string $tokenHash, ?\DateTimeImmutable $now = null): void
    {
        if (!$this->isPending()) {
            throw new \DomainException('Only a pending invitation may be resent.');
        }
        if (strlen($tokenHash) < 32 || strlen($tokenHash) > 255) {
            throw new \InvalidArgumentException('Invitation token verifier must be a non-trivial hash.');
        }
        $now ??= new \DateTimeImmutable();
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $now->add(new \DateInterval(self::LIFETIME));
        $this->updatedAt = $now;
    }

    public function accept(?\DateTimeImmutable $now = null): void
    {
        if (!$this->isUsable($now)) {
            throw new \DomainException('This invitation can no longer be accepted.');
        }
        $now ??= new \DateTimeImmutable();
        $this->status = self::STATUS_ACCEPTED;
        $this->acceptedAt = $now;
        $this->updatedAt = $now;
    }

    public function revoke(?\DateTimeImmutable $now = null): void
    {
        if (!$this->isPending()) {
            throw new \DomainException('Only a pending invitation may be revoked.');
        }
        $now ??= new \DateTimeImmutable();
        $this->status = self::STATUS_REVOKED;
        $this->revokedAt = $now;
        $this->updatedAt = $now;
    }
}
