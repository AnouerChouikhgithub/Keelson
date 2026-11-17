<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Principal;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses to sign in an account whose email address is not confirmed.
 *
 * The check runs in `checkPostAuth` on purpose. Symfony calls `checkPreAuth`
 * *before* it verifies the password, so rejecting there would answer "email not
 * verified" to anyone who guessed an address. After the password, the response
 * is a fact about an account the caller already proved they own.
 *
 * The rule is switchable because a local or staging deployment may want accounts
 * to work before mail delivery exists.
 */
final class VerifiedUserChecker implements UserCheckerInterface
{
    public function __construct(private readonly bool $requireVerifiedEmail)
    {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        // Nothing to check before the password: see the class comment.
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof Principal || !$this->requireVerifiedEmail) {
            return;
        }

        if (!$user->isEmailVerified()) {
            throw new EmailNotVerifiedException();
        }
    }
}
