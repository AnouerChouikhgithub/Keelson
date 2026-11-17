<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrganizationAiSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OrganizationAiSettings> */
final class OrganizationAiSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationAiSettings::class);
    }

    public function findForOrganization(string $organizationId): ?OrganizationAiSettings
    {
        return $this->createQueryBuilder('settings')
            ->andWhere('IDENTITY(settings.organization) = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Reserves `$estimate` tokens against the month's budget, or returns `false`.
     *
     * One statement does the whole decision, which is what makes two concurrent
     * requests safe: the predicate is evaluated against the row PostgreSQL has
     * locked, so the second request sees the first one's reservation and cannot
     * also fit when the budget is nearly gone. A read-then-write pair would let
     * both observe the same free budget.
     *
     * The period rollover happens here too: a row from a previous month is reset
     * before the reservation, in the same atomic update.
     */
    public function reserveBudget(string $organizationId, string $period, int $estimate): bool
    {
        if ($estimate < 1) {
            return false;
        }

        $inserted = $this->getEntityManager()->getConnection()->fetchOne(
            'UPDATE organization_ai_settings
             SET period = :period,
                 used_tokens = CASE WHEN period = :period THEN used_tokens ELSE 0 END,
                 reserved_tokens = (CASE WHEN period = :period THEN reserved_tokens ELSE 0 END) + :estimate,
                 updated_at = :now
             WHERE organization_id = :organizationId
               AND enabled = TRUE
               AND (CASE WHEN period = :period THEN used_tokens ELSE 0 END)
                 + (CASE WHEN period = :period THEN reserved_tokens ELSE 0 END)
                 + :estimate <= monthly_token_budget
             RETURNING id',
            [
                'period' => $period,
                'estimate' => $estimate,
                'now' => new \DateTimeImmutable(),
                'organizationId' => $organizationId,
            ],
            [
                'now' => \Doctrine\DBAL\Types\Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        return false !== $inserted && null !== $inserted;
    }

    /**
     * Replaces a reservation with the real usage. A month that rolled over between
     * reservation and settlement leaves the new month's counters untouched.
     */
    public function settleBudget(string $organizationId, string $period, int $estimate, int $actual): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE organization_ai_settings
             SET used_tokens = used_tokens + :actual,
                 reserved_tokens = GREATEST(0, reserved_tokens - :estimate),
                 updated_at = :now
             WHERE organization_id = :organizationId AND period = :period',
            [
                'actual' => max(0, $actual),
                'estimate' => max(0, $estimate),
                'now' => new \DateTimeImmutable(),
                'organizationId' => $organizationId,
                'period' => $period,
            ],
            [
                'now' => \Doctrine\DBAL\Types\Types::DATETIMETZ_IMMUTABLE,
            ],
        );
    }

    /** Drops a reservation whose provider call never produced a billable answer. */
    public function releaseBudget(string $organizationId, string $period, int $estimate): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE organization_ai_settings
             SET reserved_tokens = GREATEST(0, reserved_tokens - :estimate),
                 updated_at = :now
             WHERE organization_id = :organizationId AND period = :period',
            [
                'estimate' => max(0, $estimate),
                'now' => new \DateTimeImmutable(),
                'organizationId' => $organizationId,
                'period' => $period,
            ],
            [
                'now' => \Doctrine\DBAL\Types\Types::DATETIMETZ_IMMUTABLE,
            ],
        );
    }
}
