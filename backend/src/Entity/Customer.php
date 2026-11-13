<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\CustomerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customers')]
#[ORM\UniqueConstraint(name: 'uniq_customers_organization_id_id', fields: ['organization', 'id'])]
#[ORM\Index(name: 'idx_customers_organization_id', fields: ['organization', 'id'])]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 320, nullable: true)]
    private ?string $email;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone;

    /** @var array{street: string, city: string, postalCode: string, countryCode: string}|null */
    #[ORM\Column(name: 'billing_address', type: 'json', nullable: true)]
    private ?array $billingAddress;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToMany(targetEntity: Invoice::class, mappedBy: 'customer')]
    private \Doctrine\Common\Collections\Collection $invoices;

    /** @param array<string, mixed>|null $billingAddress */
    public function __construct(
        Organization $organization,
        string $name,
        ?string $email = null,
        ?string $phone = null,
        ?array $billingAddress = null,
        ?\DateTimeImmutable $now = null,
    ) {
        $this->organization = $organization;
        $this->invoices = new \Doctrine\Common\Collections\ArrayCollection();
        $this->apply($name, $email, $phone, $billingAddress);
        $this->createdAt = $now ?? new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /** @param array<string, mixed>|null $billingAddress */
    public function update(string $name, ?string $email, ?string $phone, ?array $billingAddress, ?\DateTimeImmutable $now = null): void
    {
        $this->apply($name, $email, $phone, $billingAddress);
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    /** @return array{street: string, city: string, postalCode: string, countryCode: string}|null */
    public function getBillingAddress(): ?array
    {
        return $this->billingAddress;
    }

    /** @param array<string, mixed>|null $billingAddress */
    private function apply(string $name, ?string $email, ?string $phone, ?array $billingAddress): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 160) {
            throw new \InvalidArgumentException('Customer name must contain 1 to 160 characters.');
        }
        if (null !== $email && (strlen($email) > 320 || false === filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new \InvalidArgumentException('Customer email is invalid.');
        }
        if (null !== $phone && ('' === trim($phone) || mb_strlen($phone) > 40)) {
            throw new \InvalidArgumentException('Customer phone must contain 1 to 40 characters when provided.');
        }
        if (null !== $billingAddress) {
            $expected = ['street', 'city', 'postalCode', 'countryCode'];
            if (array_diff(array_keys($billingAddress), $expected) || array_diff($expected, array_keys($billingAddress))) {
                throw new \InvalidArgumentException('Billing address must include street, city, postalCode, and countryCode only.');
            }
            foreach ($expected as $field) {
                if (!is_string($billingAddress[$field]) || '' === trim($billingAddress[$field]) || mb_strlen($billingAddress[$field]) > 160) {
                    throw new \InvalidArgumentException('Billing address fields must contain 1 to 160 characters.');
                }
                $billingAddress[$field] = trim($billingAddress[$field]);
            }
            $billingAddress['countryCode'] = strtoupper($billingAddress['countryCode']);
            if (!preg_match('/^[A-Z]{2}$/', $billingAddress['countryCode'])) {
                throw new \InvalidArgumentException('Billing address countryCode must be a two-letter ISO country code.');
            }
            /* @var array{street: string, city: string, postalCode: string, countryCode: string} $billingAddress */
        }

        $this->name = $name;
        $this->email = null === $email ? null : strtolower(trim($email));
        $this->phone = null === $phone ? null : trim($phone);
        $this->billingAddress = $billingAddress;
    }
}
