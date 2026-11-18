<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Service\TransactionBoundary;
use App\Util\Uuid;
use Doctrine\DBAL\Connection;

/**
 * The tenant a unit of work may touch, carried by PostgreSQL itself.
 *
 * Row-level security reads `current_setting('app.organization_id')` on every row
 * it checks, so the value has to be set on the *connection* and has to disappear
 * when the work is done. Both come from `set_config(..., true)`: the third
 * argument makes the value transaction-local, so it is discarded on commit or
 * rollback and can never leak to the next request that reuses the worker's
 * connection. A plain `SET` would leak; that is the whole reason this class
 * exists rather than a one-line `SET` somewhere.
 *
 * Every entry point that is not an HTTP request (console commands, fixtures) must
 * wrap its tenant work in {@see self::run()}. A query that runs without it sees no
 * rows at all rather than every row — the policies are written to fail closed.
 */
final class TenantContext
{
    /** The session variable the policies in Version20261121120000 read. */
    public const ORGANIZATION_SETTING = 'app.organization_id';

    /** The recipient of a notification; see the notifications policy. */
    public const PRINCIPAL_SETTING = 'app.principal_id';

    public function __construct(
        private readonly Connection $connection,
        private readonly TransactionBoundary $transactions,
    ) {
    }

    /**
     * Runs $work in a transaction scoped to one tenant.
     *
     * If a transaction is already open, the boundary joins it and the setting
     * still applies to it: `set_config` with `is_local = true` lasts until the
     * outer transaction ends, which is exactly the caller's unit of work.
     */
    public function run(string $organizationId, callable $work): mixed
    {
        return $this->transactions->run(function () use ($organizationId, $work): mixed {
            $this->activate($organizationId);

            return $work();
        });
    }

    /**
     * Sets the tenant for the current transaction only.
     *
     * Callers must already be inside a transaction — outside one, `is_local`
     * scopes the value to the statement and the next query would not see it, so
     * an accidental use would fail closed and confusingly. The listener opens the
     * request transaction itself; {@see self::run()} is the helper for everything
     * else.
     */
    public function activate(string $organizationId): void
    {
        if (!Uuid::isValid($organizationId)) {
            throw new \InvalidArgumentException('A tenant context requires an organization id.');
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A tenant context must be set inside a transaction; use TenantContext::run().');
        }

        // set_config takes the value as a bind parameter, which `SET LOCAL` cannot.
        $this->connection->executeStatement(
            'SELECT set_config(?, ?, true)',
            [self::ORGANIZATION_SETTING, $organizationId],
        );
    }

    /**
     * Sets the signed-in principal for the current transaction only.
     *
     * Notifications are read per recipient across every organization the person
     * belongs to, so that table's policy also accepts rows owned by this
     * principal. Everything else ignores the setting.
     */
    public function activatePrincipal(string $principalId): void
    {
        if (!Uuid::isValid($principalId)) {
            throw new \InvalidArgumentException('A principal context requires a principal id.');
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A principal context must be set inside a transaction.');
        }

        $this->connection->executeStatement(
            'SELECT set_config(?, ?, true)',
            [self::PRINCIPAL_SETTING, $principalId],
        );
    }
}
