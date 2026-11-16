<?php

declare(strict_types=1);

namespace App\Input;

use App\Entity\Principal;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of `POST /api/auth/resend-verification`.
 *
 * The email is validated for shape only. An address that does not exist, or one
 * that is already confirmed, is not an error here: the endpoint answers the same
 * thing either way, so it cannot be used to discover or probe accounts.
 */
final class ResendVerificationInput
{
    #[Assert\NotBlank(message: 'An email address is required.')]
    #[Assert\Email(message: 'This is not a valid email address.')]
    #[Assert\Length(max: Principal::EMAIL_MAX_LENGTH)]
    public string $email = '';
}
