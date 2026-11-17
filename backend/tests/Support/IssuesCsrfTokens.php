<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Hands out the CSRF token an unsafe API request must echo.
 *
 * The token lives in the session, so the only honest way to obtain it is the
 * endpoint that publishes it: `GET /api/session`. Requesting it (instead of
 * reaching into the token manager) means every test exercises the same flow the
 * SPA uses, and a test cannot pass with a token the application would never
 * issue.
 */
trait IssuesCsrfTokens
{
    protected function csrfToken(KernelBrowser $client): string
    {
        $client->request('GET', '/api/session');
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $token = \is_array($payload) ? ($payload['csrfToken'] ?? null) : null;
        if (!\is_string($token) || '' === $token) {
            throw new \RuntimeException('No CSRF token was issued: is the client authenticated?');
        }

        return $token;
    }
}
