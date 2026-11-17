<?php

declare(strict_types=1);

namespace App\Ai\Http;

/**
 * The one HTTP operation the AI provider needs: POST JSON with two timeouts.
 *
 * It is an interface so {@see \App\Ai\HttpAiProvider} can be tested without a network
 * and so the transport can be replaced without touching provider or gateway logic.
 */
interface AiHttpTransport
{
    /**
     * @param list<string> $headers
     *
     * @throws \App\Ai\Exception\AiUnavailableException on a connection failure or timeout
     */
    public function post(string $url, array $headers, string $body, int $connectTimeoutMs, int $totalTimeoutMs): AiHttpResponse;
}
