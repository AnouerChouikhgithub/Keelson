<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organizations')]
#[ORM\UniqueConstraint(name: 'uniq_organizations_subdomain', fields: ['subdomain'])]
#[ORM\Index(name: 'idx_organizations_status', fields: ['status'])]
class Organization
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
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

        $now ??= new \DateTimeImmutable();
        $this->name = $name;
        $this->subdomain = $normalizedSubdomain;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }
}
