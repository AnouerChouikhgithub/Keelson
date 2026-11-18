<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiRequestRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads of the AI spend ledger, aggregated per organization and per day.
 *
 * The ledger is append-only and content-free (`ai_requests` holds no prompt and no
 * answer), so an aggregate over it can be shown to an operator without exposing tenant
 * text. Every method takes an organization id and every call is expected inside that
 * tenant's row-level-security context: `ai_requests` is a tenant table, so a query
 * without `app.organization_id` sees no rows at all.
 *
 * The day is the UTC calendar day of `created_at`, which is what the column stores. An
 * operator reading a local table should know that a "day" is UTC, not their timezone.
 */
/**
 * @extends ServiceEntityRepository<AiRequestRecord>
 */
final class AiRequestRecordRepository extends ServiceEntityRepository
{
    /** One row per UTC day: volume, failures and the three token counters. */
    private const DAILY_SQL = "(created_at AT TIME ZONE 'UTC')::date AS day,"
        .' COUNT(*) AS requests,'
        ." COUNT(*) FILTER (WHERE status = 'failed') AS failed,"
        .' COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens,'
        .' COALESCE(SUM(completion_tokens), 0) AS completion_tokens,'
        .' COALESCE(SUM(total_tokens), 0) AS total_tokens'
        .' FROM ai_requests'
        .' WHERE organization_id = :organizationId'
        .' AND created_at >= :from'
        .' AND created_at < :to'
        .' GROUP BY 1'
        .' ORDER BY 1';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiRequestRecord::class);
    }

    /**
     * One row per UTC day that saw at least one AI request.
     *
     * @return list<array{day: string, requests: int, failed: int, promptTokens: int, completionTokens: int, totalTokens: int}>
     */
    public function dailyUsage(string $organizationId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT '.self::DAILY_SQL,
            [
                'organizationId' => $organizationId,
                'from' => $from,
                'to' => $to,
            ],
            [
                'from' => Types::DATETIMETZ_IMMUTABLE,
                'to' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        return array_map(
            static fn (array $row): array => [
                'day' => (string) $row['day'],
                'requests' => (int) $row['requests'],
                'failed' => (int) $row['failed'],
                'promptTokens' => (int) $row['prompt_tokens'],
                'completionTokens' => (int) $row['completion_tokens'],
                'totalTokens' => (int) $row['total_tokens'],
            ],
            $rows,
        );
    }
}
