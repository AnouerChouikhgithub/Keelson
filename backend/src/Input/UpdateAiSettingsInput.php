<?php

declare(strict_types=1);

namespace App\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of `PUT /api/organizations/{id}/ai-settings`: both fields, always.
 *
 * They are nullable so a missing field is distinguishable from a valid `0`/`false`;
 * the controller only ever sees a validated, non-null pair.
 */
final class UpdateAiSettingsInput
{
    #[Assert\NotNull(message: 'Say whether AI is enabled.')]
    #[Assert\Type('bool', message: 'This value must be a boolean.')]
    public ?bool $enabled = null;

    #[Assert\NotNull(message: 'A monthly token budget is required.')]
    #[Assert\Type('int', message: 'This value must be an integer.')]
    #[Assert\Range(min: 0, max: 100000000, notInRangeMessage: 'The monthly token budget must be between {{ min }} and {{ max }}.')]
    public ?int $monthlyTokenBudget = null;
}
