<?php

declare(strict_types=1);

namespace App\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of `PATCH /api/organizations/{id}` — either field, or both. */
final class UpdateOrganizationInput
{
    #[Assert\Length(min: 2, max: 120, minMessage: 'The organization name must contain at least {{ limit }} characters.', maxMessage: 'The organization name must contain at most {{ limit }} characters.')]
    public ?string $name = null;

    #[Assert\Length(min: 3, max: 63, minMessage: 'The subdomain must contain at least {{ limit }} characters.', maxMessage: 'The subdomain must contain at most {{ limit }} characters.')]
    #[Assert\Regex(pattern: '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', message: 'The subdomain may contain lowercase letters, digits and hyphens, and must start and end with a letter or digit.')]
    public ?string $subdomain = null;
}
