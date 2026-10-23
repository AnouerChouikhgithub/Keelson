<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\PrincipalRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: PrincipalRepository::class)]
#[ORM\Table(name: 'principals')]
#[ORM\UniqueConstraint(name: 'uniq_principals_issuer_subject', fields: ['issuer', 'subject'])]
class Principal implements UserInterface
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
        $this->issuer = trim($issuer);
        $this->subject = trim($subject);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getIssuer(): string
    {
        return $this->issuer;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function isEmailVerified(): ?bool
    {
        return $this->emailVerified;
    }

    public function updateProfile(?string $displayName, ?string $email, bool $emailVerified, ?\DateTimeImmutable $now = null): void
    {
        $this->displayName = $displayName;
        $this->email = $email;
        $this->emailVerified = $emailVerified;
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function __serialize(): array
    {
        return ['issuer' => $this->issuer, 'subject' => $this->subject];
    }

    public function __unserialize(array $data): void
    {
        $this->issuer = (string) $data['issuer'];
        $this->subject = (string) $data['subject'];
    }

    public function getUserIdentifier(): string
    {
        return $this->issuer.'|'.$this->subject;
    }
}
