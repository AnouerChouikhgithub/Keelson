<?php

declare(strict_types=1);

namespace App\Ai\Http;

use App\Ai\Exception\AiUnavailableException;

/**
 * A minimal cURL transport.
 *
 * Symfony's HttpClient component is not installed in this project and the sprint rule
 * is no new Composer dependency, so the transport is the one already available on the
 * PHP image (`ext-curl`). It is deliberately tiny and behind
 * {@see AiHttpTransport}: when HttpClient is added for other reasons, replacing this
 * class is local to the AI namespace.
 *
 * Redirects are not followed: an AI base URL that redirects is a configuration error,
 * not something a customer prompt should be able to steer.
 */
final class CurlAiTransport implements AiHttpTransport
{
    private const MAX_BODY_BYTES = 1048576;

    public function post(string $url, array $headers, string $body, int $connectTimeoutMs, int $totalTimeoutMs): AiHttpResponse
    {
        $handle = curl_init($url);
        if (false === $handle) {
            throw AiUnavailableException::create();
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $totalTimeoutMs,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE => self::MAX_BODY_BYTES,
        ]);

        $response = curl_exec($handle);
        if (false === $response) {
            throw AiUnavailableException::create();
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new AiHttpResponse($status, (string) $response);
    }
}
