<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The checklist is computed from the database, never from a stored flag, so these
 * tests create real rows and watch the steps flip. The tenant in the path is
 * re-authorized: a foreign organization is the same `404` as a missing one.
 */
final class OnboardingApiTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private Organization $organization;
    /** A workspace with a single member, for the empty-checklist assertion. */
    private Organization $freshOrganization;
    private Organization $otherOrganization;
    private Principal $owner;
    private Principal $viewer;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();

        $suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Onboarding '.$suffix, 'onboarding-'.$suffix);
        $this->freshOrganization = new Organization('Onboarding Fresh '.$suffix, 'onboarding-fresh-'.$suffix);
        $this->otherOrganization = new Organization('Onboarding Other '.$suffix, 'onboarding-other-'.$suffix);
        $this->owner = new Principal('onboarding-owner-'.$suffix.'@example.test', 'Onboarding Owner');
        $this->viewer = new Principal('onboarding-viewer-'.$suffix.'@example.test', 'Onboarding Viewer');

        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->freshOrganization);
        $this->entityManager->persist($this->otherOrganization);
        $this->entityManager->persist($this->owner);
        $this->entityManager->persist($this->viewer);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->owner, 'Owner'));
        $this->entityManager->persist(new Membership($this->organization, $this->viewer, 'Viewer'));
        $this->entityManager->persist(new Membership($this->freshOrganization, $this->owner, 'Owner'));
        $this->entityManager->persist(new Membership($this->otherOrganization, $this->owner, 'Owner'));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement('DELETE FROM organizations WHERE id IN (?, ?, ?)', [
                $this->organization->getId(),
                $this->freshOrganization->getId(),
                $this->otherOrganization->getId(),
            ]);
            $connection->executeStatement('DELETE FROM principals WHERE id IN (?, ?)', [
                $this->owner->getId(),
                $this->viewer->getId(),
            ]);
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testAFreshOrganizationHasEveryStepToDo(): void
    {
        $this->client->loginUser($this->owner);

        $this->client->request('GET', $this->path($this->freshOrganization));
        self::assertResponseIsSuccessful();

        $payload = $this->payload();
        // The ids are part of the contract: the SPA maps each to a translated title.
        self::assertSame(
            ['invite_team', 'add_customer', 'create_invoice', 'send_invoice', 'record_payment'],
            array_column($payload['steps'], 'id'),
        );
        self::assertSame([false, false, false, false, false], array_column($payload['steps'], 'done'));
        self::assertSame(0, $payload['completed']);
        self::assertSame(5, $payload['total']);
    }

    public function testTheCountsFlipTheSteps(): void
    {
        $customer = new Customer($this->organization, 'Atelier '.bin2hex(random_bytes(4)));
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $invoice = new Invoice(
            $this->organization,
            $customer,
            '2026-000001',
            $this->owner->getId(),
            new \DateTimeImmutable('today'),
            new \DateTimeImmutable('+7 days'),
            [['description' => 'Design', 'quantity' => '1.000', 'unitPrice' => '100.000', 'lineTotal' => '100.000']],
            null,
            null,
            '100.000',
            '0.000',
            '100.000',
        );
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();
        $invoice->send();
        $invoice->markPaid();
        $this->entityManager->flush();

        $this->client->loginUser($this->viewer);
        $this->client->request('GET', $this->path($this->organization));
        self::assertResponseIsSuccessful();

        $payload = $this->payload();
        $done = array_combine(array_column($payload['steps'], 'id'), array_column($payload['steps'], 'done'));
        self::assertSame(
            ['invite_team' => true, 'add_customer' => true, 'create_invoice' => true, 'send_invoice' => true, 'record_payment' => true],
            $done,
        );
        self::assertSame(5, $payload['completed']);
    }

    public function testAForeignOrganizationIsNotFound(): void
    {
        $this->client->loginUser($this->viewer);

        $this->client->request('GET', $this->path($this->otherOrganization));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $this->payload()['error']['code']);
    }

    public function testAnInvalidIdentifierIsRejected(): void
    {
        $this->client->loginUser($this->owner);

        $this->client->request('GET', '/api/organizations/not-a-uuid/onboarding');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_id', $this->payload()['error']['code']);
    }

    public function testAnonymousCallersAreRejected(): void
    {
        $this->client->request('GET', $this->path($this->organization));

        self::assertResponseStatusCodeSame(401);
    }

    private function path(Organization $organization): string
    {
        return '/api/organizations/'.$organization->getId().'/onboarding';
    }

    /** @return array{steps: list<array{id: string, done: bool}>, completed: int, total: int} */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
