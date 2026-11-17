<?php

declare(strict_types=1);

namespace App\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of `POST /api/organizations/{id}/invitations`. */
final class InviteInput
{
    #[Assert\NotBlank(message: 'An email address is required.')]
    #[Assert\Email(message: 'This is not a valid email address.')]
    #[Assert\Length(max: 320)]
    public string $email = '';

    #[Assert\NotBlank(message: 'A role is required.')]
    #[Assert\Choice(choices: ['Admin', 'Member', 'Viewer'], message: 'Choose Admin, Member or Viewer.')]
    public string $role = '';
}
