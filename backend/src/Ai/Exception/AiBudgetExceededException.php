<?php

declare(strict_types=1);

namespace App\Ai\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * The reservation would take the organization past its monthly token budget.
 *
 * `402 Payment Required` is the closest standard status: the request is well formed
 * and authenticated, but the tenant's allowance is exhausted.
 */
final class AiBudgetExceededException extends \App\Exception\ApiException
{
    private function __construct()
    {
        parent::__construct(
            Response::HTTP_PAYMENT_REQUIRED,
            'ai_budget_exceeded',
            'The organization has used its AI token budget for this month.',
        );
    }

    public static function create(): self
    {
        return new self();
    }
}
