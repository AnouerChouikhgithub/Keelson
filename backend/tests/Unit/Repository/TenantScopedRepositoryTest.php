<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Invitation;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantScopedRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private string $suffix;
    private Principal $principalA;
    private Principal $principalB;
    private Organization $organizationA;
    private Organization $organizationB;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);

        $this->suffix = bin2hex(random_bytes(6));
        $issuer = 'https://identity.example/tenant-tests/'.$this->suffix;
        $this->principalA = new Principal($issuer, 'principal-a');
        $this->principalB = new Principal($issuer, 'principal-b');
        $this->organizationA = new Organization('Tenant A '.$this->suffix, 'tenant-a-'.$this->suffix);
        $this->organizationB = new Organization('Tenant B '.$this->suffix, 'tenant-b-'.$this->suffix);

        foreach ([$this->principalA, $this->principalB, $this->organizationA, $this->organizationB] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $this->entityManager->persist(new Membership($this->organizationA, $this->principalA, 'Owner'));
        $this->entityManager->persist(new Membership($this->organizationA, $this->principalB, 'Member'));
        $this->entityManager->persist(new Membership($this->organizationB, $this->principalB, 'Owner'));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $connection = $this->entityManager->getConnection();
            foreach (['invitations', 'memberships'] as $table) {
                $connection->executeStatement('DELETE FROM '.$table.' WHERE organization_id IN (?, ?)', [
                    $this->entityId($this->organizationA),
                    $this->entityId($this->organizationB),
                ]);
            }
            $connection->executeStatement('DELETE FROM organizations WHERE id IN (?, ?)', [
                $this->entityId($this->organizationA),
                $this->entityId($this->organizationB),
            ]);
            $connection->executeStatement('DELETE FROM principals WHERE id IN (?, ?)', [
                $this->entityId($this->principalA),
                $this->entityId($this->principalB),
            ]);
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testOrganizationAndMembershipQueriesRequirePrincipalMembership(): void
    {
        $organizations = $this->entityManager->getRepository(Organization::class);
        $memberships = $this->entityManager->getRepository(Membership::class);

        self::assertEquals([$this->organizationA], $organizations->findForPrincipal($this->entityId($this->principalA)));
        self::assertEqualsCanonicalizing(
            [$this->organizationA, $this->organizationB],
            $organizations->findForPrincipal($this->entityId($this->principalB)),
        );
        self::assertSame([], $organizations->findForPrincipal('00000000-0000-4000-8000-000000000000'));
        self::assertNotNull($memberships->findForOrganizationAndPrincipal($this->entityId($this->organizationA), $this->entityId($this->principalA)));
        self::assertNull($memberships->findForOrganizationAndPrincipal($this->entityId($this->organizationB), $this->entityId($this->principalA)));
        self::assertCount(2, $memberships->findForOrganization($this->entityId($this->organizationA), $this->entityId($this->principalA)));
        self::assertCount(2, $memberships->findForOrganization($this->entityId($this->organizationA), $this->entityId($this->principalB)));
        self::assertSame([], $memberships->findForOrganization($this->entityId($this->organizationB), $this->entityId($this->principalA)));
    }

    public function testInvitationQueriesRequireSameTenantAndAdminRole(): void
    {
        $invitation = new Invitation(
            $this->organizationA,
            'invite-'.$this->suffix.'@example.test',
            'Viewer',
            hash('sha256', 'token-'.$this->suffix),
            $this->principalA,
            new \DateTimeImmutable('+1 day'),
        );
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $repository = $this->entityManager->getRepository(Invitation::class);
        self::assertSame($invitation, $repository->findForOrganization(
            $this->entityId($this->organizationA),
            $this->entityId($invitation),
            $this->entityId($this->principalA),
        ));
        self::assertNull($repository->findForOrganization(
            $this->entityId($this->organizationB),
            $this->entityId($invitation),
            $this->entityId($this->principalA),
        ));
        self::assertNull($repository->findForOrganization(
            $this->entityId($this->organizationA),
            $this->entityId($invitation),
            $this->entityId($this->principalB),
        ));
        self::assertCount(1, $repository->findForOrganizationList($this->entityId($this->organizationA), $this->entityId($this->principalA)));
        self::assertSame([], $repository->findForOrganizationList($this->entityId($this->organizationA), $this->entityId($this->principalB)));
    }

    public function testMembershipAndOrganizationListsExcludeSuspendedOrganizations(): void
    {
        (new \ReflectionProperty($this->organizationA, 'status'))->setValue($this->organizationA, 'suspended');
        $this->entityManager->flush();

        self::assertSame([], $this->entityManager->getRepository(Membership::class)->findActiveForPrincipal($this->entityId($this->principalA)));
        self::assertSame([], $this->entityManager->getRepository(Organization::class)->findForPrincipal($this->entityId($this->principalA)));
    }

    private function entityId(object $entity): string
    {
        return (new \ReflectionProperty($entity, 'id'))->getValue($entity);
    }
}
