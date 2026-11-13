<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\SyntheticFixtureCatalog;
use App\DataFixtures\SyntheticFixtureLoader;
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

    public function testRefusesUnsafeOrAmbiguousTargets(): void
    {
        $catalog = new SyntheticFixtureCatalog();
        $calculator = new InvoiceCalculator();

        try {
            (new SyntheticFixtureLoader($this->entityManager, $calculator, $catalog, 'prod', 'postgresql://app:app@127.0.0.1:5432/keelson_test'))->assertSafeTarget();
            self::fail('prod must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('prod', $exception->getMessage());
        }

        foreach ([
            'postgresql://app:app@db.example.com:5432/keelson_test',
            'postgresql://app:app@127.0.0.1:5432/keelson',
            'mysql://app:app@127.0.0.1:3306/keelson_test',
        ] as $url) {
            try {
                (new SyntheticFixtureLoader($this->entityManager, $calculator, $catalog, 'dev', $url))->assertSafeTarget();
                self::fail(sprintf('Unsafe target accepted: %s', parse_url($url, PHP_URL_HOST).'/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/')));
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame(
            'keelson_test_local@127.0.0.1',
            (new SyntheticFixtureLoader($this->entityManager, $calculator, $catalog, 'test', 'postgresql://app:app@127.0.0.1:5432/keelson_test_local'))->assertSafeTarget(),
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
