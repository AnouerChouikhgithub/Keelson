<?php

declare(strict_types=1);

namespace App\Summary;

/**
 * The summaries this API will write, and nothing else.
 *
 * The value is both the path segment and the AI feature suffix, so an unknown
 * kind is rejected before any query runs and the spend ledger records a stable
 * name (`summary_overview`).
 */
enum SummaryKind: string
{
    case Overview = 'overview';
    case Receivables = 'receivables';
    case Activity = 'activity';

    /** The `ai_requests.feature` value, at most 64 characters. */
    public function feature(): string
    {
        return 'summary_'.$this->value;
    }
}
