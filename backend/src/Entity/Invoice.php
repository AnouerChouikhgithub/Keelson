<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoices')]
#[ORM\UniqueConstraint(name: 'uniq_invoices_organization_number', fields: ['organization', 'number'])]
#[ORM\Index(name: 'idx_invoices_organization_status_issue', fields: ['organization', 'status', 'issueDate'])]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'invoices')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Customer $customer;

    #[ORM\Column(length: 40)]
    private string $number;

    #[ORM\Column(length: 16)]
    private string $status = 'draft';

    #[ORM\Column(name: 'customer_snapshot', type: 'json')]
    private array $customerSnapshot;

    #[ORM\Column(name: 'issue_date', type: 'date_immutable')]
    private \DateTimeImmutable $issueDate;

    #[ORM\Column(name: 'due_date', type: 'date_immutable')]
    private \DateTimeImmutable $dueDate;

    /** @var list<array{description: string, quantity: string, unitPrice: string, lineTotal: string}> */
    #[ORM\Column(name: 'line_items', type: 'json')]
    private array $lineItems;

    #[ORM\Column(name: 'tax_rate', type: 'decimal', precision: 7, scale: 4, nullable: true)]
    private ?string $taxRate;

    #[ORM\Column(name: 'discount_amount', type: 'decimal', precision: 14, scale: 3, nullable: true)]
    private ?string $discountAmount;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 3)]
    private string $subtotal;

    #[ORM\Column(name: 'tax_amount', type: 'decimal', precision: 14, scale: 3)]
    private string $taxAmount;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 3)]
    private string $total;

    #[ORM\Column(length: 3)]
    private string $currency = 'TND';

    #[ORM\Column(name: 'created_by_principal_id', type: 'guid')]
    private string $createdByPrincipalId;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'sent_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'paid_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    /** @param list<array{description: string, quantity: string, unitPrice: string, lineTotal: string}> $lineItems
     */
    public function __construct(
        Organization $organization,
        Customer $customer,
        string $number,
        string $createdByPrincipalId,
        \DateTimeImmutable $issueDate,
        \DateTimeImmutable $dueDate,
        array $lineItems,
        ?string $taxRate,
        ?string $discountAmount,
        string $subtotal,
        string $taxAmount,
        string $total,
        ?\DateTimeImmutable $now = null,
    ) {
        if ($customer->getOrganization() !== $organization) {
            throw new \InvalidArgumentException('Invoice customer must belong to the same organization.');
        }
        if ('' === trim($number) || mb_strlen($number) > 40) {
            throw new \InvalidArgumentException('Invoice number must contain 1 to 40 characters.');
        }
        if ($dueDate < $issueDate) {
            throw new \InvalidArgumentException('Invoice due date cannot precede issue date.');
        }
        $this->organization = $organization;
        $this->customer = $customer;
        $this->number = $number;
        $this->createdByPrincipalId = $createdByPrincipalId;
        $this->issueDate = $issueDate;
        $this->dueDate = $dueDate;
        $this->lineItems = $lineItems;
        $this->taxRate = $taxRate;
        $this->discountAmount = $discountAmount;
        $this->subtotal = $subtotal;
        $this->taxAmount = $taxAmount;
        $this->total = $total;
        $this->customerSnapshot = [
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhone(),
            'billingAddress' => $customer->getBillingAddress(),
        ];
        $this->createdAt = $now ?? new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /** @param list<array{description: string, quantity: string, unitPrice: string, lineTotal: string}> $lineItems */
    public function updateDraft(
        Customer $customer,
        \DateTimeImmutable $issueDate,
        \DateTimeImmutable $dueDate,
        array $lineItems,
        ?string $taxRate,
        ?string $discountAmount,
        string $subtotal,
        string $taxAmount,
        string $total,
        ?\DateTimeImmutable $now = null,
    ): void {
        if ('draft' !== $this->status) {
            throw new \DomainException('Only draft invoices may be edited.');
        }
        if ($customer->getOrganization() !== $this->organization) {
            throw new \InvalidArgumentException('Invoice customer must belong to the same organization.');
        }
        if ($dueDate < $issueDate) {
            throw new \InvalidArgumentException('Invoice due date cannot precede issue date.');
        }
        $this->customer = $customer;
        $this->issueDate = $issueDate;
        $this->dueDate = $dueDate;
        $this->lineItems = $lineItems;
        $this->taxRate = $taxRate;
        $this->discountAmount = $discountAmount;
        $this->subtotal = $subtotal;
        $this->taxAmount = $taxAmount;
        $this->total = $total;
        $this->customerSnapshot = [
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhone(),
            'billingAddress' => $customer->getBillingAddress(),
        ];
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    public function send(?\DateTimeImmutable $now = null): bool
    {
        if ('sent' === $this->status || 'overdue' === $this->status || 'paid' === $this->status) {
            return false;
        }
        if ('draft' !== $this->status) {
            throw new \DomainException('Invoice cannot be sent from its current state.');
        }
        $this->status = 'sent';
        $this->sentAt = $now ?? new \DateTimeImmutable();
        $this->updatedAt = $this->sentAt;

        return true;
    }

    public function markPaid(?\DateTimeImmutable $now = null): bool
    {
        if ('paid' === $this->status) {
            return false;
        }
        if (!in_array($this->status, ['sent', 'overdue'], true)) {
            throw new \DomainException('Only sent or overdue invoices may be marked paid.');
        }
        $this->status = 'paid';
        $this->paidAt = $now ?? new \DateTimeImmutable();
        $this->updatedAt = $this->paidAt;

        return true;
    }

    public function deriveOverdue(?\DateTimeImmutable $today = null): bool
    {
        if ('sent' !== $this->status || $this->dueDate >= ($today ?? new \DateTimeImmutable('today'))) {
            return false;
        }
        $this->status = 'overdue';
        $this->updatedAt = $today ?? new \DateTimeImmutable();

        return true;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function getCustomerSnapshot(): array
    {
        return $this->customerSnapshot;
    }

    public function getIssueDate(): \DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function getDueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    /** @return list<array{description: string, quantity: string, unitPrice: string, lineTotal: string}> */
    public function getLineItems(): array
    {
        return $this->lineItems;
    }

    public function getTaxRate(): ?string
    {
        return $this->taxRate;
    }

    public function getDiscountAmount(): ?string
    {
        return $this->discountAmount;
    }

    public function getSubtotal(): string
    {
        return $this->subtotal;
    }

    public function getTaxAmount(): string
    {
        return $this->taxAmount;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
