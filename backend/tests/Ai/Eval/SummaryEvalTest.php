<?php

declare(strict_types=1);

namespace App\Tests\Ai\Eval;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Repository\InvoiceRepository;
use App\Repository\MembershipRepository;
use App\Summary\GroundingCheck;
use App\Summary\SummaryFallback;
use App\Summary\SummaryKind;
use App\Summary\WorkspaceFacts;
use App\Summary\WorkspaceFactsBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The summary evaluation: one seeded organization, three kinds, the exact facts.
 *
 * Facts are the whole product surface of a summary — they are what the model is allowed
 * to say, what `GroundingCheck` accepts figures from, and what the API returns next to
 * the prose. So the evaluation pins them exactly: every count, every amount in millimes
 * and every date, by value and by type. A change in the aggregates that nobody meant is
 * a failing test here rather than a plausible sentence in production.
 *
 * The fixture is in `data/summaries.json` and is expressed in offsets from the day the
 * suite runs, so the expected numbers do not depend on the calendar date. One invoice is
 * pushed into the previous month, which is what separates month-to-date and seven-day
 * figures from all-time ones.
 *
 * Two more properties are asserted per kind: the deterministic template is itself
 * grounded (an outage must not produce a text the checker would reject), and the locale
 * and role the facts carry are the caller's.
 *
 * The class is in the `ai-eval` group for the same reason as the guide suite: it is the
 * data the real-provider workflow runs.
 */
