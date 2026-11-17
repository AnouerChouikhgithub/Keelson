<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuthTokenRepository;
use App\Util\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single-use, hashed token for one out-of-band flow.
 *
 * Only the SHA-256 of the token is stored, so a database read cannot be turned
 * into a working link. "Used" and "expired" are separate columns rather than a
 * deleted row: the record is the audit trail of the flow, and consuming marks
 * it instead of destroying the evidence.
 *
 * Consumption is a conditional UPDATE in {@see AuthTokenRepository}, not a
 * setter here: the row has to decide for itself which of two concurrent
 * requests wins, and object state cannot express a compare-and-set.
 */
#[ORM\Entity(repositoryClass: AuthTokenRepository::class)]
#[ORM\Table(name: 'auth_tokens')]
#[ORM\UniqueConstraint(name: 'uniq_auth_tokens_token_hash', fields: ['tokenHash'])]
#[ORM\Index(name: 'idx_auth_tokens_principal_type', fields: ['principal', 'type'])]
class AuthToken
{
    public const TYPE_VERIFY = 'verify';
    public const TYPE_RESET = 'reset';

    public const TYPES = [self::TYPE_VERIFY, self::TYPE_RESET];

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Principal::class)]
    #[ORM\JoinColumn(name: 'principal_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Principal $principal;

    #[ORM\Column(length: 16)]
    private string $type;

    // CHAR(64), not VARCHAR: every digest is exactly 64 hex characters.
    #[ORM\Column(name: 'token_hash', length: 64, options: ['fixed' => true])]
    // Compared and written by SQL; PHP never reads the digest.
    protected string $tokenHash;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'used_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Principal $principal,
        string $type,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $now = null,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Auth token type "%s" is not supported.', $type));
        }
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $tokenHash)) {
            throw new \InvalidArgumentException('Auth token hash must be a lower-case SHA-256 digest.');
        }

        $this->id = Uuid::v4();
        $now ??= new \DateTimeImmutable();
        $this->principal = $principal;
        $this->type = $type;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
