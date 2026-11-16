<?php

declare(strict_types=1);

namespace App\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of `POST /api/auth/reset-password`. */
final class ResetPasswordInput
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
