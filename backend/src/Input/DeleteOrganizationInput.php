<?php

declare(strict_types=1);

namespace App\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of `DELETE /api/organizations/{id}`: typing the name is the confirmation. */
final class DeleteOrganizationInput
{
    #[Assert\NotBlank(message: 'Type the organization name to confirm deletion.')]
    public string $confirmName = '';
}
