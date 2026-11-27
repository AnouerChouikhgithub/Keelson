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
 * debugging surface for the caller. The reasons are deliberately specific — a
 * truncated reasoning model, an empty sanitised answer and an unparseable body used to
 * share one code, which made a real incident undiagnosable from the ledger alone.
 */
final class AiUnavailableException extends \App\Exception\ApiException
{
    public const REASON_NOT_CONFIGURED = 'not_configured';
    public const REASON_TIMEOUT = 'timeout';
    public const REASON_TRANSPORT = 'transport';
    public const REASON_SERVER_ERROR = 'server_error';

    /**
     * The body did not have the shape the contract requires: not JSON, no choices,
     * no message, or a content value that is neither text nor a list of text parts.
     */
    public const REASON_MALFORMED_BODY = 'malformed_body';

    /** The provider stopped at the token limit before writing any visible answer. */
    public const REASON_TRUNCATED = 'truncated';

    /** The provider answered, sanitising left nothing usable, and it was not a truncation. */
    public const REASON_EMPTY_AFTER_SANITIZE = 'empty_after_sanitize';

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
