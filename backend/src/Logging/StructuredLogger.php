<?php

declare(strict_types=1);

namespace App\Logging;

use Psr\Log\LoggerInterface;

/**
 * Writes one JSON object per log line so logs can be filtered by level and
 * followed across a request by `request_id`.
 *
 * It decorates Symfony's minimal PSR-3 logger, which means every log the
 * framework and the application emit goes through the same redaction and
 * correlation rules: there is one log stream, not a second parallel one.
 *
 * Redaction is key-based plus a small set of value patterns (connection
 * strings, `password=…`). It is a safety net, not a reason to pass secrets as
 * log context in the first place.
 */
final class StructuredLogger implements LoggerInterface
{
    private const SENSITIVE_KEY = '/(pass(word|wd)?|secret|token|cookie|authorization|api[_-]?key|csrf|session|dsn|database_url|credential)/i';
    private const REDACTED = '[redacted]';
    private const MAX_DEPTH = 5;
    private const MAX_ENTRIES = 50;
    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    /** @var resource|null */
    private $stream;

    private readonly int $minimumLevelIndex;

    public function __construct(
        private readonly LoggerInterface $fallback,
        private readonly RequestContext $requestContext,
        private readonly string $destination = 'php://stderr',
        string $minimumLevel = 'warning',
    ) {
        // Symfony's minimal logger defaults to ERROR on stderr; warning keeps
        // authentication and 4xx failures visible without per-request debug
        // noise. LOG_LEVEL overrides it during an investigation.
        $this->minimumLevelIndex = self::LEVELS[strtolower($minimumLevel)] ?? self::LEVELS['warning'];
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $levelName = \is_string($level) ? strtolower($level) : (string) $level;
        if (!isset(self::LEVELS[$levelName]) || self::LEVELS[$levelName] < $this->minimumLevelIndex) {
            return;
        }

        $channel = \is_string($context['channel'] ?? null) ? $context['channel'] : 'app';
        unset($context['channel']);

        $record = [
            'time' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            'level' => $levelName,
            'channel' => $channel,
            'message' => $this->redact((string) $message),
            'request_id' => $this->requestContext->requestId(),
            'context' => $this->sanitize($context),
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (false === $line) {
            // A record that cannot be encoded must still be observable, but its
            // raw context may contain the very value that broke encoding.
            $this->fallback->error('Unable to encode a structured log record for {channel}.', ['channel' => $channel]);

            return;
        }

        $stream = $this->stream ??= $this->openStream();
        if (null === $stream) {
            $this->fallback->error('Unable to open the structured log stream.');

            return;
        }

        fwrite($stream, $line."\n");
    }

    /** @param array<mixed> $context */
    private function sanitize(array $context, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['truncated' => '[depth limit]'];
        }

        $sanitized = [];
        $count = 0;
        foreach ($context as $key => $value) {
            if (++$count > self::MAX_ENTRIES) {
                $sanitized['_truncated'] = '[entry limit]';
                break;
            }
            $name = (string) $key;
            $sanitized[$name] = preg_match(self::SENSITIVE_KEY, $name) ? self::REDACTED : $this->sanitizeValue($value, $depth + 1);
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return '[depth limit]';
        }
        if ($value instanceof \Throwable) {
            return $this->throwable($value);
        }
        if (\is_array($value)) {
            return $this->sanitize($value, $depth);
        }
        if (\is_object($value)) {
            return $value instanceof \Stringable ? $this->redact((string) $value) : $value::class;
        }
        if (\is_string($value)) {
            return $this->redact($value);
        }
        if (\is_resource($value)) {
            return '[resource]';
        }

        return \is_scalar($value) || null === $value ? $value : get_debug_type($value);
    }

    /** @return array<string, mixed> */
    private function throwable(\Throwable $throwable): array
    {
        $previous = $throwable->getPrevious();

        return [
            'class' => $throwable::class,
            'message' => $this->redact($throwable->getMessage()),
            'code' => $throwable->getCode(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'previous' => null === $previous ? null : $previous::class.': '.$this->redact($previous->getMessage()),
            // Server-side only: getTraceAsString() does not include arguments.
            'trace' => $throwable->getTraceAsString(),
        ];
    }

    private function redact(string $value): string
    {
        $patterns = [
            '/((?:password|passwd|pwd|secret|token|api[_-]?key|client_secret|authorization)\s*[=:]\s*)([^\s&,;"\']+)/i' => '$1'.self::REDACTED,
            '#(postgres(?:ql)?://)[^@\s]+@#i' => '$1'.self::REDACTED.'@',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $value) ?? $value;
    }

    /** @return resource|null */
    private function openStream()
    {
        $stream = @fopen($this->destination, 'ab');

        return false === $stream ? null : $stream;
    }
}
