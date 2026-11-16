<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;

/**
 * The application's DBAL connection, counting statements while the test asks.
 *
 * Registered as Doctrine's `wrapper_class` in the test environment only
 * (config/packages/test/doctrine.yaml). Counting here — at the point every query
 * and prepared statement passes through — is what lets a test state the cost of
 * a request in queries, and therefore prove that a listing does not grow a query
 * per row.
 */
class CountingConnection extends Connection
{
    private static int $count = 0;

    private static bool $enabled = false;

    public static function reset(): void
    {
        self::$count = 0;
        self::$enabled = true;
    }

    public static function stop(): void
    {
        self::$enabled = false;
    }

    public static function count(): int
    {
        return self::$count;
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        if (self::$enabled) {
            ++self::$count;
        }

        return parent::executeQuery($sql, $params, $types, $qcp);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        if (self::$enabled) {
            ++self::$count;
        }

        return parent::executeStatement($sql, $params, $types);
    }

    public function prepare(string $sql): \Doctrine\DBAL\Statement
    {
        if (self::$enabled) {
            ++self::$count;
        }

        return parent::prepare($sql);
    }
}
