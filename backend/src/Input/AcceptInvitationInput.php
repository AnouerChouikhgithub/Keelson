<?php

declare(strict_types=1);

namespace App\Input;

use App\Entity\Principal;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of `POST /api/auth/accept-invitation`.
 *
 * `password` is required only when the address has no account yet; the service
 * enforces that, because a constraint cannot see whether the account exists.
 */
final class AcceptInvitationInput
{
    #[Assert\NotBlank(message: 'An invitation token is required.')]
    #[Assert\Length(max: 255)]
    public string $token = '';

    #[Assert\Length(
        min: 10,
        max: 72,
        minMessage: 'The password must contain at least {{ limit }} characters.',
        maxMessage: 'The password must contain at most {{ limit }} characters.',
    )]
    public ?string $password = null;

    #[Assert\Length(max: Principal::DISPLAY_NAME_MAX_LENGTH, maxMessage: 'The display name must contain at most {{ limit }} characters.')]
    public ?string $displayName = null;
}
