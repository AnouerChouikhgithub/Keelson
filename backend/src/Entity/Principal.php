<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\PrincipalRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PrincipalRepository::class)]
#[ORM\Table(name: 'principals')]
#[ORM\UniqueConstraint(name: 'uniq_principals_issuer_subject', fields: ['issuer', 'subject'])]
class Principal
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(length: 512)]
    private string $issuer;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(name: 'display_name', length: 255, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(length: 320, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'email_verified', nullable: true)]
    private ?bool $emailVerified = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $issuer, string $subject, ?\DateTimeImmutable $now = null)
    {
        if ('' === trim($issuer) || '' === trim($subject)) {
            throw new \InvalidArgumentException('OIDC issuer and subject are required.');
        }

        $now ??= new \DateTimeImmutable();
        $this->issuer = $issuer;
        $this->subject = $subject;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }
}
