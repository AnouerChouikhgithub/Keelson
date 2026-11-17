<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\InvoiceAuditEvent;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Exception\ApiException;
use App\Http\JsonBody;
use App\Repository\CustomerRepository;
use App\Repository\InvoiceAuditEventRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrganizationRepository;
use App\Security\InvoiceVoter;
use App\Service\InvoiceAuditLogger;
use App\Service\InvoiceCalculator;
use App\Service\InvoiceInput;
use App\Service\InvoiceSerializer;
use App\Service\NotificationService;
use App\Service\TransactionBoundary;
use App\Util\Cursor;
use App\Util\Uuid;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The tenant-scoped customer and invoice API.
 *
 * Failures are thrown as {@see ApiException}: the response envelope is built in
 * one place by the API exception listener, so this controller never assembles a
 * status/code/message triple itself. CSRF is enforced for every unsafe request
 * by the global request listener, not per action.
 */
#[Route('/api/organizations/{organizationId}')]
final class InvoiceApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrganizationRepository $organizations,
        private readonly CustomerRepository $customers,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceAuditEventRepository $auditEvents,
        private readonly InvoiceCalculator $calculator,
        private readonly InvoiceAuditLogger $audits,
        private readonly InvoiceInput $invoiceInput,
        private readonly InvoiceSerializer $serializer,
        private readonly TransactionBoundary $transactions,
        private readonly NotificationService $notifications,
    ) {
    }

    #[Route('/customers', name: 'api_customers_list', methods: ['GET'])]
    public function listCustomers(string $organizationId, Request $request): JsonResponse
    {
        $this->assertIds($organizationId);
        if (!$this->isGranted(InvoiceVoter::READ, $organizationId)) {
            throw ApiException::notFound();
        }
        $principal = $this->principal();
        $limit = $this->limit($request);
        $rows = $this->customers->findForOrganizationList(
            $organizationId,
            $principal->getId(),
            $request->query->get('q'),
            Cursor::decode($request->query->get('cursor')),
            $limit,
        );
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $lastCustomer = $hasMore ? $rows[array_key_last($rows)] : null;
        $nextCursor = null !== $lastCustomer ? Cursor::encode($lastCustomer->getCreatedAt(), $lastCustomer->getId()) : null;

        return $this->json(['items' => array_map(fn (Customer $customer) => $this->serializer->customer($customer, false), $rows), 'nextCursor' => $nextCursor]);
    }

    #[Route('/customers', name: 'api_customers_create', methods: ['POST'])]
    public function createCustomer(string $organizationId, Request $request): JsonResponse
    {
        $this->assertIds($organizationId);
        if (!$this->isGranted(InvoiceVoter::WRITE, $organizationId)) {
            throw ApiException::notFound();
        }
        $principal = $this->principal();
        $data = $this->customerPayload($request);

        try {
            return $this->transactions->run(function () use ($organizationId, $principal, $data): JsonResponse {
                $organization = $this->organizations->findActiveForPrincipalInOrganization($organizationId, $principal->getId());
                if (null === $organization) {
                    throw ApiException::notFound();
                }
                $customer = new Customer($organization, $data['name'] ?? '', $data['email'] ?? null, $data['phone'] ?? null, $data['billingAddress'] ?? null);
                $this->entityManager->persist($customer);
                // Business row and audit event share one flush and one transaction:
                // a failed audit insert rolls the customer back with it.
                $this->audit($organization, $principal, 'customer.created', null, $customer->getId());
                $this->entityManager->flush();

                return $this->json(['customer' => $this->serializer->customer($customer)], Response::HTTP_CREATED);
            });
        } catch (\InvalidArgumentException $e) {
            throw ApiException::validationFailed($e->getMessage());
        } catch (\TypeError) {
            // Well-formed JSON carrying the wrong type must not become a 500.
            throw ApiException::validationFailed('The request contains fields of the wrong type.');
        }
    }

    #[Route('/customers/{customerId}', name: 'api_customers_read', methods: ['GET'])]
    public function readCustomer(string $organizationId, string $customerId): JsonResponse
    {
        $this->assertIds($organizationId, $customerId);
        if (!$this->isGranted(InvoiceVoter::READ, $organizationId)) {
            throw ApiException::notFound();
        }
        $principal = $this->principal();
        $customer = $this->customers->findForOrganization($organizationId, $customerId, $principal->getId());
        if (null === $customer) {
            throw ApiException::notFound();
        }

        return $this->json(['customer' => $this->serializer->customer($customer)]);
    }

    #[Route('/customers/{customerId}', name: 'api_customers_update', methods: ['PATCH'])]
    public function updateCustomer(string $organizationId, string $customerId, Request $request): JsonResponse
    {
        $this->assertIds($organizationId, $customerId);
        if (!$this->isGranted(InvoiceVoter::WRITE, $organizationId)) {
            throw ApiException::notFound();
        }
        $principal = $this->principal();
        $data = $this->customerPayload($request);

        try {
            return $this->transactions->run(function () use ($organizationId, $customerId, $principal, $data): JsonResponse {
                $customer = $this->customers->findForOrganization($organizationId, $customerId, $principal->getId());
                if (null === $customer) {
                    throw ApiException::notFound();
                }
                $allowedFields = ['name', 'email', 'phone', 'billingAddress'];
                if ([] === array_intersect(array_keys($data), $allowedFields) || array_diff(array_keys($data), $allowedFields)) {
                    throw ApiException::badRequest('Customer patch contains no mutable fields or unsupported fields.');
                }
                $customer->update(
                    $data['name'] ?? $customer->getName(),
                    array_key_exists('email', $data) ? $data['email'] : $customer->getEmail(),
                    array_key_exists('phone', $data) ? $data['phone'] : $customer->getPhone(),
                    array_key_exists('billingAddress', $data) ? $data['billingAddress'] : $customer->getBillingAddress(),
                );
                $this->audit($customer->getOrganization(), $principal, 'customer.updated', null, $customerId);
                $this->entityManager->flush();

                return $this->json(['customer' => $this->serializer->customer($customer)]);
            });
        } catch (\InvalidArgumentException $e) {
            throw ApiException::validationFailed($e->getMessage());
        } catch (\TypeError) {
            // Well-formed JSON carrying the wrong type must not become a 500.
            throw ApiException::validationFailed('The request contains fields of the wrong type.');
        }
    }

    #[Route('/customers/{customerId}', name: 'api_customers_delete', methods: ['DELETE'])]
    public function deleteCustomer(string $organizationId, string $customerId, Request $request): Response
    {
        $this->assertIds($organizationId, $customerId);
        if (!$this->isGranted(InvoiceVoter::DELETE_DRAFT, $organizationId)) {
            throw ApiException::notFound();
        }
        $principal = $this->principal();

        try {
            return $this->transactions->run(function () use ($organizationId, $customerId, $principal): Response {
                // Lock before checking: PostgreSQL holds a FOR KEY SHARE lock on
                // this row for every invoice insert, and FOR UPDATE conflicts with
                // it, so a concurrent invoice cannot appear between the check and
                // the delete. A customer created concurrently cannot race either,
                // because the id is already known to be visible.
                $customer = $this->customers->findForOrganizationForUpdate($organizationId, $customerId, $principal->getId());
                if (null === $customer) {
                    throw ApiException::notFound();
                }
                if ($this->customers->hasInvoices($organizationId, $customerId)) {
                    throw ApiException::conflict('Customer is referenced by invoices.');
                }
                $this->entityManager->remove($customer);
                $this->audit($customer->getOrganization(), $principal, 'customer.deleted', null, $customerId);
                $this->entityManager->flush();

                return new Response(null, Response::HTTP_NO_CONTENT);
            });
        } catch (ForeignKeyConstraintViolationException) {
            // Defence in depth for a reference that still wins the race (for
            // example a foreign key added outside this handler): the delete and
            // its audit event were rolled back together.
            throw ApiException::conflict('Customer is referenced by invoices.');
        }
    }

    #[Route('/invoices', name: 'api_invoices_list', methods: ['GET'])]
    public function listInvoices(string $organizationId, Request $request): JsonResponse
    {
        $this->assertIds($organizationId);
        if (!$this->isGranted(InvoiceVoter::READ, $organizationId)) {
            throw ApiException::notFound();
        }
        $principal = $this->principal();
        $status = $request->query->get('status');
        if (null !== $status && !in_array($status, ['draft', 'sent', 'paid', 'overdue'], true)) {
            throw ApiException::badRequest('Invoice status filter is invalid.');
        }
        $limit = $this->limit($request);
        $cursor = Cursor::decode($request->query->get('cursor'));
        // Read-only: overdue is derived in the query filter and in the
        // serializer. A GET must not write status or audit rows, because that
        // could overwrite a concurrent payment.
        $rows = $this->invoices->findForOrganizationList($organizationId, $principal->getId(), $status, $limit, $cursor, $request->query->get('q'));
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $lastInvoice = $hasMore ? $rows[array_key_last($rows)] : null;
        $nextCursor = null !== $lastInvoice ? Cursor::encode($lastInvoice->getCreatedAt(), $lastInvoice->getId()) : null;

        return $this->json(['items' => array_map($this->serializer->invoice(...), $rows), 'nextCursor' => $nextCursor]);
    }

    #[Route('/invoices', name: 'api_invoices_create', methods: ['POST'])]
    public function createInvoice(string $organizationId, Request $request): JsonResponse
    {
        $this->assertIds($organizationId);
        if (!$this->isGranted(InvoiceVoter::WRITE, $organizationId)) {
            throw ApiException::notFound();
        }
        $principal = $this->principal();
        $data = $this->invoicePayload($request);

        try {
            return $this->transactions->run(function () use ($organizationId, $principal, $data): JsonResponse {
                $parsed = $this->invoiceInput->parse($data, $this->calculator);
                $customer = $this->customers->findForOrganization($organizationId, $parsed['customerId'], $principal->getId());
                if (null === $customer) {
                    throw ApiException::notFound();
                }
                $calculation = $parsed['calculation'];
                $organization = $this->organizations->findActiveForPrincipalInOrganization($organizationId, $principal->getId());
                if (null === $organization) {
                    throw ApiException::notFound();
                }
                // Number allocation takes a transaction-scoped lock, so it must
                // run inside this unit of work; the unique index stays as the
                // last line of defence.
                $invoice = new Invoice(
                    $organization, $customer, $this->invoices->nextNumber($organizationId),
                    $principal->getId(), $parsed['issueDate'], $parsed['dueDate'], $calculation['lineItems'],
                    $calculation['taxRate'], $calculation['discountAmount'], $calculation['subtotal'],
                    $calculation['taxAmount'], $calculation['total'],
                );
                $this->entityManager->persist($invoice);
                $this->audit($organization, $principal, 'invoice.created', $invoice->getId(), $customer->getId());
                $this->entityManager->flush();

                return $this->json(['invoice' => $this->serializer->invoice($invoice)], Response::HTTP_CREATED);
            });
        } catch (\InvalidArgumentException $e) {
            throw ApiException::validationFailed($e->getMessage());
        } catch (\TypeError) {
            // Well-formed JSON carrying the wrong type must not become a 500.
            throw ApiException::validationFailed('The request contains fields of the wrong type.');
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('Invoice number allocation conflicted; retry the request.');
        }
    }

    #[Route('/invoices/{invoiceId}', name: 'api_invoices_read', methods: ['GET'])]
    public function readInvoice(string $organizationId, string $invoiceId): JsonResponse
    {
        $this->assertIds($organizationId, $invoiceId);
        if (!$this->isGranted(InvoiceVoter::READ, $organizationId)) {
            throw ApiException::notFound();
        }
        $principal = $this->principal();
        $invoice = $this->invoices->findForOrganization($organizationId, $invoiceId, $principal->getId());
        if (null === $invoice) {
            throw ApiException::notFound();
        }

        return $this->json(['invoice' => $this->serializer->invoice($invoice)]);
    }

    #[Route('/invoices/{invoiceId}', name: 'api_invoices_update', methods: ['PATCH'])]
    public function updateInvoice(string $organizationId, string $invoiceId, Request $request): JsonResponse
    {
        $this->assertIds($organizationId, $invoiceId);
        if (!$this->isGranted(InvoiceVoter::WRITE, $organizationId)) {
            throw ApiException::notFound();
        }
        $principal = $this->principal();
        $payload = $this->invoicePayload($request);

        try {
            return $this->transactions->run(function () use ($organizationId, $invoiceId, $principal, $payload): JsonResponse {
                // Lock the row before reading it: a concurrent send must never be overwritten by a stale draft edit.
                $invoice = $this->invoices->findForOrganizationForUpdate($organizationId, $invoiceId, $principal->getId());
                if (null === $invoice) {
                    throw ApiException::notFound();
                }
                if ('draft' !== $invoice->getStatus()) {
                    throw ApiException::conflict('Only draft invoices may be edited.');
                }
                $allowedFields = ['customerId', 'issueDate', 'dueDate', 'currency', 'lineItems', 'taxRate', 'discountAmount'];
                if ([] === array_intersect(array_keys($payload), $allowedFields) || array_diff(array_keys($payload), $allowedFields)) {
                    throw ApiException::badRequest('Invoice patch contains no mutable fields or unsupported fields.');
                }
                $payload += [
                    'customerId' => $invoice->getCustomer()->getId(),
                    'issueDate' => $invoice->getIssueDate()->format('Y-m-d'),
                    'dueDate' => $invoice->getDueDate()->format('Y-m-d'),
                    'currency' => $invoice->getCurrency(),
                    'lineItems' => array_map(static fn (array $line) => ['description' => $line['description'], 'quantity' => $line['quantity'], 'unitPrice' => $line['unitPrice']], $invoice->getLineItems()),
                    'taxRate' => $invoice->getTaxRate(),
                    'discountAmount' => $invoice->getDiscountAmount(),
                ];
                $parsed = $this->invoiceInput->parse($payload, $this->calculator);
                $customer = $this->customers->findForOrganization($organizationId, $parsed['customerId'], $principal->getId());
                if (null === $customer) {
                    throw ApiException::notFound();
                }
                $calculation = $parsed['calculation'];
                $invoice->updateDraft($customer, $parsed['issueDate'], $parsed['dueDate'], $calculation['lineItems'], $calculation['taxRate'], $calculation['discountAmount'], $calculation['subtotal'], $calculation['taxAmount'], $calculation['total']);
                $this->audit($invoice->getOrganization(), $principal, 'invoice.updated', $invoiceId, $customer->getId());
                $this->entityManager->flush();

                return $this->json(['invoice' => $this->serializer->invoice($invoice)]);
            });
        } catch (\DomainException $e) {
            throw ApiException::conflict($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            throw ApiException::validationFailed($e->getMessage());
        } catch (\TypeError) {
            // Well-formed JSON carrying the wrong type must not become a 500.
            throw ApiException::validationFailed('The request contains fields of the wrong type.');
        }
    }

    #[Route('/invoices/{invoiceId}/send', name: 'api_invoices_send', methods: ['POST'])]
    public function sendInvoice(string $organizationId, string $invoiceId, Request $request): JsonResponse
    {
        $this->assertIds($organizationId, $invoiceId);

        return $this->transitionInvoice($organizationId, $invoiceId, 'send');
    }

    #[Route('/invoices/{invoiceId}/mark-paid', name: 'api_invoices_paid', methods: ['POST'])]
    public function markInvoicePaid(string $organizationId, string $invoiceId, Request $request): JsonResponse
    {
        $this->assertIds($organizationId, $invoiceId);

        return $this->transitionInvoice($organizationId, $invoiceId, 'paid');
    }

    #[Route('/invoices/{invoiceId}', name: 'api_invoices_delete_draft', methods: ['DELETE'])]
    public function deleteDraftInvoice(string $organizationId, string $invoiceId, Request $request): Response
    {
        $this->assertIds($organizationId, $invoiceId);
        if (!$this->isGranted(InvoiceVoter::DELETE_DRAFT, $organizationId)) {
            throw ApiException::notFound();
        }
        $principal = $this->principal();

        return $this->transactions->run(function () use ($organizationId, $invoiceId, $principal): Response {
            // Same lock-and-recheck rule as edits: never delete an invoice a concurrent send just committed.
            $invoice = $this->invoices->findForOrganizationForUpdate($organizationId, $invoiceId, $principal->getId());
            if (null === $invoice) {
                throw ApiException::notFound();
            }
            if ('draft' !== $invoice->getStatus()) {
                throw ApiException::conflict('Only draft invoices may be deleted.');
            }
            $this->entityManager->remove($invoice);
            $this->audit($invoice->getOrganization(), $principal, 'invoice.deleted', $invoiceId);
            $this->entityManager->flush();

            return new Response(null, Response::HTTP_NO_CONTENT);
        });
    }

    #[Route('/invoice-audit-events', name: 'api_invoice_audit_list', methods: ['GET'])]
    public function listAuditEvents(string $organizationId, Request $request): JsonResponse
    {
        $this->assertIds($organizationId);
        if (!$this->isGranted(InvoiceVoter::READ, $organizationId)) {
            throw ApiException::notFound();
        }
        $invoiceId = $request->query->get('invoiceId');
        if (null !== $invoiceId && ('' === $invoiceId || !Uuid::isValid($invoiceId))) {
            throw ApiException::invalidId();
        }
        $principal = $this->principal();
        $limit = $this->limit($request);
        $events = $this->auditEvents->findForOrganization(
            $organizationId,
            $principal->getId(),
            $invoiceId,
            $limit,
            Cursor::decode($request->query->get('cursor')),
            $request->query->get('q'),
        );
        $hasMore = count($events) > $limit;
        $events = array_slice($events, 0, $limit);
        $lastEvent = $hasMore ? $events[array_key_last($events)] : null;

        return $this->json([
            'items' => array_map(static fn (InvoiceAuditEvent $event) => [
                'id' => $event->getId(), 'invoiceId' => $event->getInvoiceId(), 'customerId' => $event->getCustomerId(),
                'action' => $event->getAction(), 'actorPrincipalId' => $event->getActorPrincipalId(),
                'metadata' => $event->getMetadata(), 'createdAt' => $event->getCreatedAt()->format(DATE_ATOM),
            ], $events),
            'nextCursor' => null !== $lastEvent ? Cursor::encode($lastEvent->getCreatedAt(), $lastEvent->getId()) : null,
        ]);
    }

    private function transitionInvoice(string $organizationId, string $invoiceId, string $transition): JsonResponse
    {
        if (!$this->isGranted(InvoiceVoter::WRITE, $organizationId)) {
            throw ApiException::notFound();
        }
        $principal = $this->principal();

        try {
            return $this->transactions->run(function () use ($organizationId, $invoiceId, $principal, $transition): JsonResponse {
                $invoice = $this->invoices->findForOrganizationForUpdate($organizationId, $invoiceId, $principal->getId());
                if (null === $invoice) {
                    throw ApiException::notFound();
                }
                if ('send' === $transition && 'draft' !== $invoice->getStatus()) {
                    return $this->json(['invoice' => $this->serializer->invoice($invoice)]);
                }
                if ('paid' === $transition && 'paid' === $invoice->getStatus()) {
                    return $this->json(['invoice' => $this->serializer->invoice($invoice)]);
                }
                $changed = 'send' === $transition ? $invoice->send() : $invoice->markPaid();
                if ($changed) {
                    $this->audit($invoice->getOrganization(), $principal, 'paid' === $transition ? 'invoice.paid' : 'invoice.sent', $invoiceId);
                    if ('paid' === $transition) {
                        // The accountant who recorded the payment does not need to be
                        // told about it; the rest of the administration does.
                        $this->notifications->invoicePaid($invoice, $principal);
                    }
                    $this->entityManager->flush();
                }

                return $this->json(['invoice' => $this->serializer->invoice($invoice)]);
            });
        } catch (\DomainException $e) {
            throw ApiException::conflict($e->getMessage());
        }
    }

    private function principal(): Principal
    {
        $principal = $this->getUser();
        if (!$principal instanceof Principal) {
            throw $this->createAccessDeniedException();
        }

        return $principal;
    }

    /**
     * Rejects a malformed identifier before it reaches a query.
     *
     * PostgreSQL would raise an invalid-UUID error for `customers/not-a-uuid`,
     * which surfaces as a 500. A 400 with a stable code is the honest answer.
     */
    private function assertIds(string ...$ids): void
    {
        foreach ($ids as $id) {
            if (!Uuid::isValid($id)) {
                throw ApiException::invalidId();
            }
        }
    }

    /**
     * Every audit row goes through the shared logger, which the fixture loader
     * also uses, so seeded and real trails cannot drift apart in shape.
     */
    private function audit(Organization $organization, Principal $principal, string $action, ?string $invoiceId = null, ?string $customerId = null): void
    {
        $this->audits->record($organization, $principal, $action, $invoiceId, $customerId);
    }

    /** @return array<string, mixed> */
    /**
     * Reads a customer body and rejects wrong JSON types up front.
     *
     * `name: []` would otherwise reach a constructor that declares a string and
     * surface as a 500; a client error has to be a 4xx. Checking here also lets
     * the response name the offending field.
     *
     * @return array<string, mixed>
     */
    private function customerPayload(Request $request): array
    {
        $data = JsonBody::decode($request);
        if ([] === $data) {
            throw ApiException::invalidJson();
        }

        $fields = [];
        foreach (['name', 'email', 'phone'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            $optional = 'name' !== $field;
            if (($optional && null === $value) || \is_string($value)) {
                continue;
            }
            $fields[$field][] = 'This value must be a string.';
        }
        if (array_key_exists('billingAddress', $data) && null !== $data['billingAddress'] && !\is_array($data['billingAddress'])) {
            $fields['billingAddress'][] = 'This value must be an object.';
        }
        if ([] !== $fields) {
            throw ApiException::validationFailed('The request contains invalid fields.', $fields);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function invoicePayload(Request $request): array
    {
        return $this->customerPayload($request);
    }

    private function limit(Request $request): int
    {
        $raw = $request->query->get('limit');
        if (null === $raw || '' === $raw) {
            return 25;
        }

        return max(1, min(100, (int) $raw));
    }
}
