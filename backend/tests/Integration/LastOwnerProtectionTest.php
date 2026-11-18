<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * An organization must never lose its last active Owner.
 *
 * A count followed by an update is not enough: two Owners demoting each other at
 * the same time would each count the other and both succeed. The handler takes a
 * per-organization advisory lock and re-reads the roster inside the transaction,
 * so the second request sees the first one's committed result.
 *
 * The tests prove three things: the invariant holds for demotion and removal,
 * the handler actually takes the lock (a second connection holding it makes the
 * request wait), and a membership change that already committed is taken into
 * account before the next decision.
 */
final class LastOwnerProtectionTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Organization $organization;
    private Principal $owner;
    private Membership $ownerMembership;
    private Principal $otherOwner;
    private Membership $otherOwnerMembership;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);

        $this->suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Owners '.$this->suffix, 'owners-'.$this->suffix);
        $this->owner = new Principal('owner-a-'.$this->suffix.'@example.test', 'Owner A');
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->owner);
        $this->entityManager->flush();
        $this->ownerMembership = new Membership($this->organization, $this->owner, 'Owner');
        $this->entityManager->persist($this->ownerMembership);
        $this->entityManager->flush();

        $this->client->loginUser($this->owner);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $organizationId = $this->id($this->organization);
                $connection->executeStatement('DELETE FROM notifications WHERE organization_id = ?', [$organizationId]);
                $connection->executeStatement('DELETE FROM memberships WHERE organization_id = ?', [$organizationId]);
                $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$organizationId]);
                $connection->executeStatement(
                    'DELETE FROM principals WHERE email LIKE ?',
                    ['%-'.$this->suffix.'@example.test'],
                );
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testTheOnlyOwnerCannotBeDemoted(): void
    {
        $this->patchRole($this->ownerMembership, 'Member');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('last_owner', $this->errorCode());
        self::assertSame('Owner', $this->roleOf($this->ownerMembership));
    }

    public function testTheOnlyOwnerCannotRemoveThemselves(): void
    {
        $this->deleteMember($this->ownerMembership);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('last_owner', $this->errorCode());
        self::assertSame(1, $this->membershipCount());
    }

    public function testAnOwnerCanBeDemotedWhenAnotherActiveOwnerRemains(): void
    {
        $this->addOwner();

        $this->patchRole($this->ownerMembership, 'Admin');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Admin', $this->roleOf($this->ownerMembership));
    }

    public function testAnOwnerCanRemoveAnotherOwnerWhenOneRemains(): void
    {
        $this->addOwner();

        $this->deleteMember($this->otherOwnerMembership);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(1, $this->membershipCount());
        self::assertSame('Owner', $this->roleOf($this->ownerMembership));
    }

    public function testAnAdminCanBeRemovedByAnOwner(): void
    {
        $admin = $this->addMember('Admin');

        $this->deleteMember($admin);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(1, $this->membershipCount());
    }

    /**
     * The handler must take the per-tenant lock: while another connection holds
     * it, the request waits instead of reading a stale roster.
     */
    public function testOwnerSensitiveChangesTakeThePerOrganizationLock(): void
    {
        $this->addOwner();

        $params = $this->entityManager->getConnection()->getParams();
        $other = DriverManager::getConnection($params);
        $this->entityManager->getConnection()->executeStatement("SET lock_timeout = '300ms'");
        $this->client->catchExceptions(false);

        try {
            $other->beginTransaction();
            $other->fetchOne(
                'SELECT pg_advisory_xact_lock(hashtext(:scope))',
                ['scope' => 'organization-owners:'.$this->id($this->organization)],
            );

            $this->patchRole($this->ownerMembership, 'Admin');
            self::fail('The demotion should wait on the organization lock, not proceed past it.');
        } catch (\Throwable $exception) {
            self::assertTrue(
                str_contains($exception->getMessage(), '55P03') || str_contains($exception->getMessage(), 'lock timeout'),
                sprintf('Expected a lock-wait timeout, got %s: %s', $exception::class, $exception->getMessage()),
            );
        } finally {
            // `catchExceptions(false)` skips the kernel's exception listeners, so
            // the failed request left its transaction open and aborted. Clear it
            // before this connection answers anything else.
            $main = $this->entityManager->getConnection();
            if ($main->isTransactionActive()) {
                $main->rollBack();
            }
            $main->executeStatement('SET lock_timeout = DEFAULT');
            if ($other->isTransactionActive()) {
                $other->rollBack();
            }
            $other->close();
        }

        self::assertSame('Owner', $this->roleOf($this->ownerMembership));
    }

    /**
     * The second request sees the first one's committed change.
     *
     * With two Owners, one is demoted (committed by another connection). The
     * remaining Owner is now the last one, so demoting them must be refused even
     * though a stale count from before the change would have allowed it.
     */
    public function testACommittedDemotionIsReflectedBeforeTheNextDecision(): void
    {
        $this->addOwner();

        $this->entityManager->getConnection()->executeStatement(
            "UPDATE memberships SET role = 'Admin' WHERE id = ?",
            [$this->id($this->otherOwnerMembership)],
        );
        $this->entityManager->clear();

        $this->patchRole($this->ownerMembership, 'Admin');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('last_owner', $this->errorCode());
    }

    /**
     * An Admin cannot touch an Owner, and cannot promote anyone to Owner.
     */
    public function testAnAdminCannotManageAnOwnerOrGrantOwnership(): void
    {
        $this->addOwner();
        $adminPrincipal = new Principal('admin-'.$this->suffix.'@example.test', 'Admin');
        $this->entityManager->persist($adminPrincipal);
        $this->entityManager->flush();
        $admin = new Membership($this->organization, $adminPrincipal, 'Admin');
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        $this->client->loginUser($adminPrincipal);
        $this->patchRole($this->ownerMembership, 'Member');
        self::assertResponseStatusCodeSame(404);

        $member = $this->addMember('Member');
        $this->patchRole($member, 'Owner');
        self::assertResponseStatusCodeSame(404);

        self::assertSame('Owner', $this->roleOf($this->ownerMembership));
        self::assertSame('Member', $this->roleOf($member));
    }

    private function addOwner(): void
    {
        $this->otherOwner = new Principal('owner-b-'.$this->suffix.'@example.test', 'Owner B');
        $this->entityManager->persist($this->otherOwner);
        $this->entityManager->flush();
        $this->otherOwnerMembership = new Membership($this->managedOrganization(), $this->otherOwner, 'Owner');
        $this->entityManager->persist($this->otherOwnerMembership);
        $this->entityManager->flush();
    }

    private function addMember(string $role): Membership
    {
        $principal = new Principal('member-'.bin2hex(random_bytes(4)).'-'.$this->suffix.'@example.test', 'Member');
        $this->entityManager->persist($principal);
        $this->entityManager->flush();
        $membership = new Membership($this->managedOrganization(), $principal, $role);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        return $membership;
    }

    /**
     * The organization as the unit of work currently knows it.
     *
     * Switching the signed-in user can detach the test's entities from the
     * manager the request used; building a membership from a detached tenant
     * would make Doctrine treat it as a new row.
     */
    private function managedOrganization(): Organization
    {
        $organization = $this->entityManager->find(Organization::class, $this->id($this->organization));
        self::assertInstanceOf(Organization::class, $organization);

        return $organization;
    }

    private function patchRole(Membership $membership, string $role): void
    {
        $this->client->request(
            'PATCH',
            sprintf('/api/organizations/%s/members/%s', $this->id($this->organization), $this->id($membership)),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => 'http://localhost',
                'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client),
            ],
            content: json_encode(['role' => $role], \JSON_THROW_ON_ERROR),
        );
    }

    private function deleteMember(Membership $membership): void
    {
        $this->client->request(
            'DELETE',
            sprintf('/api/organizations/%s/members/%s', $this->id($this->organization), $this->id($membership)),
            server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client)],
        );
    }

    private function roleOf(Membership $membership): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne(
            'SELECT role FROM memberships WHERE id = ?',
            [$this->id($membership)],
        );
    }

    private function membershipCount(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM memberships WHERE organization_id = ?',
            [$this->id($this->organization)],
        );
    }

    private function errorCode(): string
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return (string) ($payload['error']['code'] ?? '');
    }

    private function id(object $entity): string
    {
        return (new \ReflectionProperty($entity, 'id'))->getValue($entity);
    }
}
