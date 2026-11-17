<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Data-driven IDOR check over every route that carries an identifier.
 *
 * The matrix tests prove the role rules for the routes they name; this one is
 * exhaustive by construction: it reads the router, so a route added tomorrow is
 * covered without anyone remembering to add it here.
 *
 * Two callers try every identifier route with a *foreign* tenant's ids:
 *   - an authenticated member of another organization (no 2xx, no 5xx);
 *   - nobody at all (401 or 404, never a body).
 *
 * Requests are deliberately unauthenticated-safe: a rejected body (4xx) counts as
 * a pass, because the claim is that no foreign resource is reachable, not that
 * every route validates before it authorizes.
 */
final class RouteIdorTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Organization $victimOrganization;
    private Organization $attackerOrganization;
    private Principal $victimOwner;
    private Principal $attacker;
    private Membership $victimMembership;
    private Customer $victimCustomer;
    private Invoice $victimInvoice;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);
        $this->suffix = bin2hex(random_bytes(6));

        $this->victimOrganization = new Organization('Victim Tenant '.$this->suffix, 'victim-'.$this->suffix);
        $this->attackerOrganization = new Organization('Attacker Tenant '.$this->suffix, 'attacker-'.$this->suffix);
        $this->victimOwner = new Principal('idor-victim-'.$this->suffix.'@example.test');
        $this->attacker = new Principal('idor-attacker-'.$this->suffix.'@example.test');
        $this->entityManager->persist($this->victimOrganization);
        $this->entityManager->persist($this->attackerOrganization);
        $this->entityManager->persist($this->victimOwner);
        $this->entityManager->persist($this->attacker);
        $this->entityManager->flush();

        $this->victimMembership = new Membership($this->victimOrganization, $this->victimOwner, 'Owner');
        $attackerMembership = new Membership($this->attackerOrganization, $this->attacker, 'Owner');
        $this->entityManager->persist($this->victimMembership);
        $this->entityManager->persist($attackerMembership);

        $this->victimCustomer = new Customer($this->victimOrganization, 'Victim Customer '.$this->suffix);
        $this->entityManager->persist($this->victimCustomer);
        $this->entityManager->flush();

        $this->victimInvoice = new Invoice(
            $this->victimOrganization,
            $this->victimCustomer,
            '2026-9'.substr($this->suffix, 0, 5),
            $this->id($this->victimOwner),
            new \DateTimeImmutable('today'),
            (new \DateTimeImmutable('today'))->modify('+14 days'),
            [['description' => 'Victim work', 'quantity' => '1.000', 'unitPrice' => '10.000', 'lineTotal' => '10.000']],
            null,
            null,
            '10.000',
            '0.000',
            '10.000',
        );
        $this->entityManager->persist($this->victimInvoice);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $organizationIds = [$this->id($this->victimOrganization), $this->id($this->attackerOrganization)];
                $principalIds = [$this->id($this->victimOwner), $this->id($this->attacker)];
                $connection->executeStatement('ALTER TABLE invoice_audit_events DISABLE TRIGGER invoice_audit_events_append_only');
                try {
                    foreach ($organizationIds as $organizationId) {
                        $connection->executeStatement('DELETE FROM invoice_audit_events WHERE organization_id = ?', [$organizationId]);
                        $connection->executeStatement('DELETE FROM notifications WHERE organization_id = ?', [$organizationId]);
                        $connection->executeStatement('DELETE FROM invoices WHERE organization_id = ?', [$organizationId]);
                        $connection->executeStatement('DELETE FROM customers WHERE organization_id = ?', [$organizationId]);
                        $connection->executeStatement('DELETE FROM invitations WHERE organization_id = ?', [$organizationId]);
                        $connection->executeStatement('DELETE FROM memberships WHERE organization_id = ?', [$organizationId]);
                        $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$organizationId]);
                    }
                    foreach ($principalIds as $principalId) {
                        $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$principalId]);
                    }
                } finally {
                    $connection->executeStatement('ALTER TABLE invoice_audit_events ENABLE TRIGGER invoice_audit_events_append_only');
                }
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testAForeignTenantReachesNoneOfTheIdentifierRoutes(): void
    {
        $client = $this->client;
        $client->loginUser($this->attacker);
        $checked = 0;

        foreach ($this->identifierRoutes() as $name => $route) {
            $method = $this->method($route);
            $path = $this->url($route);
            try {
                $client->request($method, $path, server: $this->unsafeServer($client, $method), content: $this->body($method));
                $status = $client->getResponse()->getStatusCode();
                self::assertGreaterThanOrEqual(400, $status, sprintf('%s %s answered %d to a foreign tenant.', $method, $name, $status));
                self::assertLessThan(500, $status, sprintf('%s %s answered %d (server error) to a foreign tenant.', $method, $name, $status));
            } catch (\PHPUnit\Framework\AssertionFailedError $failure) {
                throw $failure;
            } catch (\Throwable $exception) {
                self::fail(sprintf('%s %s (%s) raised %s: %s', $method, $name, $path, $exception::class, $exception->getMessage()));
            }
            ++$checked;
        }

        self::assertGreaterThan(15, $checked, 'The router should expose the tenant routes this test walks.');
    }

    public function testEveryIdentifierRouteRefusesAnAnonymousCaller(): void
    {
        $client = $this->client;
        $checked = 0;

        foreach ($this->identifierRoutes() as $name => $route) {
            $method = $this->method($route);
            $path = $this->url($route);
            $client->request($method, $path, server: ['HTTP_ORIGIN' => 'http://localhost', 'CONTENT_TYPE' => 'application/json'], content: $this->body($method));
            $status = $client->getResponse()->getStatusCode();

            self::assertContains($status, [401, 403, 404], sprintf('%s %s answered %d to an anonymous caller.', $method, $name, $status));
            ++$checked;
        }

        self::assertGreaterThan(15, $checked);
    }

    /** @return array<string, Route> */
    private function identifierRoutes(): array
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api/') || !str_contains($path, '{')) {
                continue;
            }
            $routes[$name] = $route;
        }

        return $routes;
    }

    private function url(Route $route): string
    {
        $replacements = [
            'organizationId' => $this->id($this->victimOrganization),
            'customerId' => $this->id($this->victimCustomer),
            'invoiceId' => $this->id($this->victimInvoice),
            'membershipId' => $this->id($this->victimMembership),
            // Ids that exist nowhere: the route must say "not found", not "boom".
            'invitationId' => '00000000-0000-4000-8000-000000000000',
            'notificationId' => '00000000-0000-4000-8000-000000000000',
            'token' => 'not-a-real-invitation-token',
        ];

        return preg_replace_callback(
            '/\{([a-zA-Z]+)\}/',
            static fn (array $match): string => $replacements[$match[1]] ?? 'not-a-valid-value',
            $route->getPath(),
        ) ?? $route->getPath();
    }

    private function method(Route $route): string
    {
        $methods = $route->getMethods();

        return [] === $methods ? 'GET' : $methods[0];
    }

    /** @return array<string, string> */
    private function unsafeServer(KernelBrowser $client, string $method): array
    {
        $server = ['HTTP_ORIGIN' => 'http://localhost', 'CONTENT_TYPE' => 'application/json'];
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $server['HTTP_X_CSRF_TOKEN'] = $this->csrfToken($client);
        }

        return $server;
    }

    private function body(string $method): ?string
    {
        return in_array($method, ['GET', 'HEAD'], true) ? null : '{}';
    }

    private function id(object $entity): string
    {
        return (new \ReflectionProperty($entity, 'id'))->getValue($entity);
    }
}
