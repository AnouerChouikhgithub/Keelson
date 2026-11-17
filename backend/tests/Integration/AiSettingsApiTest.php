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
 * The AI settings endpoint follows the organization rules: Owner and Admin manage it,
 * everyone else gets the same `404` as a foreign tenant. Enabling AI is a policy
 * change, so it must leave a security event.
 */
final class AiSettingsApiTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Organization $organization;
    private Organization $otherOrganization;

    /** @var array<string, Principal> */
    private array $principals = [];

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->suffix = bin2hex(random_bytes(6));

        $this->organization = new Organization('AI Settings '.$this->suffix, 'ai-settings-'.$this->suffix);
        $this->otherOrganization = new Organization('AI Other '.$this->suffix, 'ai-other-'.$this->suffix);
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->otherOrganization);

        foreach (['Owner', 'Admin', 'Member', 'Viewer', 'Outsider'] as $role) {
            $principal = new Principal('ai-'.strtolower($role).'-'.$this->suffix.'@example.test', $role);
            $this->entityManager->persist($principal);
            $this->principals[$role] = $principal;
        }
        $this->entityManager->flush();

        foreach (['Owner', 'Admin', 'Member', 'Viewer'] as $role) {
            $this->entityManager->persist(new Membership($this->organization, $this->principals[$role], $role));
        }
        $this->entityManager->persist(new Membership($this->otherOrganization, $this->principals['Outsider'], 'Owner'));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                foreach ([$this->organization, $this->otherOrganization] as $organization) {
                    $connection->executeStatement('DELETE FROM ai_requests WHERE organization_id = ?', [$organization->getId()]);
                }
                $connection->executeStatement(
                    "DELETE FROM organizations WHERE subdomain LIKE 'ai-settings-%' OR subdomain LIKE 'ai-other-%'"
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

    public function testViewerAndMemberCannotReadOrChangeTheSettings(): void
    {
        foreach (['Viewer', 'Member'] as $role) {
            $this->client->loginUser($this->principals[$role]);

            $this->client->request('GET', $this->path($this->organization));
            self::assertResponseStatusCodeSame(404, $role.' read');

            $this->send('PUT', $this->path($this->organization), ['enabled' => true, 'monthlyTokenBudget' => 1000]);
            self::assertResponseStatusCodeSame(404, $role.' write');
        }

        self::assertSame(0, $this->settingsCount(), 'A refused request must not create settings.');
    }

    public function testAForeignTenantIsNotFound(): void
    {
        $this->client->loginUser($this->principals['Owner']);

        $this->client->request('GET', $this->path($this->otherOrganization));
        self::assertResponseStatusCodeSame(404);

        $this->send('PUT', $this->path($this->otherOrganization), ['enabled' => true, 'monthlyTokenBudget' => 1000]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerEnablesAiAndItWritesASecurityEvent(): void
    {
        $this->client->loginUser($this->principals['Owner']);

        $this->send('PUT', $this->path($this->organization), ['enabled' => true, 'monthlyTokenBudget' => 50000]);
        self::assertResponseIsSuccessful();
        $payload = $this->payload();
        self::assertTrue($payload['enabled']);
        self::assertSame(50000, $payload['monthlyTokenBudget']);
        self::assertSame(50000, $payload['budgetRemainingTokens']);

        $events = $this->securityEvents();
        self::assertCount(1, $events);
        self::assertSame((string) $this->principals['Owner']->getId(), $events[0]['principal_id']);
        self::assertStringContainsString($this->organization->getId(), (string) $events[0]['data']);

        // Enabling an already-enabled tenant is not a new policy change.
        $this->send('PUT', $this->path($this->organization), ['enabled' => true, 'monthlyTokenBudget' => 50000]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->securityEvents());

        $this->client->request('GET', $this->path($this->organization));
        self::assertResponseIsSuccessful();
        self::assertSame(50000, $this->payload()['monthlyTokenBudget']);
    }

    public function testAdminMayChangeTheSettingsToo(): void
    {
        $this->client->loginUser($this->principals['Admin']);

        $this->send('PUT', $this->path($this->organization), ['enabled' => false, 'monthlyTokenBudget' => 0]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['enabled']);
        self::assertSame([], $this->securityEvents(), 'Disabling is not the enable event.');
    }

    public function testTheBodyIsValidated(): void
    {
        $this->client->loginUser($this->principals['Owner']);

        $this->send('PUT', $this->path($this->organization), ['enabled' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('monthlyTokenBudget', $this->payload()['error']['fields']);

        $this->send('PUT', $this->path($this->organization), ['enabled' => true, 'monthlyTokenBudget' => -1]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testStatusReadsTheSettingsAndTheGlobalSwitch(): void
    {
        $this->client->loginUser($this->principals['Owner']);

        $this->client->request('GET', '/api/ai/status?organizationId='.$this->organization->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(['enabled', 'organizationEnabled', 'budgetRemainingTokens'], array_keys($this->payload()));
        self::assertFalse($this->payload()['enabled'], 'AI_ENABLED defaults to false in the tracked environment.');
        self::assertFalse($this->payload()['organizationEnabled']);

        $this->send('PUT', $this->path($this->organization), ['enabled' => true, 'monthlyTokenBudget' => 8000]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/ai/status?organizationId='.$this->organization->getId());
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['organizationEnabled']);
        self::assertSame(8000, $this->payload()['budgetRemainingTokens']);

        $this->client->request('GET', '/api/ai/status?organizationId='.$this->otherOrganization->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheGenerativeEndpointsAreRoutedStubs(): void
    {
        $this->client->loginUser($this->principals['Member']);

        $this->send('POST', '/api/ai/guide', ['question' => 'How do I mark an invoice paid?']);
        self::assertResponseStatusCodeSame(501);
        self::assertSame('not_implemented', $this->payload()['error']['code']);

        $this->send('POST', $this->summariesPath($this->organization));
        self::assertResponseStatusCodeSame(501);
        self::assertSame('not_implemented', $this->payload()['error']['code']);

        $this->send('POST', $this->summariesPath($this->otherOrganization));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousRequestsAreRejected(): void
    {
        $this->client->request('GET', '/api/ai/status');
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', $this->path($this->organization));
        self::assertResponseStatusCodeSame(401);
    }

    private function path(Organization $organization): string
    {
        return '/api/organizations/'.$organization->getId().'/ai-settings';
    }

    private function summariesPath(Organization $organization): string
    {
        return '/api/organizations/'.$organization->getId().'/ai/summaries/invoices';
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

    private function settingsCount(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM organization_ai_settings WHERE organization_id = ?',
            [$this->organization->getId()],
        );
    }

    /** @return list<array<string, mixed>> */
    private function securityEvents(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT principal_id, data FROM security_events WHERE type = 'ai_enabled' AND data::text LIKE ?",
            ['%'.$this->organization->getId().'%'],
        );
    }
}
