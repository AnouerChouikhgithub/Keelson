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
            self::assertSame(AiUnavailableException::REASON_MALFORMED, $exception->getReason());
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
