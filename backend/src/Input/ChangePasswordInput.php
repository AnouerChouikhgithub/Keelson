<?php

declare(strict_types=1);

namespace App\Input;

use App\Input\RegisterInput as Registration;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of `POST /api/me/password`.
 *
 * The bounds match registration: bcrypt cannot represent more than 72 bytes, and
 * 10 is the product minimum.
 */
final class ChangePasswordInput
{
    #[Assert\NotBlank(message: 'Your current password is required.')]
    public string $currentPassword = '';

    #[Assert\NotBlank(message: 'A new password is required.')]
    #[Assert\Length(
        min: Registration::PASSWORD_MIN_LENGTH,
        max: Registration::PASSWORD_MAX_LENGTH,
        minMessage: 'The password must contain at least {{ limit }} characters.',
        maxMessage: 'The password must contain at most {{ limit }} characters.',
    )]
    public string $newPassword = '';
}
