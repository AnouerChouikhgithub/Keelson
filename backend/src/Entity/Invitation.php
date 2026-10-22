<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\InvitationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitations')]
#[ORM\Index(name: 'idx_invitations_organization_status', fields: ['organization', 'status'])]
#[ORM\Index(name: 'idx_invitations_email_status', fields: ['email', 'status'])]
#[ORM\UniqueConstraint(name: 'uniq_invitations_token_hash', fields: ['tokenHash'])]
class Invitation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
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
    private string $status = 'pending';

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
        if (!in_array($role, ['Admin', 'Member', 'Viewer'], true)) {
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

        $now ??= new \DateTimeImmutable();
        $this->organization = $organization;
        $this->email = $email;
        $this->role = $role;
        $this->tokenHash = $tokenHash;
        $this->invitedBy = $invitedBy;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }
}
