<?php

declare(strict_types=1);

namespace App\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of `POST /api/auth/verify-email`.
 *
 * The password is part of redeeming the link, not an extra step: registration
 * already stored a hash, so whoever typed the address — who may not own the
 * mailbox — picked it. Requiring a password here lets the mailbox owner replace
 * that hash at the moment they prove control of the address, so an account
 * pre-registered by someone else can never be signed into with their password.
 *
 * The bounds are RegisterInput's own constants, quoted rather than copied, so the
 * two forms cannot drift apart.
 */
final class VerifyEmailInput
{
    #[Assert\NotBlank(message: 'A token is required.')]
    #[Assert\Length(max: 512)]
    public string $token = '';

    #[Assert\NotBlank(message: 'A password is required.')]
    #[Assert\Length(
        min: RegisterInput::PASSWORD_MIN_LENGTH,
        max: RegisterInput::PASSWORD_MAX_LENGTH,
        minMessage: 'The password must contain at least {{ limit }} characters.',
        maxMessage: 'The password must contain at most {{ limit }} characters.',
    )]
    public string $password = '';
}
