<?php

declare(strict_types=1);

namespace App\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of `PATCH /api/organizations/{id}/members/{membershipId}`. */
final class MemberRoleInput
{
    #[Assert\NotBlank(message: 'A role is required.')]
    #[Assert\Choice(choices: ['Owner', 'Admin', 'Member', 'Viewer'], message: 'Choose Owner, Admin, Member or Viewer.')]
    public string $role = '';
}
