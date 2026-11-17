<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationAiSettingsRepository;
use App\Util\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * One organization's AI opt-in and monthly token budget.
 *
 * `used_tokens` and `reserved_tokens` are counters, not derived values: the budget
 * is enforced by a single conditional UPDATE (see
 * {@see OrganizationAiSettingsRepository::reserveBudget()}), which needs one row the
 * database can lock. `period` is `YYYY-MM`; a new month resets both counters inside
 * that same statement, so a rollover cannot race a reservation.
 */
#[ORM\Entity(repositoryClass: OrganizationAiSettingsRepository::class)]
#[ORM\Table(name: 'organization_ai_settings')]
#[ORM\UniqueConstraint(name: 'uniq_organization_ai_settings_organization', fields: ['organization'])]
class OrganizationAiSettings
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\OneToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(type: 'boolean')]
    private bool $enabled = false;

    #[ORM\Column(name: 'monthly_token_budget', type: 'integer')]
    private int $monthlyTokenBudget = 0;

    #[ORM\Column(length: 7)]
    private string $period;

    #[ORM\Column(name: 'used_tokens', type: 'integer')]
    private int $usedTokens = 0;

    #[ORM\Column(name: 'reserved_tokens', type: 'integer')]
    private int $reservedTokens = 0;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Organization $organization,
        bool $enabled = false,
        int $monthlyTokenBudget = 0,
        ?\DateTimeImmutable $now = null,
    ) {
        if ($monthlyTokenBudget < 0) {
            throw new \InvalidArgumentException('The monthly token budget cannot be negative.');
        }

        $now ??= new \DateTimeImmutable();
        $this->id = Uuid::v4();
        $this->organization = $organization;
        $this->enabled = $enabled;
        $this->monthlyTokenBudget = $monthlyTokenBudget;
        $this->period = $now->format('Y-m');
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getMonthlyTokenBudget(): int
    {
        return $this->monthlyTokenBudget;
    }

    /** Budget minus what is spent and what in-flight requests have reserved. */
    public function remainingTokens(string $currentPeriod): int
    {
        if ($this->period !== $currentPeriod) {
            return $this->monthlyTokenBudget;
        }

        return max(0, $this->monthlyTokenBudget - $this->usedTokens - $this->reservedTokens);
    }

    public function update(bool $enabled, int $monthlyTokenBudget, ?\DateTimeImmutable $now = null): void
    {
        if ($monthlyTokenBudget < 0) {
            throw new \InvalidArgumentException('The monthly token budget cannot be negative.');
        }

        $this->enabled = $enabled;
        $this->monthlyTokenBudget = $monthlyTokenBudget;
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }
}
