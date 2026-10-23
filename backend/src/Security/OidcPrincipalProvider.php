<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Principal;
use App\Repository\PrincipalRepository;
use Drenso\OidcBundle\Model\OidcTokens;
use Drenso\OidcBundle\Model\OidcUserData;
use Drenso\OidcBundle\Security\UserProvider\OidcUserProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

final class OidcPrincipalProvider implements OidcUserProviderInterface
{
    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $issuer,
    ) {
    }

    public function ensureUserExists(string $userIdentifier, OidcUserData $userData, OidcTokens $tokens): void
    {
        $subject = $userData->getSub();
        if ('' === $this->issuer || '' === $subject || !hash_equals($userIdentifier, $subject)) {
            throw $this->userNotFound($userIdentifier);
        }

        $principal = $this->principals->findByIssuerAndSubject($this->issuer, $subject);
        if (null === $principal) {
            $principal = new Principal($this->issuer, $subject);
            $this->entityManager->persist($principal);
        }

        $displayName = $userData->getFullName();
        if ('' === $displayName) {
            $displayName = $userData->getDisplayName();
        }

        $principal->updateProfile(
            '' === $displayName ? null : $displayName,
            '' === $userData->getEmail() ? null : $userData->getEmail(),
            $userData->getEmailVerified(),
        );
        $this->entityManager->flush();
    }

    public function loadOidcUser(string $userIdentifier): UserInterface
    {
        if ('' === $userIdentifier) {
            throw $this->userNotFound($userIdentifier);
        }

        $principal = $this->principals->findByIssuerAndSubject($this->issuer, $userIdentifier);
        if (null === $principal) {
            throw $this->userNotFound($userIdentifier);
        }

        return $principal;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $prefix = $this->issuer.'|';
        if (!str_starts_with($identifier, $prefix)) {
            throw $this->userNotFound($identifier);
        }

        return $this->loadOidcUser(substr($identifier, strlen($prefix)));
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof Principal) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }

        $principal = $this->principals->findByIssuerAndSubject($this->issuer, $user->getSubject());
        if (null === $principal) {
            throw $this->userNotFound($user->getUserIdentifier());
        }

        return $principal;
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, Principal::class, true);
    }

    private function userNotFound(string $identifier): UserNotFoundException
    {
        $exception = new UserNotFoundException('The OIDC identity is not available to this application.');
        $exception->setUserIdentifier($identifier);

        return $exception;
    }
}
