<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AiRequestRecord;
use App\Entity\Customer;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\OrganizationAiSettings;
use App\Entity\Principal;
use App\Tenancy\TenantContext;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the database enforces tenancy on its own.
 *
 * Everything here runs on a second connection as a role that is neither the table
 * owner nor BYPASSRLS, because that is the only way to observe a policy: a
 * superuser is exempt from every one of them. The test drives raw SQL on purpose —
 * a repository call would prove that the repository filters, which is exactly what
 * this test must not rely on.
 *
 * The probe role is created if missing and left behind, with row privileges, in the
 * disposable test database only. It cannot be dropped without revoking first, and
 * re-creating it per run would cost more than it is worth in a throwaway database.
 */
final class RowLevelSecurityTest extends KernelTestCase
{
    private const PROBE_ROLE = 'keelson_rls_probe';
    private const PROBE_PASSWORD = 'keelson_rls_probe';
    private const TENANT_TABLES = ['customers', 'invoices', 'invitations', 'notifications', 'organization_ai_settings', 'ai_requests'];

    private EntityManagerInterface $entityManager;
    private \Doctrine\DBAL\Connection $probe;
    private TenantContext $tenants;
    private string $suffix;
    private Organization $organizationA;
    private Organization $organizationB;
    private Principal $principal;
    private Customer $customerA;
    private Customer $customerB;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->tenants = self::getContainer()->get(TenantContext::class);
        $owner = $this->entityManager->getConnection();