#[Group('ai-eval')]
final class SummaryEvalTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private WorkspaceFactsBuilder $builder;
    private Organization $organization;
    private Principal $principal;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);

        $memberships = $this->entityManager->getRepository(Membership::class);
        self::assertInstanceOf(MembershipRepository::class, $memberships);
        $invoices = $this->entityManager->getRepository(Invoice::class);
        self::assertInstanceOf(InvoiceRepository::class, $invoices);

        $this->builder = new WorkspaceFactsBuilder($this->entityManager->getConnection(), $memberships, $invoices);
        $this->seed(EvalCorpus::summaries());
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $connection = $this->entityManager->getConnection();
            $organizationId = $this->organization->getId();
            $connection->executeStatement('DELETE FROM ai_requests WHERE organization_id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM invoices WHERE organization_id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM customers WHERE organization_id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM memberships WHERE organization_id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$organizationId]);
            $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$this->principal->getId()]);
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function kinds(): iterable
    {
        foreach (SummaryKind::cases() as $kind) {
            yield $kind->value => [$kind->value];
        }
    }

    #[DataProvider('kinds')]
    public function testEachKindProducesExactlyTheExpectedFacts(string $kind): void
    {
        $corpus = EvalCorpus::summaries();
        $expected = $corpus['expected'][$kind] ?? null;
        self::assertIsArray($expected, sprintf('summaries.json has no expectation for "%s".', $kind));

        $facts = $this->facts($kind);
        $today = new \DateTimeImmutable('today');

        self::assertSame($kind, $facts->kind->value);
        self::assertSame($corpus['role'], $facts->role);
        self::assertSame($corpus['locale'], $facts->locale);
        self::assertSame($corpus['currency'], $facts->currency);

        // Exact, ordered and typed: a stray millime or a stringified count fails.
        self::assertSame($expected['numbers'], $facts->numbers);
        self::assertSame($this->resolveDates($expected['dates'], $today), $facts->dates);
        self::assertSame($expected['customerNames'], $facts->customerNames);
        self::assertSame($expected['customerNames'], $facts->toArray()['customerNames']);
    }

    #[DataProvider('kinds')]
    public function testTheDeterministicTemplateIsGrounded(string $kind): void
    {
        $facts = $this->facts($kind);

        self::assertTrue(
            (new GroundingCheck())->verify((new SummaryFallback())->text($facts), $facts),
            'The fallback template must contain only figures the facts can derive.',
        );
    }

    /** No fact may carry anything a prompt could leak once the shape is widened. */
    public function testTheFactShapeCannotCarryContactDetailsOrFreeText(): void
    {
        foreach (SummaryKind::cases() as $kind) {
            $facts = $this->facts($kind->value);

            self::assertLessThanOrEqual(WorkspaceFacts::CUSTOMER_LIMIT, count($facts->customerNames));
            foreach ($facts->customerNames as $name) {
                self::assertStringNotContainsString('@', $name, 'A customer display name is not an address.');
            }
            foreach (array_keys($facts->toArray()) as $key) {
                self::assertNotContains($key, ['email', 'phone', 'billingAddress', 'note', 'lineItems', 'memberNames']);
            }
        }
    }

    /**
     * A whole-second `created_at` can tie, and the newest-invoice order must survive it.
     *
     * `invoices.created_at` is `timestamp(0)`, so two invoices written in the same second
     * share an instant; ordering on that column alone would leave their order to the
     * planner. `InvoiceRepository::findRecentForOrganization` breaks the tie on the primary
     * key, which makes the order total. This pins that secondary key: three invoices on one
     * instant must come back greatest-id first, and the same way on every read.
     */
    public function testRecentCustomerOrderBreaksTimestampTiesOnThePrimaryKey(): void
    {
        $instant = new \DateTimeImmutable('+1 hour');
        $ids = [];
        $index = 0;
        foreach (['Tie Alpha', 'Tie Beta', 'Tie Gamma'] as $name) {
            $customer = new Customer(
                $this->organization,
                $name,
                strtolower(str_replace(' ', '.', $name)).'@example.test',
                null,
                null,
                $instant,
            );
            $this->entityManager->persist($customer);
            $this->entityManager->flush();

            $invoice = new Invoice(
                $this->organization,
                $customer,
                'TIE-'.++$index,
                $this->principal->getId(),
                $instant,
                $instant,
                [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '1.000', 'lineTotal' => '1.000']],
                null,
                null,
                '1.000',
                '0.000',
                '1.000',
                $instant,
            );
            $this->entityManager->persist($invoice);
            $this->entityManager->flush();
            $ids[$name] = $invoice->getId();
        }

        // PostgreSQL compares uuids bytewise, which their canonical lowercase text form
        // preserves, so a plain string sort here is the same order as `id DESC`.
        $expected = array_keys($ids);
        usort($expected, static fn (string $left, string $right): int => strcmp($ids[$right], $ids[$left]));

        $facts = $this->facts('activity');
        self::assertSame($expected, array_slice($facts->customerNames, 0, 3));
        self::assertSame(
            $facts->customerNames,
            $this->facts('activity')->customerNames,
            'The newest-invoice order must not change between reads.',
        );
    }

    private function facts(string $kind): WorkspaceFacts
    {
        $summaryKind = SummaryKind::from($kind);

        return $this->builder->build($this->organization->getId(), $this->principal->getId(), $summaryKind, 'en');
    }

    /**
     * @param array<string, string> $dates
     *
     * @return array<string, string>
     */
    private function resolveDates(array $dates, \DateTimeImmutable $today): array
    {
        $resolved = [];
        foreach ($dates as $key => $value) {
            if ('today' === $value) {
                $resolved[$key] = $today->format('Y-m-d');

                continue;
            }
            if (1 === preg_match('/^today-(\d+)$/', $value, $matches)) {
                $resolved[$key] = $today->modify('-'.$matches[1].' days')->format('Y-m-d');

                continue;
            }
            $resolved[$key] = $value;
        }

        return $resolved;
    }

    /** @param array<string, mixed> $corpus */
    private function seed(array $corpus): void
    {
        $suffix = bin2hex(random_bytes(6));
        $today = new \DateTimeImmutable('today');
        $previousMonth = (new \DateTimeImmutable('first day of last month'))->setTime(12, 0);

        $this->organization = new Organization('Summary eval '.$suffix, 'summary-eval-'.$suffix);
        $this->principal = new Principal('summary-eval-'.$suffix.'@example.test', 'Summary Eval');
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->principal, (string) $corpus['role']));

        $customers = [];
        foreach ($corpus['customers'] as $definition) {
            $customer = new Customer(
                $this->organization,
                $definition['name'],
                // A contact address is deliberately present in the database: the eval
                // proves it never becomes a fact or a prompt field.
                strtolower(str_replace(' ', '.', $definition['name'])).'@example.test',
                null,
                null,
                (new \DateTimeImmutable())->modify(sprintf('-%d seconds', (int) $definition['ageSeconds'])),
            );
            $this->entityManager->persist($customer);
            $customers[$definition['name']] = $customer;
        }
        $this->entityManager->flush();

        $index = 0;
        // One clock for the whole corpus. `created_at` is a whole-second column, so
        // asking for "now" once per invoice let two consecutive invoices share an
        // instant whenever the loop crossed a second boundary; the newest-invoice
        // order then fell through to the uuid tiebreak, which differs on every run.
        $clock = new \DateTimeImmutable();
        foreach ($corpus['invoices'] as $definition) {
            ++$index;
            $total = (string) $definition['total'];
            $createdAt = $clock->modify(sprintf('-%d seconds', $index));
            $invoice = new Invoice(
                $this->organization,
                $customers[$definition['customer']],
                $definition['ref'],
                $this->principal->getId(),
                $today->modify('-30 days'),
                $today->modify(sprintf('%+d days', (int) $definition['dueOffsetDays'])),
                [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => $total, 'lineTotal' => $total]],
                null,
                null,
                $total,
                '0.000',
                $total,
                $createdAt,
            );

            $outsideWindow = true === ($definition['outsideWindow'] ?? false);
            if ('sent' === $definition['status'] || 'paid' === $definition['status']) {
                $invoice->send($outsideWindow ? $previousMonth : $createdAt);
            }
            if ('paid' === $definition['status']) {
                $invoice->markPaid($outsideWindow ? $previousMonth : $createdAt);
            }

            $this->entityManager->persist($invoice);
            $this->entityManager->flush();

            if ($outsideWindow) {
                // Created, sent and paid in the previous month: the only way to prove the
                // seven-day and month-to-date figures are windows rather than totals.
                $this->entityManager->getConnection()->executeStatement(
                    'UPDATE invoices SET created_at = ?, sent_at = ?, paid_at = ?, updated_at = ? WHERE id = ?',
                    [$previousMonth, $previousMonth, $previousMonth, $previousMonth, $invoice->getId()],
                    [
                        \Doctrine\DBAL\Types\Types::DATETIMETZ_IMMUTABLE,
                        \Doctrine\DBAL\Types\Types::DATETIMETZ_IMMUTABLE,
                        \Doctrine\DBAL\Types\Types::DATETIMETZ_IMMUTABLE,
                        \Doctrine\DBAL\Types\Types::DATETIMETZ_IMMUTABLE,
                    ],
                );
            }
        }
    }
}
