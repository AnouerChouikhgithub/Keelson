<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\AiRequest;
use App\Ai\Exception\AiUnavailableException;
use App\Ai\Http\AiHttpResponse;
use App\Ai\Http\AiHttpTransport;
use App\Ai\HttpAiProvider;
use PHPUnit\Framework\TestCase;

/**
 * The HTTP provider's job is to normalise: retry one transient failure, refuse a
 * second one, and never let a malformed body through as an answer.
 */
final class HttpAiProviderTest extends TestCase
{
    public function testAServerErrorIsRetriedExactlyOnce(): void
    {
        $transport = $this->transport([
            new AiHttpResponse(502, 'bad gateway'),
            new AiHttpResponse(200, $this->body('Answer', 10, 5)),
        ]);

        $response = $this->provider($transport)->complete($this->request());

        self::assertSame('Answer', $response->text);
        self::assertSame(15, $response->totalTokens());
        self::assertCount(2, $transport->requests);
    }

    public function testASecondServerErrorIsUnavailable(): void
    {
        $transport = $this->transport([
            new AiHttpResponse(503, 'down'),
            new AiHttpResponse(503, 'still down'),
            new AiHttpResponse(200, $this->body('never used', 1, 1)),
        ]);

        try {
            $this->provider($transport)->complete($this->request());
            self::fail('A second 5xx must not be turned into an answer.');
        } catch (AiUnavailableException $exception) {
            self::assertSame('ai_unavailable', $exception->getErrorCode());
            self::assertSame(AiUnavailableException::REASON_SERVER_ERROR, $exception->getReason());
        }

        self::assertCount(2, $transport->requests, 'Only one retry is allowed.');
    }

    public function testA4xxIsNotRetried(): void
    {
        $transport = $this->transport([new AiHttpResponse(401, 'no')]);

        try {
            $this->provider($transport)->complete($this->request());
            self::fail('A rejected credential must not be retried.');
        } catch (AiUnavailableException $exception) {
            self::assertSame(AiUnavailableException::REASON_TRANSPORT, $exception->getReason());
        }

        self::assertCount(1, $transport->requests);
    }

    public function testAMalformedBodyIsUnavailable(): void
    {
        $transport = $this->transport([new AiHttpResponse(200, '{"choices":[]}')]);

        try {
            $this->provider($transport)->complete($this->request());
            self::fail('A body without a message must not become an answer.');
        } catch (AiUnavailableException $exception) {
            self::assertSame(AiUnavailableException::REASON_MALFORMED_BODY, $exception->getReason());
        }
    }

    public function testATransportFailureIsUnavailable(): void
    {
        $transport = new class implements AiHttpTransport {
            public function post(string $url, array $headers, string $body, int $connectTimeoutMs, int $totalTimeoutMs): AiHttpResponse
            {
                if (5000 !== $connectTimeoutMs || 30000 !== $totalTimeoutMs) {
                    throw new \RuntimeException('The provider must pass the product timeouts through.');
                }

                throw AiUnavailableException::create(AiUnavailableException::REASON_TIMEOUT);
            }
        };

        $this->expectException(AiUnavailableException::class);
        $this->provider($transport)->complete($this->request());
    }

    public function testMissingConfigurationIsUnavailable(): void
    {
        $provider = new HttpAiProvider($this->transport([]), '', 'model', 'https://api.example.test/v1');

        try {
            $provider->complete($this->request());
            self::fail('An empty key must not reach the network.');
        } catch (AiUnavailableException $exception) {
            self::assertSame(AiUnavailableException::REASON_NOT_CONFIGURED, $exception->getReason());
        }
    }

