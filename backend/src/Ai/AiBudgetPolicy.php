<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The spend numbers an operator watches.
 *
 * They live here, in one place, because two things must agree about them: the
 * `app:ai:usage` command warns against them and docs/ai.md publishes them. A literal
 * repeated in a command and a document drifts the first time one of them changes.
 *
 * Nothing here is enforced. The enforced value is the per-organization
 * `monthly_token_budget` an Owner or Admin sets — it starts at 0, which is what keeps
 * the feature off until someone configures it — and these are the thresholds that make
 * a spike visible before the budget runs out.
 */
final class AiBudgetPolicy
{
    /** One day at 40% of a 50,000-token monthly allowance: either a spike or a runaway loop. */
    public const ALERT_DAILY_TOKENS = 20000;

    /** Warn when a tenant has spent this share of its monthly budget. */
    public const ALERT_UTILISATION_PERCENT = 80;
}
