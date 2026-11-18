<?php

declare(strict_types=1);

namespace App\Tests\Integration\Summary;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Exception\ApiException;
use App\Repository\InvoiceRepository;
use App\Repository\MembershipRepository;
use App\Summary\SummaryKind;
use App\Summary\WorkspaceFactsBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The facts on a seeded database.
 *
 * Every assertion is a number a human could check on the dashboard or the invoice
 * list: the summary may describe what those screens show, and nothing else. The
 * second organization exists only to prove it is invisible here.
 */
final class WorkspaceFactsBuilderTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private WorkspaceFactsBuilder $builder;
    private string $suffix;
    private Organization $organization;
    private Organization $foreignOrganization;

    /** @var array<string, Principal> */
    private array $principals = [];

    private Principal $stranger;

    /** @var array<string, Customer> */
    private array $customers = [];

    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);

        $this->builder = new WorkspaceFactsBuilder(
            $this->entityManager->getConnection(),
            $this->entityManager->getRepository(Membership::class),
            $this->entityManager->getRepository(Invoice::class),
        );
        self::assertInstanceOf(MembershipRepository::class, $this->entityManager->getRepository(Membership::class));
        self::assertInstanceOf(InvoiceRepository::class, $this->entityManager->getRepository(Invoice::class));

        $this->today = new \DateTimeImmutable('today');
        $this->suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Summary Tenant '.$this->suffix, 'summary-'.$this->suffix);
        $this->foreignOrganization = new Organization('Foreign Tenant '.$this->suffix, 'foreign-'.$this->suffix);
        $this->principals['Owner'] = new Principal('summary-owner-'.$this->suffix.'@example.test', 'Summary Owner');
        $this->principals['Viewer'] = new Principal('summary-viewer-'.$this->suffix.'@example.test', 'Summary Viewer');
        $this->stranger = new Principal('summary-stranger-'.$this->suffix.'@example.test', 'Stranger');

        foreach ([$this->organization, $this->foreignOrganization, ...array_values($this->principals), $this->stranger] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        foreach (['Owner', 'Viewer'] as $role) {
            $this->entityManager->persist(new Membership($this->organization, $this->principals[$role], $role));
        }
        $this->entityManager->flush();

        for ($index = 1; $index <= 6; ++$index) {
            $customer = new Customer(
                $this->organization,
                'Debtor '.$index.' '.$this->suffix,
                'debtor'.$index.'@example.test',
                '+216 00 000 000',
                ['street' => '1 Rue Example', 'city' => 'Tunis', 'postalCode' => '1000', 'countryCode' => 'TN'],
            );
            $this->customers['c'.$index] = $customer;
            $this->entityManager->persist($customer);
        }
        $foreignCustomer = new Customer($this->foreignOrganization, 'Foreign Only Ltd '.$this->suffix);
        $this->entityManager->persist($foreignCustomer);
        $this->entityManager->flush();

        $now = new \DateTimeImmutable();
        // Current, not yet due.
        $this->sent($this->invoice($this->customers['c1'], 'a', '1240.000', $this->today, $this->today->modify('+10 days')), $now);
        // Sent, past due: overdue by the due date, like the list derives it.
        $this->sent($this->invoice($this->customers['c2'], 'b', '300.000', $this->today->modify('-20 days'), $this->today->modify('-10 days'), $this->today->modify('-20 days')), $now);
        // Persisted overdue through the explicit transition.
        $overdue = $this->sent(
            $this->invoice($this->customers['c3'], 'c', '500.000', $this->today->modify('-10 days'), $this->today->modify('-5 days'), $this->today->modify('-10 days')),
            $now->modify('-2 days'),
        );
        $overdue->markOverdue($now);
        // Due within the week.
        $this->sent($this->invoice($this->customers['c4'], 'd', '100.000', $this->today, $this->today->modify('+3 days')), $now);
        $this->sent($this->invoice($this->customers['c5'], 'e', '10.000', $this->today, $this->today->modify('+20 days')), $now);
        $this->sent($this->invoice($this->customers['c6'], 'f', '20.000', $this->today, $this->today->modify('+20 days')), $now);
        // An old draft: it counts, but it is not activity in the last seven days.
        $this->entityManager->persist(
            $this->invoice($this->customers['c1'], 'g', '50.000', $this->today->modify('-20 days'), $this->today->modify('-6 days'), $this->today->modify('-20 days')),
        );
        // Paid yesterday: it is activity, and this month's revenue when the month allows.
        $paid = $this->sent(
            $this->invoice($this->customers['c1'], 'h', '999.000', $this->today->modify('-3 days'), $this->today->modify('+11 days'), $now->modify('-3 days')),
            $now->modify('-2 days'),
        );
        $paid->markPaid($now->modify('-1 day'));
        $this->entityManager->flush();

        $this->sent(
            $this->invoice($foreignCustomer, 'x', '7777.000', $this->today, $this->today->modify('+5 days'), null, $this->foreignOrganization),
            $now,
        );
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $connection = $this->entityManager->getConnection();
            $organizationIds = [$this->organization->getId(), $this->foreignOrganization->getId()];
            foreach (['invoices', 'customers', 'memberships'] as $table) {
                $connection->executeStatement('DELETE FROM '.$table.' WHERE organization_id IN (?, ?)', $organizationIds);
            }
            $connection->executeStatement('DELETE FROM organizations WHERE id IN (?, ?)', $organizationIds);
            $principalIds = [...array_map(static fn (Principal $principal): string => $principal->getId(), array_values($this->principals)), $this->stranger->getId()];
            $connection->executeStatement('DELETE FROM principals WHERE id IN (?, ?, ?)', $principalIds);
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testOverviewCountsWhatTheDashboardCounts(): void
    {
        $facts = $this->build(SummaryKind::Overview);

        self::assertSame('Owner', $facts->role);
        self::assertSame('TND', $facts->currency);
        self::assertSame(6, $facts->numbers['customerCount']);
        self::assertSame(8, $facts->numbers['invoiceCount']);
        self::assertSame(1, $facts->numbers['draftInvoiceCount']);
        self::assertSame(4, $facts->numbers['sentInvoiceCount']);
        self::assertSame(2, $facts->numbers['overdueInvoiceCount']);
        self::assertSame(1, $facts->numbers['paidInvoiceCount']);
        self::assertSame(2170000, $facts->numbers['outstandingMillimes']);
        self::assertSame(800000, $facts->numbers['overdueMillimes']);
        self::assertSame(
            $this->today->modify('-1 day')->format('Y-m') === $this->today->format('Y-m') ? 999000 : 0,
            $facts->numbers['paidThisMonthMillimes'],
            'Revenue is this month only.',
        );
        self::assertSame($this->today->format('Y-m-d'), $facts->dates['asOf']);
    }

    public function testReceivablesSeparatesOverdueFromDueSoon(): void
    {
        $facts = $this->build(SummaryKind::Receivables);

        self::assertSame(6, $facts->numbers['openInvoiceCount']);
        self::assertSame(2, $facts->numbers['overdueInvoiceCount']);
        self::assertSame(1, $facts->numbers['dueSoonInvoiceCount']);
        self::assertSame(100000, $facts->numbers['dueSoonMillimes']);
        self::assertSame(800000, $facts->numbers['overdueMillimes']);
        self::assertSame(2170000, $facts->numbers['outstandingMillimes']);
        self::assertSame($this->today->modify('-10 days')->format('Y-m-d'), $facts->dates['oldestDueDate']);
    }

    public function testActivityCoversTheLastSevenDaysOnly(): void
    {
        $facts = $this->build(SummaryKind::Activity);

        self::assertSame(7, $facts->numbers['windowDays']);
        self::assertSame(5, $facts->numbers['invoicesCreated'], 'The 20-day-old draft is not activity.');
        self::assertSame(7, $facts->numbers['invoicesSent']);
        self::assertSame(1, $facts->numbers['invoicesPaid']);
        self::assertSame(999000, $facts->numbers['paidMillimes']);
        self::assertSame(6, $facts->numbers['customersCreated']);
        self::assertSame($this->today->modify('-6 days')->format('Y-m-d'), $facts->dates['windowStart']);
    }

    public function testCustomerNamesAreCappedAtFiveAndOrderedByBalance(): void
    {
        $facts = $this->build(SummaryKind::Overview);

        self::assertCount(5, $facts->customerNames);
        self::assertSame($this->customers['c1']->getName(), $facts->customerNames[0]);
        self::assertSame($this->customers['c3']->getName(), $facts->customerNames[1]);
        self::assertSame($this->customers['c2']->getName(), $facts->customerNames[2]);
        self::assertNotContains($this->customers['c5']->getName(), $facts->customerNames, 'The smallest balance is the sixth name.');
    }

    public function testTheSecondTenantContributesNothing(): void
    {
        $facts = $this->build(SummaryKind::Overview);

        self::assertNotContains('Foreign Only Ltd '.$this->suffix, $facts->customerNames);
        self::assertSame(2170000, $facts->numbers['outstandingMillimes'], 'A foreign invoice must not reach the total.');
        self::assertSame(6, $facts->numbers['customerCount']);
    }

    public function testNoContactDetailLeavesTheDatabase(): void
    {
        $payload = json_encode($this->build(SummaryKind::Overview)->toArray(), \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('@example.test', $payload);
        self::assertStringNotContainsString('+216', $payload);
        self::assertStringNotContainsString('Rue Example', $payload);
    }

    public function testAViewerReceivesExactlyWhatAnOwnerReceives(): void
    {
        $owner = $this->build(SummaryKind::Overview);
        $viewer = $this->build(SummaryKind::Overview, 'Viewer');

        self::assertSame('Viewer', $viewer->role);
        self::assertSame($owner->numbers, $viewer->numbers);
        self::assertSame($owner->customerNames, $viewer->customerNames);
        self::assertSame($owner->dates, $viewer->dates);
    }

    /** @return iterable<string, array{SummaryKind}> */
    public static function kinds(): iterable
    {
        foreach (SummaryKind::cases() as $kind) {
            yield $kind->value => [$kind];
        }
    }

    #[DataProvider('kinds')]
    public function testAForeignPrincipalAndAForeignTenantAreBothNotFound(SummaryKind $kind): void
    {
        try {
            $this->builder->build($this->organization->getId(), $this->stranger->getId(), $kind, 'en');
            self::fail('A non-member must not obtain facts.');
        } catch (ApiException $exception) {
            self::assertSame('not_found', $exception->getErrorCode());
            self::assertSame(404, $exception->getStatusCode());
        }

        try {
            $this->builder->build($this->foreignOrganization->getId(), $this->principals['Owner']->getId(), $kind, 'en');
            self::fail('A member of one tenant must not obtain another tenant\'s facts.');
        } catch (ApiException $exception) {
            self::assertSame('not_found', $exception->getErrorCode());
            self::assertSame(404, $exception->getStatusCode());
        }
    }

    private function build(SummaryKind $kind, string $role = 'Owner'): \App\Summary\WorkspaceFacts
    {
        return $this->builder->build(
            $this->organization->getId(),
            $this->principals[$role]->getId(),
            $kind,
            'en',
        );
    }

    private function invoice(
        Customer $customer,
        string $label,
        string $total,
        \DateTimeImmutable $issueDate,
        \DateTimeImmutable $dueDate,
        ?\DateTimeImmutable $createdAt = null,
        ?Organization $organization = null,
    ): Invoice {
        return new Invoice(
            $organization ?? $this->organization,
            $customer,
            'S-'.$label.'-'.$this->suffix,
            $this->principals['Owner']->getId(),
            $issueDate,
            $dueDate,
            [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => $total, 'lineTotal' => $total]],
            null,
            null,
            $total,
            '0.000',
            $total,
            $createdAt ?? new \DateTimeImmutable(),
        );
    }

    private function sent(Invoice $invoice, \DateTimeImmutable $sentAt): Invoice
    {
        $invoice->send($sentAt);
        $this->entityManager->persist($invoice);

        return $invoice;
    }
}
