<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationRepository;
use App\Util\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * One message for one person about something that happened in an organization.
 *
 * Notifications are per recipient on purpose: fan-out happens at write time, so
 * the read path is a single indexed query on (principal, read state, date) and
 * never has to work out "who should have seen this". `data` carries the few
 * facts a renderer needs (ids and numbers), never a copy of the record.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notifications_principal_read_created', fields: ['principal', 'readAt', 'createdAt'])]
class Notification
{
    public const TYPE_INVOICE_OVERDUE = 'invoice_overdue';
    public const TYPE_INVOICE_PAID = 'invoice_paid';
    public const TYPE_INVITATION_ACCEPTED = 'invitation_accepted';
    public const TYPE_MEMBER_JOINED = 'member_joined';

    public const TYPES = [
        self::TYPE_INVOICE_OVERDUE,
        self::TYPE_INVOICE_PAID,
        self::TYPE_INVITATION_ACCEPTED,
        self::TYPE_MEMBER_JOINED,
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Principal::class)]
    #[ORM\JoinColumn(name: 'principal_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Principal $principal;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Organization $organization;

    #[ORM\Column(length: 32)]
    private string $type;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: 'json')]
    private array $data;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'read_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    /** @param array<string, scalar|null> $data */
    public function __construct(
        Principal $principal,
        ?Organization $organization,
        string $type,
        array $data = [],
        ?\DateTimeImmutable $now = null,
    ) {
        $this->id = Uuid::v4();
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Notification type is not supported.');
        }
        $this->principal = $principal;
        $this->organization = $organization;
        $this->type = $type;
        $this->data = $data;
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /** @return array<string, scalar|null> */
    public function getData(): array
    {
        return $this->data;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    /** Marking an already-read notification again is a no-op, not an error. */
    public function markRead(?\DateTimeImmutable $now = null): bool
    {
        if (null !== $this->readAt) {
            return false;
        }
        $this->readAt = $now ?? new \DateTimeImmutable();

        return true;
    }
}
