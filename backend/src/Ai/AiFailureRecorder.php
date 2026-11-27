<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\AiRequestRecord;
use App\Tenancy\TenantContext;
use App\Util\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

/**
 * Writes the ledger row for a failed provider call on its own connection.
 *
 * The tenant listener wraps every authenticated request in a transaction and rolls it
 * back when an exception escapes. A guide failure is exactly that: the gateway records
 * the failure and rethrows, so the response can be `503`, and a row written through the
 * request's EntityManager is rolled back with everything else. The live run showed 31
 * rows for 32 attempts for this reason.
 *
 * The row is therefore committed independently, in its own short transaction, with the
 * tenant setting applied on that connection so row-level security still accepts it. The
 * budget reservation is deliberately *not* released here: it lives in the request
 * transaction, which either rolls back (releasing it) or commits (the summary path
 * catches the exception and the gateway releases it in the same unit of work).
 *
 * A failure to write the row is logged and swallowed: the caller must still receive the
 * provider's `503`, not a database error that hides it.
 */
final class AiFailureRecorder
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(
        string $organizationId,
        ?string $principalId,
        string $feature,
        int $latencyMs,
        string $errorCode,
    ): void {
        $recorder = null;

        try {
            $recorder = DriverManager::getConnection($this->connection->getParams());
            $recorder->beginTransaction();
            $recorder->executeStatement(
                'SELECT set_config(?, ?, true)',
                [TenantContext::ORGANIZATION_SETTING, $organizationId],
            );
            $recorder->insert('ai_requests', [
                'id' => Uuid::v4(),
                'organization_id' => $organizationId,
                'principal_id' => $principalId,
                'feature' => $feature,
                'status' => AiRequestRecord::STATUS_FAILED,
                'model' => null,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'latency_ms' => $latencyMs,
                'error_code' => substr($errorCode, 0, 64),
                'created_at' => new \DateTimeImmutable(),
            ], [
                'created_at' => Types::DATETIMETZ_IMMUTABLE,
            ]);
            $recorder->commit();
        } catch (\Throwable $exception) {
            if (null !== $recorder && $recorder->isTransactionActive()) {
                $recorder->rollBack();
            }

            // Metadata only: the organization and the reason code, never a prompt.
            $this->logger->error('Could not record a failed AI request', [
                'organizationId' => $organizationId,
                'feature' => $feature,
                'errorCode' => $errorCode,
                'exception' => $exception::class,
            ]);
        } finally {
            $recorder?->close();
        }
    }
}
