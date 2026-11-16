<?php

declare(strict_types=1);

namespace App\Input;

use App\Entity\Principal;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of `POST /api/auth/register`.
 *
 * The password bounds are the ones bcrypt can actually represent: bcrypt
 * truncates past 72 bytes, so a longer password would silently lose entropy.
 * The minimum of 10 is the product policy.
 */
final class RegisterInput
{
    public const PASSWORD_MIN_LENGTH = 10;
    public const PASSWORD_MAX_LENGTH = 72;

    #[Assert\NotBlank(message: 'An email address is required.')]
    #[Assert\Email(message: 'This is not a valid email address.')]
    #[Assert\Length(max: Principal::EMAIL_MAX_LENGTH)]
    public string $email = '';

    #[Assert\NotBlank(message: 'A password is required.')]
    #[Assert\Length(
        min: self::PASSWORD_MIN_LENGTH,
        max: self::PASSWORD_MAX_LENGTH,
        minMessage: 'The password must contain at least {{ limit }} characters.',
        maxMessage: 'The password must contain at most {{ limit }} characters.',
    )]
    public string $password = '';

    #[Assert\NotBlank(message: 'An organization name is required.')]
    #[Assert\Length(
        min: 2,
        max: 120,
        minMessage: 'The organization name must contain at least {{ limit }} characters.',
        maxMessage: 'The organization name must contain at most {{ limit }} characters.',
    )]
    public string $organizationName = '';

    #[Assert\Length(max: Principal::DISPLAY_NAME_MAX_LENGTH)]
    public ?string $displayName = null;
}
