<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\FixtureTargetGuard;
use App\DataFixtures\SyntheticFixtureCatalog;
use App\DataFixtures\SyntheticFixtureLoader;
use App\Entity\Principal;
use App\Tests\Support\RemovesSyntheticFixtures;
use Doctrine\Bundle\DoctrineBundle\ConnectionFactory;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The synthetic fixture loader: idempotency, role and tenant invariants, the
 * seeded audit trail, usable sign-in credentials, and every safety refusal.
 */
final class SyntheticFixtureLoaderTest extends KernelTestCase
{
    use RemovesSyntheticFixtures;

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
        self::assertSame(5, $first['created']['principals']);
        self::assertSame(2, $first['created']['organizations']);
        self::assertSame(5, $first['created']['memberships']);
        self::assertSame(12, $first['created']['customers']);
        self::assertSame(30, $first['created']['invoices']);
        foreach ($first['skipped'] as $skipped) {
            self::assertSame(0, $skipped);
        }

        $second = $this->loader->load();
        foreach ($second['created'] as $type => $created) {
            self::assertSame(0, $created, sprintf('%s should not be recreated on a second run.', $type));
        }
        self::assertSame($first['created'], $second['skipped']);

        $connection = $this->entityManager->getConnection();
        self::assertSame(5, (int) $connection->fetchOne('SELECT COUNT(*) FROM principals WHERE email IN ('.$this->fixtureEmails().')'));
        self::assertSame(2, (int) $connection->fetchOne("SELECT COUNT(*) FROM organizations WHERE subdomain LIKE 'fixture-%'"));

