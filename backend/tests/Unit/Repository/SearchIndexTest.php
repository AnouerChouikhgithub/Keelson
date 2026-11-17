<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The `q` search is only fast because of these indexes, and Doctrine cannot
 * express an expression index in entity mapping, so nothing else would notice
 * if one disappeared.
 */
final class SearchIndexTest extends KernelTestCase
{
    public function testTheTrigramSearchIndexesAreInstalled(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine')->getConnection();
        self::assertInstanceOf(Connection::class, $connection);

        $indexes = $connection->fetchFirstColumn(
            'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND indexname IN (?, ?, ?)',
            ['idx_customers_name_trgm', 'idx_customers_email_trgm', 'idx_invoices_number_trgm'],
        );

        self::assertEqualsCanonicalizing(
            ['idx_customers_name_trgm', 'idx_customers_email_trgm', 'idx_invoices_number_trgm'],
            $indexes,
            'Run doctrine:migrations:migrate: the search indexes are missing.',
        );

        self::assertNotFalse(
            $connection->fetchOne("SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'"),
            'The pg_trgm extension is not installed in this database.',
        );
    }
}
