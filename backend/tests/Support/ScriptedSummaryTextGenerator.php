<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Principal;
use App\Summary\SummaryTextGenerator;
use App\Summary\WorkspaceFacts;

/**
 * A generator a test writes the answer for.
 *
 * The grounding tests need a provider that lies confidently — "987,654 invoices"
 * — which is impossible to express with the deterministic fake provider. Each
 * scripted entry is either the text to return or the exception to throw.
 */
final class ScriptedSummaryTextGenerator implements SummaryTextGenerator
{
    /** @var list<string|\Throwable> */
    private array $responses = [];

    private int $calls = 0;

    /** @param list<string|\Throwable> $responses */
    public function script(array $responses): void
    {
        $this->responses = $responses;
        $this->calls = 0;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function generate(Principal $principal, WorkspaceFacts $facts): string
    {
        ++$this->calls;
        $next = array_shift($this->responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return is_string($next) ? $next : '';
    }
}
