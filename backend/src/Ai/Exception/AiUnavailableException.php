<?php

declare(strict_types=1);

namespace App\Ai\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * The provider could not produce an answer: it timed out, answered 5xx twice, asked
 * for credentials this deployment does not have, or returned a body this API cannot
 * parse.
 *
 * The client gets one stable code and message. The reason travels only to the ledger
 * (`ai_requests.error_code`) and the log, so a provider failure does not become a
 * debugging surface for the caller.
 */
final class AiUnavailableException extends \App\Exception\ApiException
{
    public const REASON_NOT_CONFIGURED = 'not_configured';
    public const REASON_TIMEOUT = 'timeout';
    public const REASON_TRANSPORT = 'transport';
    public const REASON_SERVER_ERROR = 'server_error';
    public const REASON_MALFORMED = 'malformed_response';

    private function __construct(private readonly string $reason)
    {
        parent::__construct(
            Response::HTTP_SERVICE_UNAVAILABLE,
            'ai_unavailable',
            'The AI service is temporarily unavailable.',
        );
    }

    public static function create(string $reason = self::REASON_TRANSPORT): self
    {
        return new self($reason);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
