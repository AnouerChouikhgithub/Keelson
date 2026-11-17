<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Repository\MembershipRepository;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates notifications for the people who should see them.
 *
 * Fan-out happens here, at write time, to the organization's Owners and Admins.
 * Recipients come from the membership repository (active memberships only), so
 * a suspended or removed administrator stops receiving notices immediately.
 *
 * Notifications are best-effort side effects: they are persisted in the caller's
 * transaction but never throw a business error, and automatic ones are
 * deduplicated per (recipient, type, invoice) so re-running a job is harmless.
 */
final class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationRepository $notifications,
        private readonly MembershipRepository $memberships,
    ) {
    }

    public function invoicePaid(Invoice $invoice, Principal $actor): void
    {
        $this->notifyInvoice($invoice, Notification::TYPE_INVOICE_PAID, $actor, 'invoice.paid');
    }

    public function invoiceOverdue(Invoice $invoice): void
    {
        $this->notifyInvoice($invoice, Notification::TYPE_INVOICE_OVERDUE, null, 'invoice.overdue');
    }

    public function memberJoined(Organization $organization, Principal $member, bool $acceptedInvitation): void
    {
        foreach ($this->recipients($organization) as $recipient) {
            if ($recipient->getId() === $member->getId()) {
                continue;
            }
            $this->entityManager->persist(new Notification(
                $recipient,
                $organization,
                Notification::TYPE_MEMBER_JOINED,
                [
                    'organizationId' => $organization->getId(),
                    'organizationName' => $organization->getName(),
                    'principalId' => $member->getId(),
                    'memberEmail' => $member->getEmail(),
                    'memberName' => $member->getDisplayName(),
                    'viaInvitation' => $acceptedInvitation,
                ],
            ));
        }
    }

    public function invitationAccepted(Organization $organization, Principal $invited, Principal $inviter): void
    {
        if ($inviter->getId() === $invited->getId()) {
            return;
        }
        $this->entityManager->persist(new Notification(
            $inviter,
            $organization,
            Notification::TYPE_INVITATION_ACCEPTED,
            [
                'organizationId' => $organization->getId(),
                'organizationName' => $organization->getName(),
                'principalId' => $invited->getId(),
                'memberEmail' => $invited->getEmail(),
            ],
        ));
    }

    private function notifyInvoice(Invoice $invoice, string $type, ?Principal $actor, string $reason): void
    {
        $organization = $invoice->getOrganization();
        foreach ($this->recipients($organization) as $recipient) {
            if (null !== $actor && $recipient->getId() === $actor->getId()) {
                // Do not tell someone about the action they just performed.
                continue;
            }
            if ($this->notifications->hasInvoiceNotification($recipient->getId(), $type, $invoice->getId())) {
                continue;
            }
            $this->entityManager->persist(new Notification(
                $recipient,
                $organization,
                $type,
                [
                    'invoiceId' => $invoice->getId(),
                    'invoiceNumber' => $invoice->getNumber(),
                    'total' => $invoice->getTotal(),
                    'currency' => $invoice->getCurrency(),
                    'reason' => $reason,
                ],
            ));
        }
    }

    /** @return list<Principal> */
    private function recipients(Organization $organization): array
    {
        $recipients = [];
        foreach ($this->memberships->findForOrganizationAdmins($organization->getId()) as $membership) {
            $recipients[] = $membership->getPrincipal();
        }

        return $recipients;
    }
}
