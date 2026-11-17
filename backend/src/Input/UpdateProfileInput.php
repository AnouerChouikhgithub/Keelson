<?php

declare(strict_types=1);

namespace App\Input;

use App\Entity\Principal;
use Symfony\Component\Validator\Constraints as Assert;

/** Body of `PATCH /api/me`. */
final class UpdateProfileInput
{
    #[Assert\NotBlank(message: 'A display name is required.')]
    #[Assert\Length(max: Principal::DISPLAY_NAME_MAX_LENGTH, maxMessage: 'The display name must contain at most {{ limit }} characters.')]
    public string $displayName = '';
}
