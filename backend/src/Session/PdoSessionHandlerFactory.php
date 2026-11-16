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

        return new PdoSessionHandler($native, [
            'db_table' => 'sessions',
            'db_id_col' => 'sess_id',
            'db_data_col' => 'sess_data',
            'db_lifetime_col' => 'sess_lifetime',
            'db_time_col' => 'sess_time',
        ]);
    }
}