        $this->suffix = bin2hex(random_bytes(6));
        $this->principal = new Principal('rls-'.$this->suffix.'@example.test');
        $this->organizationA = new Organization('RLS A '.$this->suffix, 'rls-a-'.$this->suffix);
        $this->organizationB = new Organization('RLS B '.$this->suffix, 'rls-b-'.$this->suffix);
        foreach ([$this->principal, $this->organizationA, $this->organizationB] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organizationA, $this->principal, 'Owner'));
        $this->entityManager->flush();

        $this->customerA = $this->tenants->run($this->organizationA->getId(), function (): Customer {
            $customer = new Customer($this->organizationA, 'Customer A '.$this->suffix);
            $this->entityManager->persist($customer);
            $this->entityManager->flush();

            return $customer;
        });
        $this->customerB = $this->tenants->run($this->organizationB->getId(), function (): Customer {
            $customer = new Customer($this->organizationB, 'Customer B '.$this->suffix);
            $this->entityManager->persist($customer);
            $this->entityManager->flush();

            return $customer;
        });

        $this->probe = $this->openProbeConnection($owner, $this->principal);
    }

    protected function tearDown(): void
    {
        if (isset($this->probe)) {
            $this->probe->close();
        }
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            // The seeding connection is a superuser here, which bypasses the
            // policies, so the cleanup needs no tenant context.
            $connection = $this->entityManager->getConnection();
            foreach (self::TENANT_TABLES as $table) {
                $connection->executeStatement(
                    'DELETE FROM '.$table.' WHERE organization_id IN (?, ?)',
                    [$this->organizationA->getId(), $this->organizationB->getId()],
                );
            }
            $connection->executeStatement('DELETE FROM memberships WHERE organization_id IN (?, ?)', [
                $this->organizationA->getId(),
                $this->organizationB->getId(),
            ]);
            $connection->executeStatement('DELETE FROM organizations WHERE id IN (?, ?)', [
                $this->organizationA->getId(),
                $this->organizationB->getId(),
            ]);
            $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$this->principal->getId()]);
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testThePoliciesAreEnabledAndForcedOnEveryTenantTable(): void
    {
        $placeholders = implode(', ', array_fill(0, count(self::TENANT_TABLES), '?'));
        $sql = "SELECT c.relname, c.relrowsecurity, c.relforcerowsecurity
                  FROM pg_class c
                  JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = 'public' AND c.relname IN ($placeholders)";
        $rows = $this->entityManager->getConnection()->fetchAllAssociative($sql, self::TENANT_TABLES);

        self::assertCount(count(self::TENANT_TABLES), $rows, 'every tenant table exists and is covered');
        foreach ($rows as $row) {
            self::assertTrue((bool) $row['relrowsecurity'], $row['relname'].' enables row-level security');
            self::assertTrue((bool) $row['relforcerowsecurity'], $row['relname'].' forces it, so the owner is not exempt');
        }
    }

    public function testAnUnfilteredReadSeesOnlyTheScopedTenant(): void
    {
        // No WHERE clause at all: this is the "forgot the tenant filter" query.
        $names = $this->scoped(
            $this->organizationA->getId(),
            fn (): array => $this->probe->fetchFirstColumn('SELECT name FROM customers'),
        );

        self::assertContains($this->customerA->getName(), $names);
        self::assertNotContains($this->customerB->getName(), $names, 'a foreign tenant row is invisible even without a filter');
        self::assertNotContains($this->customerB->getId(), $this->scoped(
            $this->organizationA->getId(),
            fn (): array => $this->probe->fetchFirstColumn('SELECT id FROM customers'),
        ));
    }

    public function testAMissingTenantVariableReturnsNoRowsRatherThanEveryRow(): void
    {
        $this->probe->beginTransaction();
        try {
            self::assertSame('', (string) $this->probe->fetchOne("SELECT current_setting('app.organization_id', true)"));
            self::assertSame(0, (int) $this->probe->fetchOne('SELECT COUNT(*) FROM customers'));
            self::assertSame([], $this->probe->fetchFirstColumn('SELECT name FROM customers'));
        } finally {
            $this->probe->rollBack();
        }
    }

    public function testForeignRowsCannotBeWrittenEvenWithTenantScopedSql(): void
    {
        $this->probe->beginTransaction();
        try {
            $this->probe->executeStatement('SELECT set_config(?, ?, true)', [TenantContext::ORGANIZATION_SETTING, $this->organizationA->getId()]);

            $this->expectException(DbalException::class);
            $this->probe->executeStatement(
                'INSERT INTO customers (id, organization_id, name, created_at, updated_at) VALUES (?, ?, ?, now(), now())',
                [$this->uuid(), $this->organizationB->getId(), 'Smuggled '.$this->suffix],
            );
        } finally {
            if ($this->probe->isTransactionActive()) {
                $this->probe->rollBack();
            }
        }

        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM customers WHERE name = ?',
            ['Smuggled '.$this->suffix],
        ));
    }

    public function testTheProbeRoleIsNotExemptFromThePolicies(): void
    {
        $role = $this->probe->fetchAssociative(
            'SELECT r.rolsuper::int AS is_superuser, r.rolbypassrls::int AS bypass_rls
               FROM pg_catalog.pg_roles r WHERE r.rolname = current_user',
        );

        self::assertIsArray($role);
        self::assertSame(0, (int) $role['is_superuser'], 'the probe role must not be a superuser');
        self::assertSame(0, (int) $role['bypass_rls'], 'the probe role must not have BYPASSRLS');
    }

    /**
     * The AI tables carry tenant rows, so they carry the same policy.
     *
     * They were created without one, which left them protected by the application layer
     * alone; Version20261201130000 closes that. This asserts the behaviour the policy is
     * for: unset scope reads nothing, the owning tenant sees its own row and not the
     * other's, and a foreign row cannot be written even with a tenant-scoped connection.
     */
    public function testTheAiTablesAreScopedByTheSamePolicy(): void
    {
        $settings = new OrganizationAiSettings($this->organizationA, true, 1000);
        $this->entityManager->persist($settings);
        $this->entityManager->persist(new AiRequestRecord(
            $this->organizationA->getId(),
            $this->principal->getId(),
            'guide',
            AiRequestRecord::STATUS_SUCCEEDED,
            'fake-model',
            10,
            5,
            15,
            120,
            null,
        ));
        $this->entityManager->flush();

        $this->probe->beginTransaction();
        try {
            self::assertSame(0, (int) $this->probe->fetchOne('SELECT COUNT(*) FROM ai_requests'), 'No scope, no rows.');
            self::assertSame(0, (int) $this->probe->fetchOne('SELECT COUNT(*) FROM organization_ai_settings'));
        } finally {
            $this->probe->rollBack();
        }

        self::assertSame(1, (int) $this->scoped(
            $this->organizationA->getId(),
            fn (): int|string => (int) $this->probe->fetchOne('SELECT COUNT(*) FROM ai_requests'),
        ));
        self::assertSame(0, (int) $this->scoped(
            $this->organizationB->getId(),
            fn (): int|string => (int) $this->probe->fetchOne('SELECT COUNT(*) FROM ai_requests'),
        ));

        $this->probe->beginTransaction();
        try {
            $this->probe->executeStatement('SELECT set_config(?, ?, true)', [TenantContext::ORGANIZATION_SETTING, $this->organizationA->getId()]);
            $this->expectException(DbalException::class);
            $this->probe->executeStatement(
                'INSERT INTO ai_requests (id, organization_id, principal_id, feature, status, model, prompt_tokens, completion_tokens, total_tokens, latency_ms, error_code, created_at)'
                ." VALUES (?, ?, NULL, 'guide', 'succeeded', 'fake-model', 1, 1, 2, 5, NULL, now())",
                [$this->uuid(), $this->organizationB->getId()],
            );
        } finally {
            if ($this->probe->isTransactionActive()) {
                $this->probe->rollBack();
            }
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function scoped(string $organizationId, callable $work): mixed
    {
        $this->probe->beginTransaction();
        try {
            $this->probe->executeStatement('SELECT set_config(?, ?, true)', [TenantContext::ORGANIZATION_SETTING, $organizationId]);
            $result = $work();
        } finally {
            $this->probe->rollBack();
        }

        return $result;
    }

    private function openProbeConnection(\Doctrine\DBAL\Connection $owner, Principal $principal): \Doctrine\DBAL\Connection
    {
        try {
            $exists = (bool) $owner->fetchOne('SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = ?', [self::PROBE_ROLE]);
            if (!$exists) {
                $owner->executeStatement(sprintf(
                    'CREATE ROLE %s LOGIN PASSWORD %s NOSUPERUSER NOBYPASSRLS',
                    self::PROBE_ROLE,
                    $owner->quote(self::PROBE_PASSWORD),
                ));
            }
            $owner->executeStatement(sprintf('GRANT USAGE ON SCHEMA public TO %s', self::PROBE_ROLE));
            $owner->executeStatement(sprintf(
                'GRANT SELECT, INSERT, UPDATE, DELETE ON %s TO %s',
                implode(', ', self::TENANT_TABLES),
                self::PROBE_ROLE,
            ));
        } catch (DbalException $exception) {
            self::markTestSkipped('Creating a non-BYPASSRLS role needs a superuser test connection: '.$exception->getMessage());
        }

        $params = $owner->getParams();
        unset($params['url'], $params['dbname_suffix']);
        $params['user'] = self::PROBE_ROLE;
        $params['password'] = self::PROBE_PASSWORD;

        return DriverManager::getConnection($params);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
