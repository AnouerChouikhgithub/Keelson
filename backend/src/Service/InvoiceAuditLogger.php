<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InvoiceAuditEvent;
use App\Entity\Organization;
use App\Entity\Principal;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes invoice audit events.
 *
 * The controller and the synthetic fixture loader both record through here, so
 * a seeded trail has exactly the shape the API produces: the same entity, the
 * same action vocabulary and the same actor column. It only inserts — the table
 * is append-only in PostgreSQL — and it never flushes on its own, because the
 * business row and its event must commit or roll back together.
 */
final class InvoiceAuditLogger
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    public function record(
        Organization $organization,
        Principal $actor,
        string $action,
        ?string $invoiceId = null,
        ?string $customerId = null,
        array $metadata = [],
        ?\DateTimeImmutable $at = null,
    ): InvoiceAuditEvent {
        $event = new InvoiceAuditEvent($organization, $invoiceId, $customerId, $action, $actor->getId(), $metadata, $at);
        $this->entityManager->persist($event);

        return $event;
    }
}
