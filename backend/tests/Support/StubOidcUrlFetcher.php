<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Drenso\OidcBundle\OidcUrlFetcher;

/**
 * Offline discovery document for tests.
 *
 * Tests must never depend on a reachable identity provider: the logout test
 * needs a deterministic `end_session_endpoint`, and CI must not make outbound
 * auth requests. Only the OpenID discovery URL is answered; any other fetch is
 * a programming error in the test environment.
 */
final class StubOidcUrlFetcher extends OidcUrlFetcher
{
    public function fetchUrl(string $url, ?array $params = null, array $headers = []): string
    {
        if (!str_contains($url, '.well-known/openid-configuration')) {
            throw new \RuntimeException('Tests must not call the identity provider: '.$url);
        }

        $issuer = rtrim((string) ($_SERVER['OIDC_ISSUER'] ?? $_ENV['OIDC_ISSUER'] ?? 'https://issuer.example.test/oidc'), '/');

        return json_encode([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/auth',
            'token_endpoint' => $issuer.'/token',
            'userinfo_endpoint' => $issuer.'/userinfo',
            'jwks_uri' => $issuer.'/jwks',
            'end_session_endpoint' => $issuer.'/session/end',
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ], JSON_THROW_ON_ERROR);
    }
}