        $roles = $connection->fetchAllKeyValue(
            "SELECT p.email, m.role FROM memberships m
             JOIN principals p ON p.id = m.principal_id
             JOIN organizations o ON o.id = m.organization_id
             WHERE o.subdomain LIKE 'fixture-%'
             ORDER BY p.email, o.subdomain"
        );
        self::assertSame([
            'admin@example.test' => 'Admin',
            'member@example.test' => 'Member',
            'owner@example.test' => 'Owner',
            'viewer@example.test' => 'Viewer',
        ], $roles);
        self::assertSame(2, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM memberships m JOIN principals p ON p.id = m.principal_id WHERE p.email = 'member@example.test'"
        ));
        // The unverified account exists but belongs to no organization.
        self::assertSame(0, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM memberships m JOIN principals p ON p.id = m.principal_id WHERE p.email = 'unverified@example.test'"
        ));

        self::assertSame(7, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM customers c JOIN organizations o ON o.id = c.organization_id WHERE o.subdomain = 'fixture-northwind'"
        ));
        self::assertSame(5, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM customers c JOIN organizations o ON o.id = c.organization_id WHERE o.subdomain = 'fixture-contoso'"
        ));

        $statuses = $connection->fetchFirstColumn(
            "SELECT DISTINCT i.status FROM invoices i JOIN organizations o ON o.id = i.organization_id WHERE o.subdomain LIKE 'fixture-%' ORDER BY i.status"
        );
        self::assertSame(['draft', 'overdue', 'paid', 'sent'], $statuses);
        self::assertSame(18, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM invoices i JOIN organizations o ON o.id = i.organization_id WHERE o.subdomain = 'fixture-northwind'"
        ));
        self::assertSame(12, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM invoices i JOIN organizations o ON o.id = i.organization_id WHERE o.subdomain = 'fixture-contoso'"
        ));
        self::assertGreaterThan(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM invoices WHERE status = 'paid' AND paid_at IS NOT NULL"));
        // Overdue and upcoming states must exist whatever day the database is read:
        // overdue invoices are past their due date, the sent ones are still ahead.
        self::assertGreaterThan(0, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM invoices WHERE status = 'overdue' AND due_date < CURRENT_DATE"
        ));
        self::assertGreaterThan(0, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM invoices WHERE status = 'sent' AND due_date >= CURRENT_DATE"
        ));
        // Numbers follow the API's per-organization yearly format (other suites
        // create their own invoices, so the check stays inside the fixtures).
        self::assertSame(0, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM invoices i JOIN organizations o ON o.id = i.organization_id
             WHERE o.subdomain LIKE 'fixture-%' AND i.number !~ '^[0-9]{4}-[0-9]{6}$'"
        ));
    }

    public function testSeededAccountsAreSignableAndTheTrailIsAttributable(): void
    {
        $stats = $this->loader->load();
        $connection = $this->entityManager->getConnection();

        $verified = $connection->fetchAllKeyValue(
            'SELECT email, email_verified_at FROM principals WHERE email IN ('.$this->fixtureEmails().')'
        );
        self::assertCount(5, $verified);
        self::assertNull($verified['unverified@example.test']);
        foreach (['owner@example.test', 'admin@example.test', 'member@example.test', 'viewer@example.test'] as $email) {
            self::assertNotNull($verified[$email], sprintf('%s must be created with a confirmed address.', $email));
        }

        // Every account has a hash of the documented password, including the
        // unverified one: otherwise the 403 path could never be reached.
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $owner = $this->entityManager->getRepository(Principal::class)->findOneBy(['email' => 'owner@example.test']);
        self::assertInstanceOf(Principal::class, $owner);
        foreach ([$stats['password'], SyntheticFixtureCatalog::FALLBACK_PASSWORD] as $password) {
            self::assertTrue($hasher->isPasswordValid($owner, $password));
        }
        self::assertFalse($hasher->isPasswordValid($owner, 'Keelson-dev-2026'));

        // The result carries the plaintext demo password for the account table and
        // never a hash.
        self::assertStringNotContainsString('$2y$', json_encode($stats, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('$argon', json_encode($stats, JSON_THROW_ON_ERROR));

        // 30 invoices were created, 26 of them sent (8 sent + 5 overdue + 13 paid)
        // and 13 paid, each event recorded through the service the API uses.
        self::assertSame(30, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM invoice_audit_events WHERE action = 'invoice.created'"
        ));
        self::assertSame(26, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM invoice_audit_events WHERE action = 'invoice.sent'"
        ));
        self::assertSame(13, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM invoice_audit_events WHERE action = 'invoice.paid'"
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM invoice_audit_events WHERE action <> :created AND invoice_id IS NULL',
            ['created' => 'invoice.created'],
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

    public function testAcceptsKeelsonDevAndRefusesTheProductionName(): void
    {
        $guard = new FixtureTargetGuard();
        $development = ['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'dbname' => 'keelson_dev'];

        self::assertSame('keelson_dev@127.0.0.1', $guard->describe($development, 'dev'));

        try {
            $guard->describe(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'dbname' => 'keelson'], 'dev');
            self::fail('The production database name must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('keelson', $exception->getMessage());
            self::assertStringContainsString('test/dev/fixture', $exception->getMessage());
        }
    }

    public function testRefusesUnsafeOrAmbiguousTargets(): void
    {
        $guard = new FixtureTargetGuard();
        $safe = ['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'dbname' => 'keelson_test'];

        foreach (['prod', 'production', 'prod-eu'] as $environment) {
            try {
                $guard->describe($safe, $environment);
                self::fail(sprintf('Environment %s must be refused.', $environment));
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('prod', $exception->getMessage());
            }
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

    public function testTheDatabaseTargetCommandPrintsOnlyDatabaseAndHost(): void
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:db:target'));
        self::assertSame(0, $tester->execute([]));

        $expected = $this->entityManager->getConnection()->getDatabase().'@127.0.0.1';
        self::assertSame($expected, trim($tester->getDisplay()));
        self::assertStringNotContainsString('keelson:keelson', $tester->getDisplay());
    }

    public function testAPasswordShorterThanRegistrationAllowsIsRejectedBeforeAnythingIsWritten(): void
    {
        $loader = new SyntheticFixtureLoader(
            $this->entityManager,
            self::getContainer()->get(\App\Service\InvoiceCalculator::class),
            new SyntheticFixtureCatalog(),
            new FixtureTargetGuard(),
            self::getContainer()->get(UserPasswordHasherInterface::class),
            self::getContainer()->get(\App\Service\InvoiceAuditLogger::class),
            'test',
            'short',
        );

        try {
            $loader->load();
            self::fail('A password below the registration minimum must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('at least 10 characters', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM principals WHERE email IN ('.$this->fixtureEmails().')'
        ));
    }

    private function removeFixtureRows(): void
    {
        $this->removeSyntheticFixtureRows($this->entityManager);
    }

    private function fixtureEmails(): string
    {
        return $this->quotedFixtureEmails();
    }
}
