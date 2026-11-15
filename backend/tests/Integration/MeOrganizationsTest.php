<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `GET /api/me/organizations` returns the caller's own active memberships only.
 *
 * It is the discovery read the SPA needs in order to address the existing
 * tenant routes. Authorization for those routes does not depend on it.
 */
final class MeOrganizationsTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Principal $principal;
    private Principal $other;
    private Organization $ownedOrganization;
    private Organization $memberOrganization;
    private Organization $otherOrganization;
    private Organization $suspendedOrganization;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);
        $this->suffix = bin2hex(random_bytes(6));
        $issuer = $_SERVER['OIDC_ISSUER'] ?? $_ENV['OIDC_ISSUER'] ?? null;
        self::assertNotNull($issuer, 'OIDC_ISSUER must be configured for the HTTP test user provider.');

        $this->principal = new Principal($issuer, 'orgs-'.$this->suffix);
        $this->other = new Principal($issuer, 'orgs-other-'.$this->suffix);
        $this->ownedOrganization = new Organization('Owned '.$this->suffix, 'owned-'.$this->suffix);
        $this->memberOrganization = new Organization('Member '.$this->suffix, 'member-'.$this->suffix);
        $this->otherOrganization = new Organization('Foreign '.$this->suffix, 'foreign-'.$this->suffix);
        $this->suspendedOrganization = new Organization('Suspended '.$this->suffix, 'suspended-'.$this->suffix);
        foreach ([$this->principal, $this->other] as $principal) {
            $this->entityManager->persist($principal);
        }
        foreach ([$this->ownedOrganization, $this->memberOrganization, $this->otherOrganization, $this->suspendedOrganization] as $organization) {
            $this->entityManager->persist($organization);
        }
        $this->entityManager->flush();

        $this->entityManager->persist(new Membership($this->ownedOrganization, $this->principal, 'Owner'));
        $this->entityManager->persist(new Membership($this->memberOrganization, $this->principal, 'Viewer'));
        $this->entityManager->persist(new Membership($this->otherOrganization, $this->other, 'Owner'));
        $this->entityManager->persist(new Membership($this->suspendedOrganization, $this->principal, 'Admin'));
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE organizations SET status = 'suspended' WHERE id = ?",
            [$this->id($this->suspendedOrganization)],
        );
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $organizationIds = [
                    $this->id($this->ownedOrganization),
                    $this->id($this->memberOrganization),
                    $this->id($this->otherOrganization),
                    $this->id($this->suspendedOrganization),
                ];
                foreach ($organizationIds as $organizationId) {
                    $connection->executeStatement('DELETE FROM memberships WHERE organization_id = ?', [$organizationId]);
                    $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$organizationId]);
                }
                $connection->executeStatement('DELETE FROM principals WHERE id IN (?, ?)', [
                    $this->id($this->principal),
                    $this->id($this->other),
                ]);
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testListsOnlyTheCallersActiveMembershipsWithTheirRole(): void
    {
        $this->client->loginUser($this->principal);

        $this->client->request('GET', '/api/me/organizations');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $items = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['items'];
        $byId = [];
        foreach ($items as $item) {
            self::assertSame(['id', 'name', 'subdomain', 'role'], array_keys($item), 'The payload must stay a small, stable projection.');
            $byId[$item['id']] = $item;
        }

        self::assertArrayHasKey($this->id($this->ownedOrganization), $byId);
        self::assertSame('Owner', $byId[$this->id($this->ownedOrganization)]['role']);
        self::assertSame($this->ownedOrganization->getName(), $byId[$this->id($this->ownedOrganization)]['name']);
        self::assertSame($this->ownedOrganization->getSubdomain(), $byId[$this->id($this->ownedOrganization)]['subdomain']);

        self::assertArrayHasKey($this->id($this->memberOrganization), $byId);
        self::assertSame('Viewer', $byId[$this->id($this->memberOrganization)]['role']);

        // Another principal's organization and a suspended organization are invisible.
        self::assertArrayNotHasKey($this->id($this->otherOrganization), $byId);
        self::assertArrayNotHasKey($this->id($this->suspendedOrganization), $byId);
        self::assertCount(2, $items);
    }

    public function testSuspendedMembershipDisappearsFromTheList(): void
    {
        $membership = $this->entityManager->getRepository(Membership::class)->findOneBy([
            'organization' => $this->memberOrganization,
            'principal' => $this->principal,
        ]);
        self::assertInstanceOf(Membership::class, $membership);
        $membership->suspend();
        $this->entityManager->flush();

        $this->client->loginUser($this->principal);
        $this->client->request('GET', '/api/me/organizations');

        self::assertResponseIsSuccessful();
        $items = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['items'];
        self::assertSame([$this->id($this->ownedOrganization)], array_column($items, 'id'));
    }

    public function testAnonymousCallerIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/api/me/organizations');
        self::assertResponseRedirects('/login');
    }

    public function testTheListCannotBeWidenedByARequestParameter(): void
    {
        $this->client->loginUser($this->principal);
        $this->client->request('GET', '/api/me/organizations?principalId='.$this->id($this->other).'&organizationId='.$this->id($this->otherOrganization));

        self::assertResponseIsSuccessful();
        $items = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['items'];
        self::assertArrayNotHasKey($this->id($this->otherOrganization), array_column($items, 'id', 'id'));
        self::assertCount(2, $items);
    }

    private function id(object $entity): string
    {
        return (new \ReflectionProperty($entity, 'id'))->getValue($entity);
    }
}
