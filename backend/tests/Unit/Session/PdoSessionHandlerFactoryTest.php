<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Session\PdoSessionHandlerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * The session handler borrows Doctrine's PDO connection, so its locking strategy
 * is not an implementation detail: with Symfony's default LOCK_TRANSACTIONAL the
 * handler opens a PDO transaction DBAL cannot see and every write request fails
 * with "There is already an active transaction".
 *
 * The end-to-end journey (e2e/tests/a-signup-verify.spec.ts) covers the real
 * request; this test pins the option that makes it work.
 */
final class PdoSessionHandlerFactoryTest extends TestCase
{
    public function testTheHandlerUsesAdvisoryLockingOnTheSharedConnection(): void
    {
        $options = PdoSessionHandlerFactory::options();

        self::assertSame(PdoSessionHandler::LOCK_ADVISORY, $options['lock_mode']);
        self::assertSame('sessions', $options['db_table']);
        self::assertSame('sess_id', $options['db_id_col']);
        self::assertSame('sess_data', $options['db_data_col']);
    }
}
