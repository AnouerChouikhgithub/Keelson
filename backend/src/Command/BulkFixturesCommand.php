<?php

declare(strict_types=1);

namespace App\Command;

use App\DataFixtures\FixtureTargetGuard;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Development-only bulk data, for measuring list endpoints at a realistic size.
 *
 * It writes straight through DBAL in batches instead of the ORM: 11,000 managed
 * entities would spend minutes in the identity map for data that no test will
 * ever load as objects. The safety gate is the same one `app:fixtures:load`
 * uses — production, a non-PostgreSQL driver, a non-loopback host or a database
 * name that does not look local are all refused before anything is written.
 *
 * Rows are keyed so the command is idempotent: customers are named
 * `Bulk Customer NNNN` and invoices numbered from 100001 per year, so a second
 * run tops the table up to the requested size instead of duplicating it.
 */
#[AsCommand(
    name: 'app:fixtures:bulk',
    description: 'Add bulk customers, invoices and audit events to a local test/dev database (performance work only).',
)]
final class BulkFixturesCommand extends Command
{
    private const BATCH_SIZE = 500;
    private const CUSTOMER_PREFIX = 'Bulk Customer ';
    private const FIRST_INVOICE_SEQUENCE = 100001;

    public function __construct(
        private readonly Connection $connection,
        private readonly FixtureTargetGuard $targetGuard,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('customers', null, InputOption::VALUE_REQUIRED, 'Total bulk customers to have in the database.', '1000')
            ->addOption('invoices', null, InputOption::VALUE_REQUIRED, 'Total bulk invoices to have in the database.', '10000')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the interactive confirmation (still refuses unsafe targets).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $target = $this->targetGuard->describe($this->connection->getParams(), $this->environment);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $customers = max(0, (int) $input->getOption('customers'));
        $invoices = max(0, (int) $input->getOption('invoices'));

        $io->note(sprintf('Bulk fixture target: %s', $target));
        $io->text(sprintf('Adding up to %d customers and %d invoices (with one audit event each). Existing rows are kept.', $customers, $invoices));

        if (!$input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error('Refusing to write bulk fixtures without --force in a non-interactive shell.');

                return Command::FAILURE;
            }
            if (!$io->confirm('Write bulk performance data into this database?', false)) {
                $io->warning('Aborted; nothing was written.');

                return Command::SUCCESS;
            }
        }

        $organization = $this->connection->fetchAssociative(
            "SELECT id, name FROM organizations WHERE status = 'active' ORDER BY (subdomain = 'fixture-northwind') DESC, created_at ASC LIMIT 1",
        );
        if (false === $organization) {
            $io->error('No active organization found. Run `php bin/console app:fixtures:load --force` first.');

            return Command::FAILURE;
        }

        $creator = $this->connection->fetchOne(
            "SELECT principal_id FROM memberships WHERE organization_id = ? AND status = 'active' LIMIT 1",
            [$organization['id']],
        );
        if (false === $creator) {
            $io->error('The organization has no active membership to attribute invoices to.');

            return Command::FAILURE;
        }

        $started = microtime(true);
        $createdCustomers = $this->seedCustomers((string) $organization['id'], $customers);
        $createdInvoices = $this->seedInvoices((string) $organization['id'], (string) $creator, $invoices);
        $elapsed = microtime(true) - $started;

        $io->table(
            ['Record', 'Added now', 'Target'],
            [
                ['customers', (string) $createdCustomers, (string) $customers],
                ['invoices', (string) $createdInvoices, (string) $invoices],
                ['invoice audit events', (string) $createdInvoices, (string) $invoices],
            ],
        );
        $io->success(sprintf('Bulk data ready in %s in %.1f s.', $target, $elapsed));

