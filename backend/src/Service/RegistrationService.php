<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthToken;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Input\RegisterInput;
use App\Repository\PrincipalRepository;
use App\Util\Subdomain;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates the account, its first organization and the owner membership.
 *
 * All three rows and the verification token are written in one transaction: a
 * principal without an organization could not use the product, and an
 * organization without an owner would be unreachable. The verification email is
 * sent only after that transaction committed, because mail is not transactional
 * and a failed send must not leave a half-created account.
 *
 * If the address is already registered the method returns normally and sends
 * nothing, so the caller's response is identical for a new and an existing
 * account.
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
        private readonly MailerInterface $mailer,
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
            // A concurrent registration of the same address won the unique
            // index. The response must stay identical, so treat it as "exists".
            return;
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
     * user never chooses it in this flow.
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

        throw new \RuntimeException('No free organization subdomain could be allocated.');
    }
}
