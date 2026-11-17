<?php

declare(strict_types=1);

namespace App\Session;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NullSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * Builds the database-backed session handler on the application connection.
 *
 * The handler needs a PDO (or DSN); reusing Doctrine's native connection means
 * the session table lives in the same database as the rest of the data and no
 * second credential has to be configured. The `sessions` table itself is created
 * by a migration, so it is versioned like every other table.
 *
 * Tests keep Symfony's mock storage: the handler would be unused there, and the
 * mock lets a test restart the kernel without leaking a row per request.
 */
final class PdoSessionHandlerFactory
{
    public function __construct(private readonly Connection $connection, private readonly string $environment)
    {
    }

    public function create(): \SessionHandlerInterface
    {
        if ('test' === $this->environment) {
            return new NullSessionHandler();
        }

        $native = $this->connection->getNativeConnection();
        if (!$native instanceof \PDO) {
            throw new \RuntimeException('The application connection must use PDO to store sessions.');
        }

        return new PdoSessionHandler($native, self::options());
    }

    /**
     * Handler options, shared with the test that pins the lock strategy.
     *
     * `lock_mode` is deliberate. Symfony defaults to LOCK_TRANSACTIONAL, which
     * starts a PDO transaction on the connection during `read()` and commits it
     * after the response. Because this handler borrows Doctrine's own PDO, DBAL
     * never sees that transaction: the first `beginTransaction()` in a request
     * throws "There is already an active transaction", and any write that did run
     * would silently be part of the session's transaction. The advisory lock
     * (`pg_advisory_lock`) gives the same one-request-per-session guarantee
     * without touching transactions, which is what makes tenant writes work.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'db_table' => 'sessions',
            'db_id_col' => 'sess_id',
            'db_data_col' => 'sess_data',
            'db_lifetime_col' => 'sess_lifetime',
            'db_time_col' => 'sess_time',
            'lock_mode' => PdoSessionHandler::LOCK_ADVISORY,
        ];
    }
}
