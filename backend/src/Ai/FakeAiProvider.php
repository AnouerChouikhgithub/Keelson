<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Exception\AiUnavailableException;

/**
 * Deterministic provider for development, tests and a deployment without a key.
 *
 * The answer is derived only from the feature and a digest of the prompt, so a test can
 * assert on it and a developer sees a stable response. `failWith()` is the seam the
 * gateway tests use to reproduce a timeout or a malformed answer without a network.
 */
final class FakeAiProvider implements AiProvider
{
    public const FAILURE_NONE = 'none';
    public const FAILURE_TIMEOUT = 'timeout';
    public const FAILURE_MALFORMED = 'malformed';
    public const FAILURE_SERVER_ERROR = 'server_error';

    private const FAILURES = [
        self::FAILURE_NONE,
        self::FAILURE_TIMEOUT,
        self::FAILURE_MALFORMED,
        self::FAILURE_SERVER_ERROR,
    ];

    private string $failure = self::FAILURE_NONE;

    public function complete(AiRequest $request): AiResponse
    {
        if (self::FAILURE_TIMEOUT === $this->failure) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_TIMEOUT);
        }
        if (self::FAILURE_MALFORMED === $this->failure) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_MALFORMED);
        }
        if (self::FAILURE_SERVER_ERROR === $this->failure) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_SERVER_ERROR);
        }

        $text = sprintf(
            'Fake AI answer for %s (%s).',
            $request->feature,
            substr(hash('sha256', $request->prompt), 0, 12),
        );

        return new AiResponse(
            $text,
            max(1, intdiv(mb_strlen($request->systemPrompt.$request->prompt), 4)),
            max(1, intdiv(mb_strlen($text), 4)),
            'fake-model',
        );
    }

    // @phpstan-ignore shipmonk.deadMethod (driven by tests/Integration/AiGatewayTest)
    public function failWith(string $failure): void
    {
        if (!in_array($failure, self::FAILURES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown fake AI failure "%s".', $failure));
        }

        $this->failure = $failure;
    }
}
