<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\FixtureTargetGuard;
use App\DataFixtures\SyntheticFixtureCatalog;
use App\DataFixtures\SyntheticFixtureLoader;
use Doctrine\Bundle\DoctrineBundle\ConnectionFactory;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Tools\DsnParser;
use App\Service\InvoiceCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SyntheticFixtureLoaderTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SyntheticFixtureLoader $loader;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);
        $loader = self::getContainer()->get(SyntheticFixtureLoader::class);
        self::assertInstanceOf(SyntheticFixtureLoader::class, $loader);
        $this->loader = $loader;
        $this->removeFixtureRows();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->removeFixtureRows();
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    public function testSeedingIsIdempotentAndKeepsRoleAndTenantInvariants(): void
    {
        $first = $this->loader->load();
        self::assertSame(4, $first['created']['principals']);
        self::assertSame(2, $first['created']['organizations']);
        self::assertSame(5, $first['created']['memberships']);
        self::assertSame(4, $first['created']['customers']);
        self::assertSame(6, $first['created']['invoices']);
        foreach ($first['skipped'] as $skipped) {
            self::assertSame(0, $skipped);
        }

        $second = $this->loader->load();
        foreach ($second['created'] as $type => $created) {
            self::assertSame(0, $created, sprintf('%s should not be recreated on a second run.', $type));
        }
        self::assertSame($first['created'], $second['skipped']);

        $connection = $this->entityManager->getConnection();
        self::assertSame(4, (int) $connection->fetchOne("SELECT COUNT(*) FROM principals WHERE issuer = ?", [SyntheticFixtureCatalog::ISSUER]));
        self::assertSame(2, (int) $connection->fetchOne("SELECT COUNT(*) FROM organizations WHERE subdomain LIKE 'fixture-%'"));

        $roles = $connection->fetchAllKeyValue(
            "SELECT p.subject, m.role FROM memberships m
             JOIN principals p ON p.id = m.principal_id
             JOIN organizations o ON o.id = m.organization_id
             ORDER BY p.subject, o.subdomain"
        );
        self::assertSame('Owner', $roles['fixture-owner-a']);
        self::assertSame('Member', $roles['fixture-member-a']);
        self::assertSame('Viewer', $roles['fixture-viewer-b']);
        self::assertSame(2, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM memberships m JOIN principals p ON p.id = m.principal_id WHERE p.subject = 'fixture-member-a'"
        ));

        self::assertSame(2, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM customers c JOIN organizations o ON o.id = c.organization_id WHERE o.subdomain = 'fixture-northwind'"
        ));
        self::assertSame(2, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM customers c JOIN organizations o ON o.id = c.organization_id WHERE o.subdomain = 'fixture-contoso'"
        ));

        $statuses = $connection->fetchFirstColumn(
            "SELECT DISTINCT i.status FROM invoices i JOIN organizations o ON o.id = i.organization_id WHERE o.subdomain LIKE 'fixture-%' ORDER BY i.status"
        );
        self::assertSame(['draft', 'overdue', 'paid', 'sent'], $statuses);
        self::assertSame(4, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM invoices i JOIN organizations o ON o.id = i.organization_id WHERE o.subdomain = 'fixture-northwind'"
        ));
        self::assertSame(2, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM invoices i JOIN organizations o ON o.id = i.organization_id WHERE o.subdomain = 'fixture-contoso'"
        ));
        self::assertGreaterThan(0, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM invoices WHERE status = 'paid' AND paid_at IS NOT NULL"
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM principals WHERE issuer = ? AND (email IS NULL OR email NOT LIKE '%@example.test')",
            [SyntheticFixtureCatalog::ISSUER]
        ));
    }

    public function testAcceptsTheEffectiveCiTestDatabaseDespiteAnUnsafeLookingUrl(): void
    {
        // CI sets DATABASE_URL to .../keelson and the test environment appends
        // Doctrine's `dbname_suffix` (_test). The guard must judge the database
        // PostgreSQL actually receives (keelson_test), not the raw URL name
        // (keelson) that used to make seeding fail in GitHub Actions.
        // DoctrineBundle, not the URL, is what applies the suffix, so the
        // reproduction goes through the same factory the application uses.
        $connectionParams = (new ConnectionFactory([], new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql'])))
            ->createConnection([
                'url' => 'postgresql://keelson:keelson@127.0.0.1:5432/keelson?serverVersion=16&charset=utf8',
                'dbname_suffix' => '_test',
            ], new Configuration())
            ->getParams();
        self::assertSame('keelson_test', $connectionParams['dbname']);

        self::assertSame(
            'keelson_test@127.0.0.1',
            (new FixtureTargetGuard())->describe($connectionParams, 'test'),
        );
    }

    public function testGuardsAgainstTheRunningConnectionsEffectiveTarget(): void
    {
        $target = $this->loader->assertSafeTarget();
        $database = $this->entityManager->getConnection()->getDatabase();

        self::assertSame($database.'@127.0.0.1', $target, 'The guard must describe the database the loader will write to.');
        self::assertMatchesRegularExpression('/(?:^|_)(test|dev|fixture)(?:_|$)/', $database);
    }

    public function testRefusesUnsafeOrAmbiguousTargets(): void
    {
        $guard = new FixtureTargetGuard();
        $safe = ['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'dbname' => 'keelson_test'];

        try {
            $guard->describe($safe, 'prod');
            self::fail('prod must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('prod', $exception->getMessage());
        }

        $unsafe = [
            'remote host' => ['driver' => 'pdo_pgsql', 'host' => 'db.example.com', 'dbname' => 'keelson_test'],
            'production database name' => ['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'dbname' => 'keelson'],
            'ambiguous name' => ['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'dbname' => 'keelson_latest'],
            'empty name' => ['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'dbname' => ''],
            'mysql driver' => ['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'dbname' => 'keelson_test'],
            'missing driver' => ['host' => '127.0.0.1', 'dbname' => 'keelson_test'],
            'empty host' => ['driver' => 'pdo_pgsql', 'host' => '', 'dbname' => 'keelson_test'],
        ];
        foreach ($unsafe as $label => $params) {
            try {
                $guard->describe($params, 'dev');
                self::fail(sprintf('Unsafe target accepted: %s', $label));
            } catch (\RuntimeException $exception) {
                self::assertStringNotContainsString('keelson:keelson', $exception->getMessage(), 'A refusal must not echo credentials.');
                self::assertStringNotContainsString('password', strtolower($exception->getMessage()));
            }
        }

        foreach (['127.0.0.1', 'localhost', '::1'] as $host) {
            self::assertSame(
                'keelson_test_local@'.$host,
                $guard->describe(['driver' => 'pdo_pgsql', 'host' => $host, 'dbname' => 'keelson_test_local'], 'test'),
            );
        }
        self::assertSame(
            'fixture_data@localhost',
            $guard->describe(['driver' => 'pdo_pgsql', 'host' => 'localhost', 'dbname' => 'fixture_data'], 'dev'),
        );
    }

    private function removeFixtureRows(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("DELETE FROM invoices WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE 'fixture-%')");
        $connection->executeStatement("DELETE FROM customers WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE 'fixture-%')");
        $connection->executeStatement("DELETE FROM memberships WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE 'fixture-%')");
        $connection->executeStatement("DELETE FROM organizations WHERE subdomain LIKE 'fixture-%'");
        $connection->executeStatement('DELETE FROM principals WHERE issuer = ?', [SyntheticFixtureCatalog::ISSUER]);
    }
}
