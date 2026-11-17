<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Signals "the credentials were right, the address is not confirmed yet".
 *
 * Thrown from {@see VerifiedUserChecker::checkPostAuth()}, i.e. only after the
 * password has been verified, so a caller who does not know the password can
 * never learn whether an account exists but is unverified.
 */
final class EmailNotVerifiedException extends CustomUserMessageAccountStatusException
{
    public function __construct()
    {
        parent::__construct('Confirm your email address before signing in.');
    }
}
