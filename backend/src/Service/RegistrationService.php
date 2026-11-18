<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthToken;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Exception\ApiException;
use App\Input\RegisterInput;
use App\Mail\DeferredMailer;
use App\Repository\PrincipalRepository;
use App\Util\Subdomain;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates the account, its first organization and the owner membership.
 *
 * All three rows and the verification token are written in one transaction: a
 * principal without an organization could not use the product, and an
 * organization without an owner would be unreachable. The verification email is
 * built only after that transaction committed and queued on {@see DeferredMailer}
 * for the end of the request, because mail is not transactional: a failed send
 * must not leave a half-created account, and it must not make an existing
 * address measurably slower than a new one.
 *
 * If the address is already registered the method returns normally and sends
 * nothing, so the caller's response is identical for a new and an existing
 * account.
 *
 * A unique-constraint violation is *not* assumed to mean "the address exists":
 * the subdomain index can lose a race on its own. After a failed insert the row
 * is looked up on the connection to decide which index failed, and a subdomain
 * collision is reported as an explicit `409`, never as a successful registration
 * for an account that was never created.
 */
final class RegistrationService
{
    private const MAX_SUBDOMAIN_ATTEMPTS = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrincipalRepository $principals,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TransactionBoundary $transactions,
        private readonly AuthTokenService $tokens,
        private readonly DeferredMailer $mailer,
        private readonly MailFactory $mails,
    ) {
    }

    public function register(RegisterInput $input): void
    {
        $email = Principal::normalizeEmail($input->email);
        if (null !== $this->principals->findOneByEmail($email)) {
            return;
        }

        $principal = null;
        $plaintext = null;

        try {
            $this->transactions->run(function () use ($input, $email, &$principal, &$plaintext): void {
                $principal = new Principal($email, $input->displayName);
                $principal->setPasswordHash($this->passwordHasher->hashPassword($principal, $input->password));
                $this->entityManager->persist($principal);

                $organization = new Organization($input->organizationName, $this->availableSubdomain($input->organizationName));
                $this->entityManager->persist($organization);
                $this->entityManager->persist(new Membership($organization, $principal, 'Owner'));
                $this->entityManager->flush();

                $plaintext = $this->tokens->issue($principal, AuthToken::TYPE_VERIFY);
            });
        } catch (UniqueConstraintViolationException) {
            // The transaction is rolled back; the EntityManager is closed now, so
            // the decision is taken on the raw connection. A concurrent
            // registration of the same address won the email index and must look
            // like "already registered" (identical 201, no mail).
            if ($this->emailExists($email)) {
                return;
            }

            // Otherwise the generated subdomain lost its race. The account was
            // rolled back with it, so reporting success would be a lie.
            throw ApiException::conflict('That organization name is in use; choose another and retry.', 'subdomain_unavailable');
        }

        if (null === $principal || null === $plaintext) {
            throw new \LogicException('Registration did not produce a principal and token.');
        }

        $this->mailer->send($this->mails->verifyEmail($principal, $plaintext));
    }

    /**
     * Picks the first free subdomain derived from the organization name.
     *
     * Collisions are expected (`acme` twice) and are resolved with a numeric
     * suffix rather than rejected, because the subdomain is generated and the
     * user never chooses it in this flow. An exhausted list is an explicit
     * conflict: the unique index remains the authority for a concurrent race.
     */
    private function availableSubdomain(string $organizationName): string
    {
        $base = Subdomain::fromName($organizationName);
        for ($attempt = 1; $attempt <= self::MAX_SUBDOMAIN_ATTEMPTS; ++$attempt) {
            $candidate = Subdomain::withSuffix($base, $attempt);
            $taken = $this->entityManager->getRepository(Organization::class)->findOneBy(['subdomain' => $candidate]);
            if (null === $taken) {
                return $candidate;
            }
        }

        throw ApiException::conflict('That organization name is in use; choose another and retry.', 'subdomain_unavailable');
    }

    /**
     * Reads the address on a fresh statement, not through the closed manager.
     *
     * Runs after the failed transaction rolled back, so a row written by the
     * concurrent winner is committed and visible here.
     */
    private function emailExists(string $email): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM principals WHERE email = ?',
            [$email],
        );
    }
}
