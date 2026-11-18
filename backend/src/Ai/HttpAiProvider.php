<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Exception\AiUnavailableException;
use App\Ai\Http\AiHttpTransport;

/**
 * An OpenAI-compatible chat-completions provider.
 *
 * Timeouts are fixed at the product level: five seconds to connect (a provider whose
 * DNS or TLS is that slow is not going to answer inside a request) and thirty seconds
 * overall. A `5xx` is retried exactly once, because those are usually transient; a
 * `4xx` is not retried — the request or the credential is wrong and a second attempt
 * only spends the caller's latency.
 *
 * Anything this class cannot turn into an {@see AiResponse} is an
 * {@see AiUnavailableException}. A malformed body must never surface as a 500 or as
 * an empty answer a feature then shows to a user.
 */
final class HttpAiProvider implements AiProvider
{
    private const CONNECT_TIMEOUT_MS = 5000;
    private const TOTAL_TIMEOUT_MS = 30000;

    public function __construct(
        private readonly AiHttpTransport $transport,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
    ) {
    }

    public function complete(AiRequest $request): AiResponse
    {
        if ('' === trim($this->apiKey) || '' === trim($this->model) || '' === trim($this->baseUrl)) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_NOT_CONFIGURED);
        }

        $payload = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $request->systemPrompt],
                ['role' => 'user', 'content' => $request->prompt],
            ],
            'max_tokens' => $request->maxTokens,
        ], \JSON_THROW_ON_ERROR);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer '.$this->apiKey,
        ];
        $url = rtrim($this->baseUrl, '/').'/chat/completions';

        $response = $this->transport->post($url, $headers, $payload, self::CONNECT_TIMEOUT_MS, self::TOTAL_TIMEOUT_MS);
        if ($response->status >= 500) {
            // One retry, and only for a server-side failure: a 4xx cannot be fixed by
            // repeating the request, it only spends the caller's latency again.
            $response = $this->transport->post($url, $headers, $payload, self::CONNECT_TIMEOUT_MS, self::TOTAL_TIMEOUT_MS);
            if ($response->status >= 500) {
                throw AiUnavailableException::create(AiUnavailableException::REASON_SERVER_ERROR);
            }
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_TRANSPORT);
        }

        return $this->parse($response->body);
    }

    private function parse(string $body): AiResponse
    {
        try {
            $data = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_MALFORMED);
        }

        if (!\is_array($data)) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_MALFORMED);
        }

        $choices = $data['choices'] ?? null;
        if (!\is_array($choices) || !isset($choices[0]) || !\is_array($choices[0])) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_MALFORMED);
        }
        $message = $choices[0]['message'] ?? null;
        if (!\is_array($message)) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_MALFORMED);
        }
        $content = $message['content'] ?? null;
        if (!\is_string($content)) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_MALFORMED);
        }

        $usage = $data['usage'] ?? [];
        if (!\is_array($usage)) {
            $usage = [];
        }

        return new AiResponse(
            $content,
            \is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : 0,
            \is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : 0,
            \is_string($data['model'] ?? null) ? $data['model'] : $this->model,
        );
    }
}
