<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * API failures must be JSON documents a client can act on, never Symfony's HTML
 * error page and never an internal exception message.
 */
final class ApiErrorHandlingTest extends WebTestCase
{
    public function testAnonymousApiRequestGetsJson401InsteadOfASignInPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/me/organizations', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('unauthenticated', $payload['error']['code']);
        self::assertArrayNotHasKey('exception', $payload);
        self::assertArrayNotHasKey('trace', $payload);
    }

    public function testUnknownApiRouteReturnsJson404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/does-not-exist', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('not_found', $payload['error']['code']);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Exception', $body);
        self::assertStringNotContainsString('vendor', $body);
    }

    public function testHtmlEvidencePagesAreNotRewrittenByTheApiListener(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        // /login is a public route and redirects to the provider; it must not be
        // answered with the API JSON envelope.
        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('https://', (string) $client->getResponse()->headers->get('Location'));
    }
}
