<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\InvoiceAuditEvent;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Repository\CustomerRepository;
use App\Repository\InvoiceAuditEventRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrganizationRepository;
use App\Security\InvoiceVoter;
use App\Service\InvoiceCalculator;
use App\Service\InvoiceInput;
use App\Service\InvoiceSerializer;
use App\Service\TransactionBoundary;
use App\Util\Uuid;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
        private readonly InvoiceInput $invoiceInput,
        private readonly InvoiceSerializer $serializer,
        private readonly TransactionBoundary $transactions,
    ) {
    }

    #[Route('/customers', name: 'api_customers_list', methods: ['GET'])]
    public function listCustomers(string $organizationId, Request $request): JsonResponse
    {
        if (!$this->isGranted(InvoiceVoter::READ, $organizationId)) {
            return $this->denied();
        }
        $principal = $this->principal();
        $limit = $this->limit($request);
        $rows = $this->customers->findForOrganizationList($organizationId, $principal->getId(), $request->query->get('cursor'), $limit);
        $hasMore = count($rows) > $limit;
        $nextCursor = $hasMore ? $rows[$limit - 1]->getId() : null;
        $rows = array_slice($rows, 0, $limit);

        return $this->json(['items' => array_map(fn (Customer $customer) => $this->serializer->customer($customer, false), $rows), 'nextCursor' => $nextCursor]);
    }

    #[Route('/customers', name: 'api_customers_create', methods: ['POST'])]
    public function createCustomer(string $organizationId, Request $request): JsonResponse
    {
        if (!$this->isGranted(InvoiceVoter::WRITE, $organizationId)) {
            return $this->denied();
        }
        if ($denied = $this->checkMutation($request)) {
            return $denied;
        }
        $principal = $this->principal();
        try {
            return $this->transactions->run(function () use ($organizationId, $principal, $request): JsonResponse {
                $data = $this->payload($request);
                $organization = $this->organizations->findActiveForPrincipalInOrganization($organizationId, $principal->getId());
                if (null === $organization) {
                    return $this->denied();
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
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/customers/{customerId}', name: 'api_customers_read', methods: ['GET'])]
    public function readCustomer(string $organizationId, string $customerId): JsonResponse
    {
        if (!$this->isGranted(InvoiceVoter::READ, $organizationId)) {
            return $this->denied();
        }
        $principal = $this->principal();
        $customer = $this->customers->findForOrganization($organizationId, $customerId, $principal->getId());

        return null === $customer ? $this->denied() : $this->json(['customer' => $this->serializer->customer($customer)]);
    }

    #[Route('/customers/{customerId}', name: 'api_customers_update', methods: ['PATCH'])]
    public function updateCustomer(string $organizationId, string $customerId, Request $request): JsonResponse
    {
        if (!$this->isGranted(InvoiceVoter::WRITE, $organizationId)) {
            return $this->denied();
        }
        if ($denied = $this->checkMutation($request)) {
            return $denied;
        }
        $principal = $this->principal();
        try {
            return $this->transactions->run(function () use ($organizationId, $customerId, $principal, $request): JsonResponse {
                $customer = $this->customers->findForOrganization($organizationId, $customerId, $principal->getId());
                if (null === $customer) {
                    return $this->denied();
                }
                $data = $this->payload($request);
                $allowedFields = ['name', 'email', 'phone', 'billingAddress'];
                if ([] === array_intersect(array_keys($data), $allowedFields) || array_diff(array_keys($data), $allowedFields)) {
                    return $this->error('Customer patch contains no mutable fields or unsupported fields.', Response::HTTP_BAD_REQUEST);
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
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/customers/{customerId}', name: 'api_customers_delete', methods: ['DELETE'])]
    public function deleteCustomer(string $organizationId, string $customerId, Request $request): Response
    {
        if (!$this->isGranted(InvoiceVoter::DELETE_DRAFT, $organizationId)) {
            return $this->denied();
        }
        if ($denied = $this->checkMutation($request)) {
            return $denied;
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
                    return $this->denied();
                }
                if ($this->customers->hasInvoices($organizationId, $customerId)) {
                    return $this->error('Customer is referenced by invoices.', Response::HTTP_CONFLICT);
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
            return $this->error('Customer is referenced by invoices.', Response::HTTP_CONFLICT);
        }
    }

    #[Route('/invoices', name: 'api_invoices_list', methods: ['GET'])]
    public function listInvoices(string $organizationId, Request $request): JsonResponse
    {
        if (!$this->isGranted(InvoiceVoter::READ, $organizationId)) {
            return $this->denied();
        }
        $principal = $this->principal();
        $status = $request->query->get('status');
        if (null !== $status && !in_array($status, ['draft', 'sent', 'paid', 'overdue'], true)) {
            return $this->error('Invoice status filter is invalid.', Response::HTTP_BAD_REQUEST);
        }
        $limit = $this->limit($request);
        $cursor = $this->invoiceCursor($request->query->get('cursor'));
        if ($request->query->has('cursor') && null === $cursor) {
            return $this->error('Invoice cursor is invalid.', Response::HTTP_BAD_REQUEST);
        }
        // Read-only: overdue is derived in the query filter and in the
        // serializer. A GET must not write status or audit rows, because that
        // could overwrite a concurrent payment.
        $rows = $this->invoices->findForOrganizationList($organizationId, $principal->getId(), $status, $limit, $cursor);
        $hasMore = count($rows) > $limit;
        $nextCursor = $hasMore ? $this->encodeInvoiceCursor($rows[$limit - 1]) : null;
        $rows = array_slice($rows, 0, $limit);

        return $this->json(['items' => array_map($this->serializer->invoice(...), $rows), 'nextCursor' => $nextCursor]);
    }

    #[Route('/invoices', name: 'api_invoices_create', methods: ['POST'])]
    public function createInvoice(string $organizationId, Request $request): JsonResponse
    {
        if (!$this->isGranted(InvoiceVoter::WRITE, $organizationId)) {
            return $this->denied();
        }
        if ($denied = $this->checkMutation($request)) {
            return $denied;
        }
        $principal = $this->principal();
        try {
            return $this->transactions->run(function () use ($organizationId, $principal, $request): JsonResponse {
                $data = $this->invoiceInput->parse($this->payload($request), $this->calculator);
                $customer = $this->customers->findForOrganization($organizationId, $data['customerId'], $principal->getId());
                if (null === $customer) {
                    return $this->denied();
                }
                $calculation = $data['calculation'];
                $organization = $this->organizations->findActiveForPrincipalInOrganization($organizationId, $principal->getId());
                if (null === $organization) {
                    return $this->denied();
                }
                // Number allocation takes a transaction-scoped lock, so it must
                // run inside this unit of work; the unique index stays as the
                // last line of defence.
                $invoice = new Invoice(
                    $organization, $customer, $this->invoices->nextNumber($organizationId),
                    $principal->getId(), $data['issueDate'], $data['dueDate'], $calculation['lineItems'],
                    $calculation['taxRate'], $calculation['discountAmount'], $calculation['subtotal'],
                    $calculation['taxAmount'], $calculation['total'],
                );
                $this->entityManager->persist($invoice);
                $this->audit($organization, $principal, 'invoice.created', $invoice->getId(), $customer->getId());
                $this->entityManager->flush();

                return $this->json(['invoice' => $this->serializer->invoice($invoice)], Response::HTTP_CREATED);
            });
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UniqueConstraintViolationException) {
            return $this->error('Invoice number allocation conflicted; retry the request.', Response::HTTP_CONFLICT);
        }
    }

    #[Route('/invoices/{invoiceId}', name: 'api_invoices_read', methods: ['GET'])]
    public function readInvoice(string $organizationId, string $invoiceId): JsonResponse
    {
        if (!$this->isGranted(InvoiceVoter::READ, $organizationId)) {
            return $this->denied();
        }
        $principal = $this->principal();
        $invoice = $this->invoices->findForOrganization($organizationId, $invoiceId, $principal->getId());
        if (null === $invoice) {
            return $this->denied();
        }

        return $this->json(['invoice' => $this->serializer->invoice($invoice)]);
    }

    #[Route('/invoices/{invoiceId}', name: 'api_invoices_update', methods: ['PATCH'])]
    public function updateInvoice(string $organizationId, string $invoiceId, Request $request): JsonResponse
    {
        if (!$this->isGranted(InvoiceVoter::WRITE, $organizationId)) {
            return $this->denied();
        }
        if ($denied = $this->checkMutation($request)) {
            return $denied;
        }
        $principal = $this->principal();
        try {
            return $this->transactions->run(function () use ($organizationId, $invoiceId, $principal, $request): JsonResponse {
                // Lock the row before reading it: a concurrent send must never be overwritten by a stale draft edit.
                $invoice = $this->invoices->findForOrganizationForUpdate($organizationId, $invoiceId, $principal->getId());
                if (null === $invoice) {
                    return $this->denied();
                }
                if ('draft' !== $invoice->getStatus()) {
                    return $this->error('Only draft invoices may be edited.', Response::HTTP_CONFLICT);
                }
                $payload = $this->payload($request);
                $allowedFields = ['customerId', 'issueDate', 'dueDate', 'currency', 'lineItems', 'taxRate', 'discountAmount'];
                if ([] === array_intersect(array_keys($payload), $allowedFields) || array_diff(array_keys($payload), $allowedFields)) {
                    return $this->error('Invoice patch contains no mutable fields or unsupported fields.', Response::HTTP_BAD_REQUEST);
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
                $data = $this->invoiceInput->parse($payload, $this->calculator);
                $customer = $this->customers->findForOrganization($organizationId, $data['customerId'], $principal->getId());
                if (null === $customer) {
                    return $this->denied();
                }
                $calculation = $data['calculation'];
                $invoice->updateDraft($customer, $data['issueDate'], $data['dueDate'], $calculation['lineItems'], $calculation['taxRate'], $calculation['discountAmount'], $calculation['subtotal'], $calculation['taxAmount'], $calculation['total']);
                $this->audit($invoice->getOrganization(), $principal, 'invoice.updated', $invoiceId, $customer->getId());
                $this->entityManager->flush();

                return $this->json(['invoice' => $this->serializer->invoice($invoice)]);
            });
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/invoices/{invoiceId}/send', name: 'api_invoices_send', methods: ['POST'])]
    public function sendInvoice(string $organizationId, string $invoiceId, Request $request): JsonResponse|Response
    {
        return $this->transitionInvoice($organizationId, $invoiceId, $request, 'send');
    }

    #[Route('/invoices/{invoiceId}/mark-paid', name: 'api_invoices_paid', methods: ['POST'])]
    public function markInvoicePaid(string $organizationId, string $invoiceId, Request $request): JsonResponse|Response
    {
        return $this->transitionInvoice($organizationId, $invoiceId, $request, 'paid');
    }

    #[Route('/invoices/{invoiceId}', name: 'api_invoices_delete_draft', methods: ['DELETE'])]
    public function deleteDraftInvoice(string $organizationId, string $invoiceId, Request $request): Response
    {
        if (!$this->isGranted(InvoiceVoter::DELETE_DRAFT, $organizationId)) {
            return $this->denied();
        }
        if ($denied = $this->checkMutation($request)) {
            return $denied;
        }
        $principal = $this->principal();
        return $this->transactions->run(function () use ($organizationId, $invoiceId, $principal): Response {
            // Same lock-and-recheck rule as edits: never delete an invoice a concurrent send just committed.
            $invoice = $this->invoices->findForOrganizationForUpdate($organizationId, $invoiceId, $principal->getId());
            if (null === $invoice) {
                return $this->denied();
            }
            if ('draft' !== $invoice->getStatus()) {
                return $this->error('Only draft invoices may be deleted.', Response::HTTP_CONFLICT);
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
        if (!$this->isGranted(InvoiceVoter::READ, $organizationId)) {
            return $this->denied();
        }
        $principal = $this->principal();
        $events = $this->auditEvents->findForOrganization($organizationId, $principal->getId(), $request->query->get('invoiceId'), $this->limit($request));

        return $this->json(['items' => array_map(static fn (InvoiceAuditEvent $event) => [
            'id' => $event->getId(), 'invoiceId' => $event->getInvoiceId(), 'customerId' => $event->getCustomerId(),
            'action' => $event->getAction(), 'actorPrincipalId' => $event->getActorPrincipalId(),
            'metadata' => $event->getMetadata(), 'createdAt' => $event->getCreatedAt()->format(DATE_ATOM),
        ], $events)]);
    }

    private function transitionInvoice(string $organizationId, string $invoiceId, Request $request, string $transition): JsonResponse|Response
    {
        if (!$this->isGranted(InvoiceVoter::WRITE, $organizationId)) {
            return $this->denied();
        }
        if ($denied = $this->checkMutation($request)) {
            return $denied;
        }
        $principal = $this->principal();
        try {
            return $this->transactions->run(function () use ($organizationId, $invoiceId, $principal, $transition): JsonResponse {
                $invoice = $this->invoices->findForOrganizationForUpdate($organizationId, $invoiceId, $principal->getId());
                if (null === $invoice) {
                    return $this->denied();
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
                    $this->entityManager->flush();
                }

                return $this->json(['invoice' => $this->serializer->invoice($invoice)]);
            });
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), Response::HTTP_CONFLICT);
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

    private function checkMutation(Request $request): ?JsonResponse
    {
        if (!$request->isMethodSafe() && !$this->isCsrfTokenValid('invoice_api', (string) $request->headers->get('X-CSRF-Token', ''))) {
            // API clients may use same-origin enforcement where session CSRF tokens are not exposed yet.
            $origin = $request->headers->get('Origin');
            $host = $request->getSchemeAndHttpHost();
            if (null === $origin || !hash_equals($host, $origin)) {
                return $this->error('A valid same-origin request is required.', Response::HTTP_FORBIDDEN);
            }
        }

        return null;
    }

    private function audit(Organization $organization, Principal $principal, string $action, ?string $invoiceId = null, ?string $customerId = null): void
    {
        $this->entityManager->persist(new InvoiceAuditEvent($organization, $invoiceId, $customerId, $action, $principal->getId()));
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $data = $request->toArray();
        if ([] === $data || array_is_list($data)) {
            throw new \InvalidArgumentException('Request body must be a non-empty JSON object.');
        }

        return $data;
    }

    private function invoiceCursor(?string $encoded): ?array
    {
        if (null === $encoded || '' === $encoded) {
            return null;
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (false === $decoded) {
            return null;
        }
        try {
            $cursor = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($cursor) || !is_string($cursor['id'] ?? null) || !Uuid::isValid($cursor['id']) || !is_string($cursor['createdAt'] ?? null)) {
            return null;
        }
        try {
            $createdAt = new \DateTimeImmutable($cursor['createdAt']);
        } catch (\Exception) {
            return null;
        }

        return ['id' => $cursor['id'], 'createdAt' => $createdAt];
    }

    private function encodeInvoiceCursor(Invoice $invoice): string
    {
        return rtrim(strtr(base64_encode(json_encode(['id' => $invoice->getId(), 'createdAt' => $invoice->getCreatedAt()->format(DATE_ATOM)], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function limit(Request $request): int
    {
        return max(1, min(100, (int) $request->query->get('limit', 50)));
    }

    private function denied(): JsonResponse
    {
        return $this->error('Resource not found.', Response::HTTP_NOT_FOUND);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['error' => ['message' => $message]], $status);
    }
}
