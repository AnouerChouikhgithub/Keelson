<?php

declare(strict_types=1);

namespace App\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of `POST /api/auth/verify-email`. */
final class VerifyEmailInput
{
    #[Assert\NotBlank(message: 'A token is required.')]
    #[Assert\Length(max: 512)]
    public string $token = '';
}
