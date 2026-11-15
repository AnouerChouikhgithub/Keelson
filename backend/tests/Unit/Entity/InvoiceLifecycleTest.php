<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Organization;
use PHPUnit\Framework\TestCase;

final class InvoiceLifecycleTest extends TestCase
{
    public function testSentInvoiceSnapshotsCustomerAndCanBeMarkedPaidIdempotently(): void
    {
        $organization = new Organization('Example', 'example');
        $customer = $this->customer($organization, 'Original name');
        $invoice = $this->invoice($organization, $customer, new \DateTimeImmutable('2026-10-01'));
        $sentAt = new \DateTimeImmutable('2026-10-10T10:00:00+00:00');

        self::assertTrue($invoice->send($sentAt));
        self::assertFalse($invoice->send(new \DateTimeImmutable('2026-10-11T10:00:00+00:00')));
        $customer->update('Updated name', null, null, null);
        self::assertSame('Original name', $invoice->getCustomerSnapshot()['name']);
        self::assertTrue($invoice->markOverdue(new \DateTimeImmutable('2026-10-02')));
        self::assertSame('overdue', $invoice->getStatus());
        self::assertTrue($invoice->markPaid(new \DateTimeImmutable('2026-10-12T10:00:00+00:00')));
        self::assertFalse($invoice->markPaid(new \DateTimeImmutable('2026-10-13T10:00:00+00:00')));
        self::assertSame('paid', $invoice->getStatus());
        self::assertSame('2026-10-12T10:00:00+00:00', $invoice->getPaidAt()?->format(DATE_ATOM));
        self::assertFalse($invoice->markOverdue(new \DateTimeImmutable('2026-12-01')));
    }

    public function testOverdueStatusIsDerivedForReadersWithoutChangingStoredState(): void
    {
        $organization = new Organization('Example', 'example');
        $invoice = $this->invoice($organization, $this->customer($organization), new \DateTimeImmutable('2026-10-31'));
        $invoice->send(new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));

        // Before the due date the invoice is simply sent.
        self::assertSame('sent', $invoice->effectiveStatus(new \DateTimeImmutable('2026-10-31')));
        // After it, readers see overdue while the stored status stays sent.
        self::assertSame('overdue', $invoice->effectiveStatus(new \DateTimeImmutable('2026-11-01')));
        self::assertSame('sent', $invoice->getStatus());
        // A paid invoice is never reported as overdue.
        $invoice->markPaid(new \DateTimeImmutable('2026-11-02T09:00:00+00:00'));
        self::assertSame('paid', $invoice->effectiveStatus(new \DateTimeImmutable('2026-12-01')));
    }

    public function testOverdueTransitionRequiresSentStatusAndAPastDueDate(): void
    {
        $organization = new Organization('Example', 'example');
        $invoice = $this->invoice($organization, $this->customer($organization), new \DateTimeImmutable('2026-10-31'));

        self::assertFalse($invoice->markOverdue(new \DateTimeImmutable('2026-11-01')), 'A draft invoice is never overdue.');
        self::assertSame('draft', $invoice->getStatus());
        $invoice->send(new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));
        self::assertFalse($invoice->markOverdue(new \DateTimeImmutable('2026-10-31')), 'The due date itself is not overdue.');
        self::assertSame('sent', $invoice->getStatus());
        self::assertTrue($invoice->markOverdue(new \DateTimeImmutable('2026-11-01')));
        self::assertSame('overdue', $invoice->getStatus());
        self::assertFalse($invoice->markOverdue(new \DateTimeImmutable('2026-11-02')), 'The transition is idempotent.');
    }

    public function testCannotEditSentInvoiceOrPayDraft(): void
    {
        $organization = new Organization('Example', 'example');
        $customer = $this->customer($organization);
        $invoice = $this->invoice($organization, $customer);
        $invoice->send();

        try {
            $invoice->updateDraft($customer, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-02'), [], null, null, '0.000', '0.000', '0.000');
            self::fail('Sent invoice should not be editable.');
        } catch (\DomainException) {
            self::assertSame('sent', $invoice->getStatus());
        }
    }

    public function testInvoiceRejectsCustomerFromDifferentOrganization(): void
    {
        $organizationA = new Organization('A', 'tenant-a');
        $organizationB = new Organization('B', 'tenant-b');
        $this->expectException(\InvalidArgumentException::class);
        $this->invoice($organizationA, $this->customer($organizationB));
    }

    private function customer(Organization $organization, string $name = 'Customer'): Customer
    {
        $customer = new Customer($organization, $name);
        (new \ReflectionProperty($customer, 'id'))->setValue($customer, bin2hex(random_bytes(16)));

        return $customer;
    }

    private function invoice(Organization $organization, Customer $customer, ?\DateTimeImmutable $dueDate = null): Invoice
    {
        return new Invoice(
            $organization,
            $customer,
            '2026-000001',
            '00000000-0000-4000-8000-000000000001',
            new \DateTimeImmutable('2026-10-01'),
            $dueDate ?? new \DateTimeImmutable('2026-10-31'),
            [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '10.000', 'lineTotal' => '10.000']],
            null,
            null,
            '10.000',
            '0.000',
            '10.000',
        );
    }
}