    /**
     * Live providers do not always return content as one string. Every shape captured
     * from the real run gets a regression test here, so a parser change cannot quietly
     * reintroduce the fallback every summary hit.
     */
    public function testContentAsTextPartsIsJoined(): void
    {
        $transport = $this->transport([new AiHttpResponse(200, $this->bodyOf([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => [
                    ['type' => 'text', 'text' => 'Two invoices are '],
                    ['type' => 'text', 'text' => 'overdue.'],
                ]],
            ]],
        ]))]);

        self::assertSame('Two invoices are overdue.', $this->provider($transport)->complete($this->request())->text);
    }

    public function testReasoningFieldsAreIgnored(): void
    {
        $transport = $this->transport([new AiHttpResponse(200, $this->bodyOf([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'content' => 'No invoice is overdue.',
                    'reasoning' => 'The user has three invoices; I should not mention the number 3...',
                    'reasoning_content' => 'more hidden deliberation',
                ],
            ]],
        ]))]);

        self::assertSame('No invoice is overdue.', $this->provider($transport)->complete($this->request())->text);
    }

    public function testClosedThinkBlocksAreStrippedBeforeTheAnswer(): void
    {
        $transport = $this->transport([new AiHttpResponse(200, $this->bodyOf([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => "<think>the total is 99</think>As of today, 3 invoices are overdue."],
            ]],
        ]))]);

        self::assertSame('As of today, 3 invoices are overdue.', $this->provider($transport)->complete($this->request())->text);
    }

    public function testAnUnclosedThinkBlockIsRemovedToTheEnd(): void
    {
        $transport = $this->transport([new AiHttpResponse(200, $this->bodyOf([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => "An answer.\n<think>unfinished deliberation"],
            ]],
        ]))]);

        self::assertSame('An answer.', trim($this->provider($transport)->complete($this->request())->text));
    }

    public function testEmptyContentAtTheTokenLimitIsTruncated(): void
    {
        $transport = $this->transport([new AiHttpResponse(200, $this->bodyOf([
            'choices' => [[
                'finish_reason' => 'length',
                'message' => ['content' => '', 'reasoning' => str_repeat('thinking ', 40)],
            ]],
            'usage' => ['prompt_tokens' => 402, 'completion_tokens' => 320],
        ]))]);

        try {
            $this->provider($transport)->complete($this->request());
            self::fail('An empty answer cut off at the limit must be a truncation.');
        } catch (AiUnavailableException $exception) {
            self::assertSame(AiUnavailableException::REASON_TRUNCATED, $exception->getReason());
        }
    }

    public function testNullContentAtTheTokenLimitIsTruncated(): void
    {
        $transport = $this->transport([new AiHttpResponse(200, $this->bodyOf([
            'choices' => [[
                'finish_reason' => 'length',
                'message' => ['content' => null, 'reasoning' => 'hidden'],
            ]],
        ]))]);

        try {
            $this->provider($transport)->complete($this->request());
            self::fail('A null answer cut off at the limit must be a truncation.');
        } catch (AiUnavailableException $exception) {
            self::assertSame(AiUnavailableException::REASON_TRUNCATED, $exception->getReason());
        }
    }

    public function testNullContentWithoutTruncationIsMalformed(): void
    {
        $transport = $this->transport([new AiHttpResponse(200, $this->bodyOf([
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => null]]],
        ]))]);

        try {
            $this->provider($transport)->complete($this->request());
            self::fail('A null answer with no truncation is a broken body.');
        } catch (AiUnavailableException $exception) {
            self::assertSame(AiUnavailableException::REASON_MALFORMED_BODY, $exception->getReason());
        }
    }

    public function testAnEmptyStringWithoutTruncationIsReturnedForTheGatewayToReject(): void
    {
        $transport = $this->transport([new AiHttpResponse(200, $this->bodyOf([
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '   ']]],
        ]))]);

        self::assertSame('   ', $this->provider($transport)->complete($this->request())->text);
    }

    public function testReasoningEffortIsSentOnlyWhenConfigured(): void
    {
        foreach (['' => false, 'low' => true] as $effort => $expected) {
            $transport = new class implements AiHttpTransport {
                /** @var list<string> */
                public array $bodies = [];

                public function post(string $url, array $headers, string $body, int $connectTimeoutMs, int $totalTimeoutMs): AiHttpResponse
                {
                    $this->bodies[] = $body;

                    return new AiHttpResponse(200, '{"choices":[{"message":{"content":"ok"}}]}');
                }
            };

            $provider = new HttpAiProvider($transport, 'test-key', 'test-model', 'https://api.example.test/v1', $effort);
            $provider->complete($this->request());

            $payload = json_decode($transport->bodies[0], true, 64, \JSON_THROW_ON_ERROR);
            self::assertSame($expected, array_key_exists('reasoning_effort', $payload));
        }
    }

    /** @param array<string, mixed> $body */
    private function bodyOf(array $body): string
    {
        return json_encode(['model' => 'test-model', 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]] + $body, \JSON_THROW_ON_ERROR);
    }

    /** @param list<AiHttpResponse> $responses */
    private function transport(array $responses): object
    {
        return new class($responses) implements AiHttpTransport {
            /** @var list<string> */
            public array $requests = [];

            /** @param list<AiHttpResponse> $responses */
            public function __construct(private array $responses)
            {
            }

            public function post(string $url, array $headers, string $body, int $connectTimeoutMs, int $totalTimeoutMs): AiHttpResponse
            {
                $this->requests[] = $url;
                $response = array_shift($this->responses);
                if (null === $response) {
                    throw new \RuntimeException('No scripted response left.');
                }

                return $response;
            }
        };
    }

    private function provider(object $transport): HttpAiProvider
    {
        self::assertInstanceOf(AiHttpTransport::class, $transport);

        return new HttpAiProvider($transport, 'test-key', 'test-model', 'https://api.example.test/v1');
    }

    private function request(): AiRequest
    {
        return new AiRequest('guide', 'You are helpful.', 'Hello?', 100, 'org-id', 'principal-id');
    }

    private function body(string $content, int $promptTokens, int $completionTokens): string
    {
        return json_encode([
            'model' => 'test-model',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens],
        ], \JSON_THROW_ON_ERROR);
    }
}
