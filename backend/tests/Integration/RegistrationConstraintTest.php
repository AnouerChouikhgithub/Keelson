<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Organization;
use App\Util\Subdomain;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Registration has two unique indexes to satisfy — the address and the generated
 * organization subdomain — and they must not be conflated.
 *
 * A taken address answers the same `201` as a new one (enumeration safety). A
 * taken subdomain is a different thing: the account was not created, so
 * reporting success would tell a user to check a mailbox for an account that
 * does not exist. It is regenerated with a numeric suffix, and when no candidate
 * is free the request fails loudly and leaves nothing behind.
 */
final class RegistrationConstraintTest extends WebTestCase
{
    private const PASSWORD = 'Correct-Horse-9';
    private const ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private string $email;
    private string $organizationName;
    private string $baseSubdomain;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->suffix = bin2hex(random_bytes(6));
        $this->email = 'constraint-'.$this->suffix.'@example.test';
        $this->organizationName = 'Shared Name '.$this->suffix;
        $this->baseSubdomain = Subdomain::fromName($this->organizationName);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $connection->executeStatement(
                    'DELETE FROM memberships WHERE organization_id IN (SELECT id FROM organizations WHERE name LIKE ?)',
                    ['%'.$this->suffix.'%'],
                );
                $connection->executeStatement('DELETE FROM organizations WHERE name LIKE ?', ['%'.$this->suffix.'%']);
                $connection->executeStatement('DELETE FROM principals WHERE email LIKE ?', ['%-'.$this->suffix.'@example.test']);
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testATakenSubdomainIsResolvedWithANumericSuffix(): void
    {
        $this->persistOrganization($this->organizationName, $this->baseSubdomain);

        $this->register();

        self::assertResponseStatusCodeSame(201);
        $subdomain = (string) $this->entityManager->getConnection()->fetchOne(
            'SELECT o.subdomain FROM memberships m JOIN organizations o ON o.id = m.organization_id WHERE m.principal_id = (SELECT id FROM principals WHERE email = ?)',
            [$this->email],
        );
        self::assertSame($this->baseSubdomain.'-2', $subdomain);
        self::assertSame(1, $this->principalCount());
    }

    /**
     * The whole candidate list taken is an explicit conflict, not a false
     * success — and the failed attempt leaves no principal behind.
     */
    public function testAnExhaustedSubdomainSpaceFailsExplicitlyAndCreatesNothing(): void
    {
        for ($attempt = 1; $attempt <= 50; ++$attempt) {
            $this->persistOrganization(
                $this->organizationName.' '.$attempt,
                Subdomain::withSuffix($this->baseSubdomain, $attempt),
            );
        }

        $this->register();

        self::assertResponseStatusCodeSame(409);
        self::assertSame('subdomain_unavailable', $this->errorCode());
        self::assertSame(0, $this->principalCount(), 'A rolled-back registration must not leave an account.');
        self::assertEmailCount(0);
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM organizations WHERE name = ?',
            [$this->organizationName],
        ));
    }

    /**
     * Enumeration safety survives the new error handling: an existing address
     * still answers `201`, sends nothing, and creates no second organization.
     */
    public function testAnExistingAddressStillAnswersLikeANewOne(): void
    {
        $this->register();
        self::assertResponseStatusCodeSame(201);
        self::assertEmailCount(1);
        $organizations = $this->organizationCount();

        $this->register();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['status' => 'verification_sent'], $this->payload());
        self::assertEmailCount(0);
        self::assertSame($organizations, $this->organizationCount());
        self::assertSame(1, $this->principalCount());
    }

    private function register(): void
    {
        $this->client->request(
            'POST',
            '/api/auth/register',
            server: ['CONTENT_TYPE' => 'application/json'] + self::ORIGIN,
            content: json_encode([
                'email' => $this->email,
                'password' => self::PASSWORD,
                'organizationName' => $this->organizationName,
                'displayName' => 'Constraint Owner',
            ], \JSON_THROW_ON_ERROR),
        );
    }

    private function persistOrganization(string $name, string $subdomain): void
    {
        $this->entityManager->persist(new Organization($name, $subdomain));
        $this->entityManager->flush();
    }

    private function principalCount(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM principals WHERE email = ?',
            [$this->email],
        );
    }

    private function organizationCount(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM organizations WHERE name LIKE ?',
            ['%'.$this->suffix.'%'],
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function errorCode(): string
    {
        return (string) ($this->payload()['error']['code'] ?? '');
    }
}
