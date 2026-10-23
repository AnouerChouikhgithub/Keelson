<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\MembershipRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MembershipRepository::class)]
#[ORM\Table(name: 'memberships')]
#[ORM\UniqueConstraint(name: 'uniq_memberships_organization_principal', fields: ['organization', 'principal'])]
#[ORM\Index(name: 'idx_memberships_principal_status', fields: ['principal', 'status'])]
#[ORM\Index(name: 'idx_memberships_organization_role', fields: ['organization', 'role'])]
class Membership
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: Principal::class)]
    #[ORM\JoinColumn(name: 'principal_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Principal $principal;

    #[ORM\Column(length: 16)]
    private string $role;

    #[ORM\Column(length: 16)]
    private string $status = 'active';

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Organization $organization, Principal $principal, string $role, ?\DateTimeImmutable $now = null)
    {
        if (!in_array($role, ['Owner', 'Admin', 'Member', 'Viewer'], true)) {
            throw new \InvalidArgumentException('Membership role is not supported.');
        }

        $now ??= new \DateTimeImmutable();
        $this->organization = $organization;
        $this->principal = $principal;
        $this->role = $role;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function isActive(): bool
    {
        return 'active' === $this->status;
    }

    public function canAdminister(): bool
    {
        return $this->isActive() && in_array($this->role, ['Owner', 'Admin'], true);
    }

    public function isOwner(): bool
    {
        return $this->isActive() && 'Owner' === $this->role;
    }
}