        return Command::SUCCESS;
    }

    private function seedCustomers(string $organizationId, int $target): int
    {
        $existing = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM customers WHERE organization_id = ? AND name LIKE ?',
            [$organizationId, self::CUSTOMER_PREFIX.'%'],
        );
        if ($existing >= $target) {
            return 0;
        }

        $now = new \DateTimeImmutable();
        $created = 0;
        for ($batchStart = $existing; $batchStart < $target; $batchStart += self::BATCH_SIZE) {
            $batchEnd = min($batchStart + self::BATCH_SIZE, $target);
            $rows = [];
            $parameters = [];
            for ($index = $batchStart; $index < $batchEnd; ++$index) {
                $rows[] = '(?, ?, ?, ?, ?, ?::json, ?, ?)';
                $parameters[] = $this->uuid();
                $parameters[] = $organizationId;
                $parameters[] = self::CUSTOMER_PREFIX.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
                $parameters[] = sprintf('bulk-%04d@example.test', $index);
                $parameters[] = null;
                $parameters[] = json_encode([
                    'street' => sprintf('%d Rue du Port', 1 + $index % 300),
                    'city' => 'Tunis',
                    'postalCode' => '1000',
                    'countryCode' => 'TN',
                ], JSON_THROW_ON_ERROR);
                $parameters[] = $now->format('Y-m-d H:i:sP');
                $parameters[] = $now->format('Y-m-d H:i:sP');
            }

            $this->connection->executeStatement(
                'INSERT INTO customers (id, organization_id, name, email, phone, billing_address, created_at, updated_at) VALUES '.implode(', ', $rows),
                $parameters,
            );
            $created += $batchEnd - $batchStart;
        }

        return $created;
    }

    private function seedInvoices(string $organizationId, string $creator, int $target): int
    {
        $year = (new \DateTimeImmutable('today'))->format('Y');
        $prefix = $year.'-';
        $existing = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM invoices WHERE organization_id = ? AND number LIKE ? AND number >= ?',
            [$organizationId, $prefix.'%', $prefix.sprintf('%06d', self::FIRST_INVOICE_SEQUENCE)],
        );
        if ($existing >= $target) {
            return 0;
        }

        // The bulk invoices hang off the bulk customers, one customer per row, so
        // the list's join and the per-tenant scoping both see realistic data.
        $customerNames = $this->connection->fetchAllAssociative(
            'SELECT id, name, email FROM customers WHERE organization_id = ? AND name LIKE ? ORDER BY name',
            [$organizationId, self::CUSTOMER_PREFIX.'%'],
        );
        if ([] === $customerNames) {
            return 0;
        }

        $today = new \DateTimeImmutable('today');
        $created = 0;
        for ($batchStart = $existing; $batchStart < $target; $batchStart += self::BATCH_SIZE) {
            $batchEnd = min($batchStart + self::BATCH_SIZE, $target);
            $rows = [];
            $parameters = [];
            $audits = [];
            $auditParameters = [];

            for ($index = $batchStart; $index < $batchEnd; ++$index) {
                $customer = $customerNames[$index % count($customerNames)];
                $invoiceId = $this->uuid();
                $issuedAt = $today->modify(sprintf('-%d days', $index % 365));
                // A realistic mix so status filters and the dashboard see data in
                // every state: draft, sent, overdue and paid in a 4-way cycle.
                $status = ['draft', 'sent', 'overdue', 'paid'][$index % 4];
                $subtotal = 100.0 + ($index % 900) * 1.25;
                $taxRate = 19.0;
                $taxAmount = round($subtotal * $taxRate / 100, 3);
                $total = round($subtotal + $taxAmount, 3);
                $sentAt = 'draft' === $status ? null : $issuedAt->format('Y-m-d H:i:sP');
                $paidAt = 'paid' === $status ? $issuedAt->modify('+3 days')->format('Y-m-d H:i:sP') : null;

                $rows[] = '(?, ?, ?, ?, ?, ?::json, ?, ?, ?::json, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $parameters,
                    $invoiceId,
                    $organizationId,
                    $customer['id'],
                    $prefix.sprintf('%06d', self::FIRST_INVOICE_SEQUENCE + $index),
                    $status,
                    json_encode([
                        'id' => $customer['id'],
                        'name' => $customer['name'],
                        'email' => $customer['email'],
                        'phone' => null,
                    ], JSON_THROW_ON_ERROR),
                    $issuedAt->format('Y-m-d'),
                    $issuedAt->modify('+30 days')->format('Y-m-d'),
                    json_encode([[
                        'description' => sprintf('Bulk consulting line %d', $index + 1),
                        'quantity' => '1.000',
                        'unitPrice' => number_format($subtotal, 3, '.', ''),
                        'lineTotal' => number_format($subtotal, 3, '.', ''),
                    ]], JSON_THROW_ON_ERROR),
                    number_format($taxRate, 4, '.', ''),
                    null,
                    number_format($subtotal, 3, '.', ''),
                    number_format($taxAmount, 3, '.', ''),
                    number_format($total, 3, '.', ''),
                    'TND',
                    $creator,
                    $issuedAt->format('Y-m-d H:i:sP'),
                    $issuedAt->format('Y-m-d H:i:sP'),
                    $sentAt,
                );
                // The last placeholder is paid_at; sent_at was pushed above it.
                $parameters[] = $paidAt;

                $audits[] = '(?, ?, ?, ?, ?, ?, ?::json, ?)';
                array_push(
                    $auditParameters,
                    $this->uuid(),
                    $organizationId,
                    $invoiceId,
                    $customer['id'],
                    'invoice.created',
                    $creator,
                    '{}',
                    $issuedAt->format('Y-m-d H:i:sP'),
                );
            }

            $this->connection->executeStatement(
                'INSERT INTO invoices (id, organization_id, customer_id, number, status, customer_snapshot, issue_date, due_date, line_items, tax_rate, discount_amount, subtotal, tax_amount, total, currency, created_by_principal_id, created_at, updated_at, sent_at, paid_at) VALUES '.implode(', ', $rows),
                $parameters,
            );
            $this->connection->executeStatement(
                'INSERT INTO invoice_audit_events (id, organization_id, invoice_id, customer_id, action, actor_principal_id, metadata, created_at) VALUES '.implode(', ', $audits),
                $auditParameters,
            );
            $created += $batchEnd - $batchStart;
        }

        return $created;
    }

    /** Warehouse bulk rows have no natural key of their own, so they carry a v4 id. */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
