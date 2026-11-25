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
 * The response is normalised, not trusted. A completion may carry its text as a string
 * or as a list of content parts, may include a `reasoning` (or `reasoning_content`)
 * field from a model that thinks before it answers, and may inline a ` thinking` block
 * in the visible text. Only the visible text is an answer: reasoning fields are ignored
 * and reasoning blocks are removed before the sanitizer sees the text, so hidden
 * deliberation can neither leak into a summary nor make an answer look non-empty.
 *
 * `finish_reason = length` with no visible text is its own failure (`truncated`): the
 * request was cut off, usually because a reasoning model spent the whole output budget
 * thinking. Retrying the same request unchanged will fail the same way, and the ledger
 * should say so instead of reporting a generic malformed body.
 *
 * Anything this class cannot turn into an {@see AiResponse} is an
 * {@see AiUnavailableException}. A malformed body must never surface as a 500 or as
 * an empty answer a feature then shows to a user.
 */
final class HttpAiProvider implements AiProvider
{
    private const CONNECT_TIMEOUT_MS = 5000;
    private const TOTAL_TIMEOUT_MS = 30000;

    /**
     * Reasoning-block forms seen from OpenAI-compatible models. Both are removed before
     * sanitising; an unclosed block is removed to the end of the text (a truncated
     * deliberation is still not an answer).
     */
    private const REASONING_BLOCK = '~<think(?:ing)?\b[^>]*>.*?</think(?:ing)?>~is';

    public function __construct(
        private readonly AiHttpTransport $transport,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        /** Optional provider parameter: OpenAI-compatible APIs accept `reasoning_effort`. */
        private readonly string $reasoningEffort = '',
    ) {
    }

    public function complete(AiRequest $request): AiResponse
    {
        if ('' === trim($this->apiKey) || '' === trim($this->model) || '' === trim($this->baseUrl)) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_NOT_CONFIGURED);
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $request->systemPrompt],
                ['role' => 'user', 'content' => $request->prompt],
            ],
            'max_tokens' => $request->maxTokens,
        ];
        if ('' !== trim($this->reasoningEffort)) {
            // Sent only when the deployment configures it: some compatible endpoints
            // reject parameters they do not know.
            $payload['reasoning_effort'] = $this->reasoningEffort;
        }

        $body = json_encode($payload, \JSON_THROW_ON_ERROR);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer '.$this->apiKey,
        ];
        $url = rtrim($this->baseUrl, '/').'/chat/completions';

        $response = $this->transport->post($url, $headers, $body, self::CONNECT_TIMEOUT_MS, self::TOTAL_TIMEOUT_MS);
        if ($response->status >= 500) {
            // One retry, and only for a server-side failure: a 4xx cannot be fixed by
            // repeating the request, it only spends the caller's latency again.
            $response = $this->transport->post($url, $headers, $body, self::CONNECT_TIMEOUT_MS, self::TOTAL_TIMEOUT_MS);
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
            throw AiUnavailableException::create(AiUnavailableException::REASON_MALFORMED_BODY);
        }

        if (!\is_array($data)) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_MALFORMED_BODY);
        }

        $choices = $data['choices'] ?? null;
        if (!\is_array($choices) || !isset($choices[0]) || !\is_array($choices[0])) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_MALFORMED_BODY);
        }
        $choice = $choices[0];
        $message = $choice['message'] ?? null;
        if (!\is_array($message)) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_MALFORMED_BODY);
        }

        $finishReason = \is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null;
        $content = $message['content'] ?? null;
        if (null === $content) {
            // A reasoning model can return null content when the output budget ran out
            // during its hidden reasoning; that is a truncation, not a broken body.
            if ('length' === $finishReason) {
                throw AiUnavailableException::create(AiUnavailableException::REASON_TRUNCATED);
            }

            throw AiUnavailableException::create(AiUnavailableException::REASON_MALFORMED_BODY);
        }

        $text = $this->withoutReasoningBlocks($this->textOf($content));

        if ('' === trim($text) && 'length' === $finishReason) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_TRUNCATED);
        }

        $usage = $data['usage'] ?? [];
        if (!\is_array($usage)) {
            $usage = [];
        }

        return new AiResponse(
            $text,
            \is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : 0,
            \is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : 0,
            \is_string($data['model'] ?? null) ? $data['model'] : $this->model,
        );
    }

    /**
     * `content` is either a string or a list of parts, where only `text` parts carry
     * answer text. Any other shape is a malformed body, not an empty answer.
     */
    private function textOf(mixed $content): string
    {
        if (\is_string($content)) {
            return $content;
        }

        if (!\is_array($content)) {
            throw AiUnavailableException::create(AiUnavailableException::REASON_MALFORMED_BODY);
        }

        $text = '';
        foreach ($content as $part) {
            if (\is_array($part) && \is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    private function withoutReasoningBlocks(string $text): string
    {
        $text = (string) preg_replace(self::REASONING_BLOCK, '', $text);

        // An opening block with no closing tag: a truncated deliberation is still not
        // part of the answer.
        return (string) preg_replace('~<think(?:ing)?\b[^>]*>.*$~is', '', $text);
    }
}
