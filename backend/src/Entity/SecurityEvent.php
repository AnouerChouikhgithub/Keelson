<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SecurityEventRepository;
use App\Util\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One security-relevant fact, written by the code that handled it.
 *
 * The row is deliberately thin and free of secrets: an email address is stored
 * only as a truncated hash, and no handler passes a password, a session token or
 * an invitation token here (there is a test that scans the table for exactly
 * that). `request_id` joins the entry to the application log line.
 */
#[ORM\Entity(repositoryClass: SecurityEventRepository::class)]
#[ORM\Table(name: 'security_events')]
#[ORM\Index(name: 'idx_security_events_type_created', columns: ['type', 'created_at'])]
#[ORM\Index(name: 'idx_security_events_principal_created', columns: ['principal_id', 'created_at'])]
class SecurityEvent
{
    public const LOGIN_SUCCESS = 'login_success';
    public const LOGIN_FAILURE = 'login_failure';
    public const PASSWORD_CHANGED = 'password_changed';
    public const INVITATION_ACCEPTED = 'invitation_accepted';
    public const AI_ENABLED = 'ai_enabled';

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** References a principal that may be deleted later; the trail keeps its id. */
    #[ORM\Column(name: 'principal_id', type: Types::GUID, nullable: true)]
    private ?string $principalId;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $type;

    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $ip;

    #[ORM\Column(name: 'user_agent', type: Types::STRING, length: 255, nullable: true)]
    private ?string $userAgent;

    #[ORM\Column(name: 'request_id', type: Types::STRING, length: 64, nullable: true)]
    private ?string $requestId;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $data;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, scalar|null> $data */
    public function __construct(
        string $type,
        ?string $principalId,
        ?string $ip,
        ?string $userAgent,
        ?string $requestId,
        array $data = [],
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = Uuid::v4();
        $this->type = $type;
        $this->principalId = $principalId;
        $this->ip = $ip;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
        $this->requestId = $requestId;
        $this->data = $data;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }
}
