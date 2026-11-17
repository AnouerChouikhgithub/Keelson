<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationRepository;
use App\Util\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organizations')]
#[ORM\UniqueConstraint(name: 'uniq_organizations_subdomain', fields: ['subdomain'])]
#[ORM\Index(name: 'idx_organizations_status', fields: ['status'])]
class Organization
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 63)]
    private string $subdomain;

    #[ORM\Column(length: 16)]
    private string $status = 'active';

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $subdomain, ?\DateTimeImmutable $now = null)
    {
        $normalizedSubdomain = strtolower(trim($subdomain));
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $normalizedSubdomain)) {
            throw new \InvalidArgumentException('Subdomain must be a normalized DNS label.');
        }

        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Organization name must contain 1 to 120 characters.');
        }

        $this->id = Uuid::v4();
        $now ??= new \DateTimeImmutable();
        $this->name = $name;
        $this->subdomain = $normalizedSubdomain;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSubdomain(): string
    {
        return $this->subdomain;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function rename(string $name, ?\DateTimeImmutable $now = null): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Organization name must contain 1 to 120 characters.');
        }
        $this->name = $name;
        $this->touch($now);
    }

    public function changeSubdomain(string $subdomain, ?\DateTimeImmutable $now = null): void
    {
        $normalized = strtolower(trim($subdomain));
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $normalized)) {
            throw new \InvalidArgumentException('Subdomain must be a normalized DNS label.');
        }
        $this->subdomain = $normalized;
        $this->touch($now);
    }

    /**
     * Soft delete: the row stays so invoices, audit events and memberships keep
     * their referential integrity, and every read path filters on `status`.
     */
    public function markDeleted(?\DateTimeImmutable $now = null): void
    {
        $this->status = 'deleted';
        $this->touch($now);
    }

    private function touch(?\DateTimeImmutable $now = null): void
    {
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }
}
