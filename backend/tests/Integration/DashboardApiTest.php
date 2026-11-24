<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Tests\Support\CountingConnection;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The dashboard has to be both correct and cheap.
 *
 * The numbers are asserted against invoices created with known totals and dates;
 * the cost is asserted by comparing the query count of the same request on a
 * small and a large tenant, which is what catches a lazy load per row.
 */
final class DashboardApiTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Organization $organization;
    private Customer $customer;

    /** @var array<string, Principal> */
    private array $principals = [];

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Dash Tenant '.$this->suffix, 'dash-'.$this->suffix);
        $this->entityManager->persist($this->organization);
        foreach (['Owner', 'Admin', 'Viewer', 'Outsider'] as $role) {
            $principal = new Principal('dash-'.strtolower($role).'-'.$this->suffix.'@example.test', $role);
            $this->entityManager->persist($principal);
            $this->principals[$role] = $principal;
        }
        $this->customer = new Customer($this->organization, 'Dash Customer '.$this->suffix);
        $this->entityManager->persist($this->customer);
        $this->entityManager->flush();
        foreach (['Owner', 'Admin', 'Viewer'] as $role) {
            $this->entityManager->persist(new Membership($this->organization, $this->principals[$role], $role));
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $connection->executeStatement('ALTER TABLE invoice_audit_events DISABLE TRIGGER invoice_audit_events_append_only');
                try {
                    $connection->executeStatement(
                        'DELETE FROM invoice_audit_events WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE ?)',
                        ['dash-%'],
                    );
                    $connection->executeStatement(
                        'DELETE FROM notifications WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE ?)',
                        ['dash-%'],
                    );
                    $connection->executeStatement('DELETE FROM invoices WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE ?)', ['dash-%']);
                    $connection->executeStatement('DELETE FROM customers WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE ?)', ['dash-%']);
                    $connection->executeStatement('DELETE FROM memberships WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE ?)', ['dash-%']);
                    $connection->executeStatement("DELETE FROM organizations WHERE subdomain LIKE 'dash-%'");
                } finally {
                    $connection->executeStatement('ALTER TABLE invoice_audit_events ENABLE TRIGGER invoice_audit_events_append_only');
                }
                foreach ($this->principals as $principal) {
                    $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$principal->getId()]);
                }
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testTheNumbersReflectTheTenantsInvoices(): void
    {
        $today = new \DateTimeImmutable('today');
        $this->invoice('D-'.substr($this->suffix, 0, 4).'-1', '10.000', $today, $today->modify('+10 days'));
        $this->invoice('D-'.substr($this->suffix, 0, 4).'-2', '20.000', $today, $today->modify('+10 days'), send: true);
        $this->invoice('D-'.substr($this->suffix, 0, 4).'-3', '30.000', $today->modify('-10 days'), $today->modify('-3 days'), send: true);
        $this->invoice('D-'.substr($this->suffix, 0, 4).'-4', '40.000', $today, $today->modify('+10 days'), send: true, paidAt: $today);
        $this->invoice('D-'.substr($this->suffix, 0, 4).'-5', '50.000', $today, $today->modify('+10 days'), send: true, paidAt: $today->modify('-2 months'));
        $this->entityManager->flush();

        $this->client->loginUser($this->principals['Viewer']);
        $this->client->request('GET', $this->path());
        self::assertResponseIsSuccessful();

        $payload = $this->payload();
        self::assertSame(50000, $payload['outstandingMillimes']);
        self::assertSame(30000, $payload['overdueMillimes']);
        self::assertSame(1, $payload['overdueCount']);
        self::assertSame(40000, $payload['paidThisMonthMillimes']);
        // One of the two paid invoices was collected this month, the other two months ago.
        self::assertSame(1, $payload['paidThisMonthCount']);
        // All five were issued today, so two of this month's five are paid.
        self::assertSame(5, $payload['issuedThisMonthCount']);
        self::assertSame(40, $payload['paidRateThisMonthPercent']);
        self::assertSame(1, $payload['customerCount']);
        self::assertSame(['draft' => 1, 'sent' => 1, 'overdue' => 1, 'paid' => 2], $payload['statusCounts']);
        self::assertCount(12, $payload['revenueByMonth']);
        self::assertSame(
            ['month' => (new \DateTimeImmutable('today'))->format('Y-m'), 'paidMillimes' => 40000],
            $payload['revenueByMonth'][11],
        );
        self::assertSame(50000, $payload['revenueByMonth'][9]['paidMillimes']);
        self::assertSame(0, $payload['revenueByMonth'][8]['paidMillimes']);
        self::assertLessThanOrEqual(5, count($payload['recentInvoices']));
    }

    public function testAuthorizationIsTheMembership(): void
    {
        $this->client->loginUser($this->principals['Outsider']);
        $this->client->request('GET', $this->path());
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->principals['Owner']);
        $this->client->request('GET', '/api/organizations/not-a-uuid/dashboard');
        self::assertResponseStatusCodeSame(400);

        // restart() drops the session and the cookies, so the same browser
        // fixture can make the anonymous request.
        $this->client->restart();
        $this->client->request('GET', $this->path());
        self::assertResponseStatusCodeSame(401);
    }

    public function testTheDashboardCostsTheSameForManyInvoicesAsForFew(): void
    {
        $today = new \DateTimeImmutable('today');
        for ($i = 0; $i < 3; ++$i) {
            $this->invoice('small-'.$this->suffix.'-'.$i, '5.000', $today, $today->modify('+5 days'), send: true);
        }
        $this->entityManager->flush();

        $this->client->loginUser($this->principals['Owner']);
        // Warm up: the first authenticated request pays for metadata and the
        // session user, which are not what this test is about.
        $this->client->request('GET', $this->path());
        self::assertResponseIsSuccessful();

        CountingConnection::reset();
        $this->client->request('GET', $this->path());
        self::assertResponseIsSuccessful();
        $small = CountingConnection::count();

        for ($i = 0; $i < 30; ++$i) {
            $this->invoice('large-'.$this->suffix.'-'.$i, '7.000', $today, $today->modify('+5 days'), send: true);
        }
        $this->entityManager->flush();

        CountingConnection::reset();
        $this->client->request('GET', $this->path());
        self::assertResponseIsSuccessful();
        $large = CountingConnection::count();
        CountingConnection::stop();

        // Same query count at 5x the rows: no per-row query.
        self::assertSame($small, $large, sprintf('dashboard used %d queries for 3 invoices and %d for 33', $small, $large));
    }

    /**
     * 250 invoices over 14 months: every figure must equal the direct computation.
     *
     * The audit found the SPA summing the first 100 invoices of a list, so anything
     * past number 101 was invisible to the dashboard. This test builds a corpus
     * large enough to prove the server aggregate is the authority: expectations are
     * accumulated while the invoices are built, using the same predicates the SQL
     * uses (`overdue` is a due date in the past, not a stored flag), and every
     * returned figure is compared to them. Two of the months fall outside the
     * twelve-month revenue window on purpose.
     */
    public function testEveryFigureOfA250InvoiceCorpusOver14Months(): void
    {
        $today = new \DateTimeImmutable('today');
        $monthStart = $today->modify('first day of this month')->setTime(0, 0);
        $thisMonth = $monthStart->format('Y-m');
        $revenueStart = $monthStart->modify('-11 months');

        $organization = $this->entityManager->find(Organization::class, $this->organization->getId());
        $customer = $this->entityManager->find(Customer::class, $this->customer->getId());
        self::assertInstanceOf(Organization::class, $organization);
        self::assertInstanceOf(Customer::class, $customer);

        $statusCounts = ['draft' => 0, 'sent' => 0, 'overdue' => 0, 'paid' => 0];
        $outstanding = 0;
        $overdue = 0;
        $paidThisMonth = 0;
        $paidThisMonthCount = 0;
        $issuedThisMonth = 0;
        $paidOfIssuedThisMonth = 0;
        /** @var array<string, int> $revenueByMonth */
        $revenueByMonth = [];
        for ($offset = 0; $offset < 12; ++$offset) {
            $revenueByMonth[$revenueStart->modify(sprintf('+%d months', $offset))->format('Y-m')] = 0;
        }

        for ($index = 0; $index < 250; ++$index) {
            $issueDate = $monthStart->modify(sprintf('-%d months', $index % 14))->modify(sprintf('+%d days', $index % 20));
            if ($issueDate > $today) {
                // Nothing is issued in the future; clamping keeps the corpus realistic
                // without making the expectations depend on the day the suite runs.
                $issueDate = $today;
            }
            $dueDate = $issueDate->modify('+14 days');
            $millimes = 1000 + $index * 37;
            $total = sprintf('%d.%03d', intdiv($millimes, 1000), $millimes % 1000);
            $kind = $index % 4; // draft, sent, sent, paid
            $isPaid = 3 === $kind;

            $invoice = new Invoice(
                $organization,
                $customer,
                sprintf('D250-%03d', $index),
                $this->principals['Owner']->getId(),
                $issueDate,
                $dueDate,
                [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => $total, 'lineTotal' => $total]],
                null,
                null,
                $total,
                '0.000',
                $total,
                $issueDate,
            );
            $this->entityManager->persist($invoice);

            if (0 === $kind) {
                ++$statusCounts['draft'];
            } else {
                $invoice->send($issueDate);
                if ($isPaid) {
                    $invoice->markPaid($issueDate);
                    ++$statusCounts['paid'];
                    if (isset($revenueByMonth[$issueDate->format('Y-m')])) {
                        $revenueByMonth[$issueDate->format('Y-m')] += $millimes;
                    }
                    // `paid_at` is the issue date here, so "this month" is an issue-month test.
                    if ($thisMonth === $issueDate->format('Y-m')) {
                        $paidThisMonth += $millimes;
                        ++$paidThisMonthCount;
                        ++$paidOfIssuedThisMonth;
                    }
                } elseif ($dueDate < $today) {
                    ++$statusCounts['overdue'];
                    $overdue += $millimes;
                } else {
                    ++$statusCounts['sent'];
                    $outstanding += $millimes;
                }
            }

            if ($thisMonth === $issueDate->format('Y-m')) {
                ++$issuedThisMonth;
            }
        }
        $this->entityManager->flush();

        $this->client->loginUser($this->principals['Owner']);
        $this->client->request('GET', $this->path());
        self::assertResponseIsSuccessful();
        $payload = $this->payload();

        self::assertSame($outstanding + $overdue, $payload['outstandingMillimes']);
        self::assertSame($overdue, $payload['overdueMillimes']);
        self::assertSame($statusCounts['overdue'], $payload['overdueCount']);
        self::assertSame($statusCounts, $payload['statusCounts']);
        self::assertSame($paidThisMonth, $payload['paidThisMonthMillimes']);
        self::assertSame($paidThisMonthCount, $payload['paidThisMonthCount']);
        self::assertSame($issuedThisMonth, $payload['issuedThisMonthCount']);
        self::assertSame(
            0 === $issuedThisMonth ? 0 : (int) round($paidOfIssuedThisMonth * 100 / $issuedThisMonth),
            $payload['paidRateThisMonthPercent'],
        );
        self::assertSame(1, $payload['customerCount']);
        self::assertSame($revenueByMonth, array_column($payload['revenueByMonth'], 'paidMillimes', 'month'));
        self::assertCount(5, $payload['recentInvoices']);

        // A foreign tenant's owner must not read this dashboard: 404, never 403.
        $foreignOrganization = new Organization('Dash Foreign '.$this->suffix, 'dash-foreign-'.$this->suffix);
        $foreignOwner = new Principal('dash-foreign-'.$this->suffix.'@example.test', 'Foreign Owner');
        $this->principals['ForeignOwner'] = $foreignOwner;
        $this->entityManager->persist($foreignOrganization);
        $this->entityManager->persist($foreignOwner);
        $this->entityManager->persist(new Membership($foreignOrganization, $foreignOwner, 'Owner'));
        $this->entityManager->flush();

        $this->client->loginUser($foreignOwner);
        $this->client->request('GET', $this->path());
        self::assertResponseStatusCodeSame(404);
    }

    private function invoice(string $number, string $total, \DateTimeImmutable $issueDate, \DateTimeImmutable $dueDate, bool $send = false, ?\DateTimeImmutable $paidAt = null): Invoice
    {
        // The kernel may have reset the manager between requests; resolve the
        // tenant and customer in the current unit of work.
        $organization = $this->entityManager->find(Organization::class, $this->organization->getId());
        $customer = $this->entityManager->find(Customer::class, $this->customer->getId());
        $invoice = new Invoice(
            $organization,
            $customer,
            $number,
            $this->principals['Owner']->getId(),
            $issueDate,
            $dueDate,
            [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => $total, 'lineTotal' => $total]],
            null,
            null,
            $total,
            '0.000',
            $total,
        );
        if ($send) {
            $invoice->send();
        }
        if (null !== $paidAt) {
            $invoice->markPaid($paidAt);
        }
        $this->entityManager->persist($invoice);

        return $invoice;
    }

    private function path(): string
    {
        return '/api/organizations/'.$this->organization->getId().'/dashboard';
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
