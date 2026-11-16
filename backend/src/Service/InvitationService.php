<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invitation;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Entity\SecurityEvent;
use App\Exception\ApiException;
use App\Repository\InvitationRepository;
use App\Repository\MembershipRepository;
use App\Repository\PrincipalRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Issues, resends and redeems invitations.
 *
 * The plaintext token is returned to the caller once (for the email) and never
 * stored: the row keeps `hash('sha256', $token)`. Acceptance is the only place
 * an invitation creates a membership, and it runs in one transaction so a
 * half-accepted invitation cannot exist.
 */
final class InvitationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvitationRepository $invitations,
        private readonly MembershipRepository $memberships,
        private readonly PrincipalRepository $principals,
        private readonly TransactionBoundary $transactions,
        private readonly NotificationService $notifications,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MailerInterface $mailer,
        private readonly MailFactory $mails,
        private readonly SecurityEventRecorder $events,
    ) {
    }

    /** @return array{invitation: Invitation, token: string} */
    public function invite(Organization $organization, Principal $inviter, string $email, string $role): array
    {
        $email = Principal::normalizeEmail($email);
        if (null !== $this->memberships->findOneBy(['organization' => $organization, 'principal' => $this->principals->findOneByEmail($email)])) {
            throw ApiException::conflictAlreadyMember();
        }
        if (null !== $this->invitations->findPendingForEmail($organization->getId(), $email)) {
            throw ApiException::conflict('An invitation is already pending for this address.');
        }

        $token = self::newToken();
        $invitation = new Invitation(
            $organization,
            $email,
            $role,
            self::hash($token),
            $inviter,
            (new \DateTimeImmutable())->add(new \DateInterval(Invitation::LIFETIME)),
        );
        $this->entityManager->persist($invitation);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two admins inviting the same address at once: the partial unique
            // index on pending invitations is the authority, not the check above.
            throw ApiException::conflict('An invitation is already pending for this address.');
        }

        return ['invitation' => $invitation, 'token' => $token];
    }

    /** A new token and a fresh 7-day window; the previous link stops working. */
    public function resend(Invitation $invitation, ?\DateTimeImmutable $now = null): string
    {
        $token = self::newToken();
        $invitation->reissue(self::hash($token), $now);
        $this->entityManager->flush();

        return $token;
    }

    /**
     * Redeems an invitation for the caller.
     *
     * Two paths, decided by whether the address already has an account:
     *
     * - existing account: the caller must *be* that account, otherwise a signed-in
     *   person could attach someone else's address (or learn that it exists).
     * - new account: the password is required here, and the address is marked
     *   verified because receiving the link proves control of the mailbox.
     *
     * @return array{organization: Organization, membership: Membership, principal: Principal, created: bool}
     */
    public function accept(string $plaintextToken, ?Principal $caller, ?string $password, ?string $displayName): array
    {
        $invitation = '' === $plaintextToken ? null : $this->invitations->findUsableByTokenHash(self::hash($plaintextToken));
        if (null === $invitation || !$invitation->isUsable()) {
            throw ApiException::invalidToken();
        }

        return $this->transactions->run(function () use ($invitation, $caller, $password, $displayName): array {
            $organization = $invitation->getOrganization();
            $existing = $this->principals->findOneByEmail($invitation->getEmail());
            $created = false;

            if (null !== $existing) {
                if (null === $caller || $caller->getId() !== $existing->getId()) {
                    throw ApiException::signInRequired();
                }
                $principal = $existing;
                if (null !== $this->memberships->findOneBy(['organization' => $organization, 'principal' => $principal])) {
                    throw ApiException::conflictAlreadyMember();
                }
            } else {
                if (null === $password || '' === $password) {
                    throw ApiException::validationFailed('The request contains invalid fields.', ['password' => ['Choose a password to create your account.']]);
                }
                $principal = new Principal($invitation->getEmail(), $displayName);
                $principal->setPasswordHash($this->passwordHasher->hashPassword($principal, $password));
                // The token arrived by email, which is what verification means here.
                $principal->markEmailVerified();
                $this->entityManager->persist($principal);
                $created = true;
            }

            $membership = new Membership($organization, $principal, $invitation->getRole());
            $this->entityManager->persist($membership);
            $invitation->accept();
            $this->notifications->memberJoined($organization, $principal, true);
            $this->notifications->invitationAccepted($organization, $principal, $invitation->getInvitedBy());
            $this->entityManager->flush();
            $this->events->record(SecurityEvent::INVITATION_ACCEPTED, $principal, [
                'organizationId' => $organization->getId(),
                'role' => $invitation->getRole(),
                'accountCreated' => $created,
            ]);

            return ['organization' => $organization, 'membership' => $membership, 'principal' => $principal, 'created' => $created];
        });
    }

    /**
     * Public preview: never says whether the address has an account.
     *
     * An unknown link is a 404 (there is nothing at this address), while an
     * expired or used one still resolves and says so.
     */
    public function preview(string $plaintextToken): Invitation
    {
        $invitation = '' === $plaintextToken ? null : $this->invitations->findUsableByTokenHash(self::hash($plaintextToken));
        if (null === $invitation) {
            throw ApiException::notFound('This invitation link is not valid.');
        }

        return $invitation;
    }

    public function sendInvitationEmail(Organization $organization, Principal $inviter, string $email, string $role, string $token): void
    {
        $this->mailer->send($this->mails->invitation($organization->getName(), $inviter, $email, $role, $token));
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
