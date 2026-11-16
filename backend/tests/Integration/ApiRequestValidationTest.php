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
 * A request the API cannot act on is a 4xx with a code the client can branch
 * on — never a 500, and never a different shape from the rest of the envelope.
 */
final class ApiRequestValidationTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private Principal $principal;
    private Organization $organization;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $suffix = bin2hex(random_bytes(6));
        $this->principal = new Principal('validation-'.$suffix.'@example.test', 'Validation Test User');
        $this->organization = new Organization('Validation Tenant '.$suffix, 'validation-'.$suffix);
        $this->entityManager->persist($this->principal);
        $this->entityManager->persist($this->organization);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->principal, 'Owner'));
        $this->entityManager->flush();
        $this->client->loginUser($this->principal, 'main');
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $connection->executeStatement('DELETE FROM memberships WHERE organization_id = ?', [$this->organization->getId()]);
                $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$this->organization->getId()]);
                $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$this->principal->getId()]);
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testAMalformedOrganizationIdIsRejectedBeforeTheTenantIsInspected(): void
    {
        $this->client->request('GET', '/api/organizations/not-a-uuid/customers');

        self::assertResponseStatusCodeSame(400);
        $payload = $this->payload();
        self::assertSame('invalid_id', $payload['error']['code']);
        self::assertArrayNotHasKey('fields', $payload['error']);
    }

    public function testAMalformedInvoiceIdIsRejected(): void
    {
        $this->client->request('GET', '/api/organizations/'.$this->organization->getId().'/invoices/1234');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_id', $this->payload()['error']['code']);
    }

    public function testAMalformedCursorIsRejectedRatherThanIgnored(): void
    {
        $this->client->request('GET', '/api/organizations/'.$this->organization->getId().'/invoices?cursor=%25%25%25');

        // Ignoring a cursor would silently return the first page again, which
        // looks to the client like the end of the list.
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_id', $this->payload()['error']['code']);
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $this->client->request(
            'POST',
            $this->customersPath(),
            server: $this->unsafeServer(),
            content: '{"name":',
        );

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_json', $this->payload()['error']['code']);
    }

    public function testAnEmptyJsonObjectIsABadRequest(): void
    {
        $this->client->request('POST', $this->customersPath(), server: $this->unsafeServer(), content: '{}');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_json', $this->payload()['error']['code']);
    }

    public function testAJsonListIsNotAnAcceptableBody(): void
    {
        $this->client->request('POST', $this->customersPath(), server: $this->unsafeServer(), content: '[]');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_json', $this->payload()['error']['code']);
    }

    public function testAWrongFieldTypeIsAValidationFailureWithTheFieldNamed(): void
    {
        $this->client->request(
            'POST',
            $this->customersPath(),
            server: $this->unsafeServer(),
            content: '{"name":[]}',
        );

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload();
        self::assertSame('validation_failed', $payload['error']['code']);
        self::assertArrayHasKey('name', $payload['error']['fields']);
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM customers WHERE organization_id = ?',
            [$this->organization->getId()],
        ));
    }

    public function testAValidationFailureStillCarriesTheFieldMessagesFromTheDomainRules(): void
    {
        $this->client->request(
            'POST',
            $this->customersPath(),
            server: $this->unsafeServer(),
            content: '{"name":" ","email":"not-an-address"}',
        );

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload();
        self::assertSame('validation_failed', $payload['error']['code']);
        self::assertIsString($payload['error']['message']);
    }

    private function customersPath(): string
    {
        return '/api/organizations/'.$this->organization->getId().'/customers';
    }

    /** @return array<string, string> */
    private function unsafeServer(): array
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
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
