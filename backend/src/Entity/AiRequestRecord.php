<?php

declare(strict_types=1);

namespace App\Entity;

use App\Util\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * One AI provider call, for spend accounting.
 *
 * The row is deliberately content-free: it records which feature asked, the model,
 * token counts, latency, the outcome and an error code — never the prompt or the
 * answer. `organization_id` is a plain column rather than a foreign key so the ledger
 * survives a tenant being removed; `idx_ai_requests_organization_created` keeps
 * tenant-scoped reports cheap.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_requests')]
#[ORM\Index(name: 'idx_ai_requests_organization_created', columns: ['organization_id', 'created_at'])]
class AiRequestRecord
{
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'organization_id', type: 'guid')]
    private string $organizationId;

    #[ORM\Column(name: 'principal_id', type: 'guid', nullable: true)]
    private ?string $principalId;

    #[ORM\Column(length: 64)]
    private string $feature;

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $model;

    #[ORM\Column(name: 'prompt_tokens', type: 'integer')]
    private int $promptTokens;

    #[ORM\Column(name: 'completion_tokens', type: 'integer')]
    private int $completionTokens;

    #[ORM\Column(name: 'total_tokens', type: 'integer')]
    private int $totalTokens;

    #[ORM\Column(name: 'latency_ms', type: 'integer', nullable: true)]
    private ?int $latencyMs;

    #[ORM\Column(name: 'error_code', length: 64, nullable: true)]
    private ?string $errorCode;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $organizationId,
        ?string $principalId,
        string $feature,
        string $status,
        ?string $model,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
        ?int $latencyMs,
        ?string $errorCode,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = Uuid::v4();
        $this->organizationId = $organizationId;
        $this->principalId = $principalId;
        $this->feature = $feature;
        $this->status = $status;
        $this->model = $model;
        $this->promptTokens = max(0, $promptTokens);
        $this->completionTokens = max(0, $completionTokens);
        $this->totalTokens = max(0, $totalTokens);
        $this->latencyMs = $latencyMs;
        $this->errorCode = $errorCode;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }
}
