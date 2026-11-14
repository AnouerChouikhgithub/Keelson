<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\InvoiceAuditEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceAuditEventRepository::class)]
#[ORM\Table(name: 'invoice_audit_events')]
#[ORM\Index(name: 'idx_invoice_audit_organization_created', fields: ['organization', 'createdAt'])]
#[ORM\Index(name: 'idx_invoice_audit_invoice_created', fields: ['invoiceId', 'createdAt'])]
final class InvoiceAuditEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(name: 'invoice_id', type: 'guid', nullable: true)]
    private ?string $invoiceId;

    #[ORM\Column(name: 'customer_id', type: 'guid', nullable: true)]
    private ?string $customerId;

    #[ORM\Column(length: 32)]
    private string $action;

    #[ORM\Column(name: 'actor_principal_id', type: 'guid')]
    private string $actorPrincipalId;

    #[ORM\Column(type: 'json')]
    private array $metadata;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        Organization $organization,
        ?string $invoiceId,
        ?string $customerId,
        string $action,
        string $actorPrincipalId,
        array $metadata = [],
        ?\DateTimeImmutable $now = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,31}$/', $action)) {
            throw new \InvalidArgumentException('Audit action is invalid.');
        }
        $this->organization = $organization;
        $this->invoiceId = $invoiceId;
        $this->customerId = $customerId;
        $this->action = $action;
        $this->actorPrincipalId = $actorPrincipalId;
        $this->metadata = $metadata;
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getInvoiceId(): ?string
    {
        return $this->invoiceId;
    }

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getActorPrincipalId(): string
    {
        return $this->actorPrincipalId;
    }

    /** @return array<string, scalar|null> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
