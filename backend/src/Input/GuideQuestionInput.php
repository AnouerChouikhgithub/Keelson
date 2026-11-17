<?php

declare(strict_types=1);

namespace App\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of `POST /api/ai/guide`.
 *
 * `organizationId` is optional and only selects which tenant's budget and rate limits
 * the call spends, exactly like `GET /api/ai/status`: omitting it uses the caller's
 * first active organization. `locale` decides the corpus and the language of the fixed
 * "not covered" answer; it is not a free-form string, because the knowledge base only
 * exists in these three locales.
 */
final class GuideQuestionInput
{
    #[Assert\NotBlank(message: 'Ask a question.')]
    #[Assert\Length(
        min: 3,
        max: 500,
        minMessage: 'The question must contain at least {{ limit }} characters.',
        maxMessage: 'The question must not exceed {{ limit }} characters.',
    )]
    public string $question = '';

    #[Assert\Choice(choices: ['en', 'fr', 'ar'], message: 'Choose en, fr or ar.')]
    public string $locale = 'en';

    public ?string $organizationId = null;
}
