<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Ai\Http\AiHttpResponse;
use App\Ai\Http\AiHttpTransport;

/**
 * A transport that answers from a script and remembers what it was asked.
 *
 * The provider drills need to reproduce an HTTP status a fake provider cannot return — a
 * 429, a 500, a body that is not JSON — and to count how many attempts were made. This
 * lives in `tests/Support`, next to the other test doubles, so no stub transport ever
 * ships in `src/`: the only way to reach a provider in production stays
 * `CurlAiTransport`.
 */
final class ScriptedAiTransport implements AiHttpTransport
{
    /** @var list<array{url: string, headers: list<string>, body: string}> */
    public array $requests = [];

    /** @var list<AiHttpResponse> */
    private array $responses;

    /** @param list<AiHttpResponse> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function post(string $url, array $headers, string $body, int $connectTimeoutMs, int $totalTimeoutMs): AiHttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

        return array_shift($this->responses) ?? new AiHttpResponse(500, 'the script ran out');
    }
}
