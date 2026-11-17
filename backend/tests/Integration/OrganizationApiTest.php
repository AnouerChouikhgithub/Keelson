<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Creating and administering an organization, including who may not.
 *
 * The denial is 404, not 403, for the same reason as the invoice API: a caller
 * must not learn that a tenant exists by probing its id.
 */
final class OrganizationApiTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Organization $organization;

    /** @var array<string, Principal> */
    private array $principals = [];

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Org Api '.$this->suffix, 'org-api-'.$this->suffix);
        $this->entityManager->persist($this->organization);
        foreach (['Owner', 'Admin', 'Member'] as $role) {
            $principal = new Principal('org-'.strtolower($role).'-'.$this->suffix.'@example.test', $role);
            $this->entityManager->persist($principal);
            $this->principals[$role] = $principal;
        }
        $this->entityManager->flush();
        foreach ($this->principals as $role => $principal) {
            $this->entityManager->persist(new Membership($this->organization, $principal, $role));
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $connection->executeStatement(
                    "DELETE FROM organizations WHERE subdomain LIKE 'org-api-%' OR subdomain LIKE 'created-%' OR subdomain LIKE 'renamed-%'"
                );
                foreach ($this->principals as $principal) {
                    $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$principal->getId()]);
                }
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testCreateMakesTheCallerOwner(): void
    {
        $principal = $this->principals['Member'];
        $this->client->loginUser($principal);
        $subdomain = 'created-'.$this->suffix;

        $this->send('POST', '/api/organizations', ['name' => 'Created Tenant', 'subdomain' => $subdomain]);

        self::assertResponseStatusCodeSame(201);
        $payload = $this->payload();
        self::assertSame('Owner', $payload['organization']['role']);
        self::assertSame('active', $payload['organization']['status']);
        self::assertSame($subdomain, $payload['organization']['subdomain']);

        self::assertSame('Owner', $this->entityManager->getConnection()->fetchOne(
            'SELECT role FROM memberships WHERE organization_id = ? AND principal_id = ?',
            [$payload['organization']['id'], $principal->getId()],
        ));
    }

    public function testReservedAndTakenSubdomainsAreRefused(): void
    {
        $this->client->loginUser($this->principals['Owner']);

        $this->send('POST', '/api/organizations', ['name' => 'Reserved', 'subdomain' => 'admin']);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('subdomain', $this->payload()['error']['fields']);

        $this->send('POST', '/api/organizations', ['name' => 'Taken', 'subdomain' => $this->organization->getSubdomain()]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('subdomain_taken', $this->payload()['error']['code']);

        $this->send('POST', '/api/organizations', ['name' => 'Too short', 'subdomain' => 'ab']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSubdomainAvailabilityAlwaysAnswersWithABoolean(): void
    {
        $this->client->loginUser($this->principals['Owner']);

        foreach ([['free-'.$this->suffix, true], [$this->organization->getSubdomain(), false], ['www', false], ['Not A Label', false]] as [$value, $expected]) {
            $this->client->request('GET', '/api/organizations/subdomain-availability?value='.rawurlencode((string) $value));
            self::assertResponseIsSuccessful();
            self::assertSame($expected, $this->payload()['available'], (string) $value);
        }
    }

    public function testOnlyTheOwnerMayRenameOrReaddressTheTenant(): void
    {
        foreach (['Admin', 'Member'] as $role) {
            $this->client->loginUser($this->principals[$role]);
            $this->send('PATCH', $this->path(), ['name' => 'Renamed by '.$role]);
            self::assertResponseStatusCodeSame(404, $role);
        }

        $this->client->loginUser($this->principals['Owner']);
        $this->send('PATCH', $this->path(), ['name' => 'Renamed', 'subdomain' => 'renamed-'.$this->suffix]);
        self::assertResponseIsSuccessful();
        self::assertSame('Renamed', $this->payload()['organization']['name']);
        self::assertSame('renamed-'.$this->suffix, $this->payload()['organization']['subdomain']);

        $this->send('PATCH', $this->path(), ['name' => null]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDeletingRequiresTheExactNameAndHidesTheTenant(): void
    {
        $this->client->loginUser($this->principals['Admin']);
        $this->send('DELETE', $this->path(), ['confirmName' => $this->organization->getName()]);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->principals['Owner']);
        $this->send('DELETE', $this->path(), ['confirmName' => 'wrong']);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('confirmName', $this->payload()['error']['fields']);

        $this->send('DELETE', $this->path(), ['confirmName' => $this->organization->getName()]);
        self::assertResponseIsSuccessful();
        self::assertSame('deleted', $this->payload()['organization']['status']);

        // Memberships survive the soft delete; the tenant is simply unreachable.
        self::assertSame(3, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM memberships WHERE organization_id = ?',
            [$this->organization->getId()],
        ));
        $this->client->request('GET', $this->path().'/members');
        self::assertResponseStatusCodeSame(404);
        $this->send('PATCH', $this->path(), ['name' => 'Back']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousRequestsAreRejected(): void
    {
        // The headers belong in `server`: the third positional argument is the
        // form parameter bag, and passing them there silently sent a form body.
        $this->client->request('POST', '/api/organizations', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
        ], content: json_encode(['name' => 'Anon', 'subdomain' => 'created-anon-'.$this->suffix]));
        self::assertResponseStatusCodeSame(401);

        $this->client->request('PATCH', $this->path(), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
        ], content: json_encode(['name' => 'Anon']));
        self::assertResponseStatusCodeSame(401);
    }

    private function path(): string
    {
        return '/api/organizations/'.$this->organization->getId();
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null): void
    {
        $this->client->request($method, $path, server: $this->headers(), content: null === $body ? null : json_encode($body));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
