<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Invoice;

final class InvoiceSerializer
{
    /** @return array<string, mixed> */
    public function customer(Customer $customer, bool $includeAddress = true): array
    {
        $result = [
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhone(),
        ];
        if ($includeAddress) {
            $result['billingAddress'] = $customer->getBillingAddress();
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function invoice(Invoice $invoice): array
    {
        $status = $invoice->getStatus();
        if ('sent' === $status && $invoice->getDueDate() < new \DateTimeImmutable('today')) {
            $status = 'overdue';
        }

        return [
            'id' => $invoice->getId(),
            'number' => $invoice->getNumber(),
            'customerId' => $invoice->getCustomer()->getId(),
            'customer' => $invoice->getCustomerSnapshot(),
            'status' => $status,
            'issueDate' => $invoice->getIssueDate()->format('Y-m-d'),
            'dueDate' => $invoice->getDueDate()->format('Y-m-d'),
            'currency' => $invoice->getCurrency(),
            'lineItems' => $invoice->getLineItems(),
            'taxRate' => $invoice->getTaxRate(),
            'discountAmount' => $invoice->getDiscountAmount(),
            'subtotal' => $invoice->getSubtotal(),
            'taxAmount' => $invoice->getTaxAmount(),
            'total' => $invoice->getTotal(),
            'sentAt' => $invoice->getSentAt()?->format(DATE_ATOM),
            'paidAt' => $invoice->getPaidAt()?->format(DATE_ATOM),
        ];
    }
}
