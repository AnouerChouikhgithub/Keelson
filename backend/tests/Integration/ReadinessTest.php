<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `/readyz` is the probe a load balancer uses: 200 only when the database is
 * reachable. It runs against the disposable test database, never the shared
 * Compose volume.
 */
final class ReadinessTest extends WebTestCase
{
    public function testReadinessReportsDatabaseConnectivity(): void
    {
        $client = static::createClient();
        $client->request('GET', '/readyz');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ready', $payload['status']);
        self::assertSame('ok', $payload['checks']['database']);
    }

    public function testReadinessAnswersWithoutAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/readyz', server: ['HTTP_ACCEPT' => 'text/html']);

        // A browser-style request must not be redirected to OIDC.
        self::assertResponseStatusCodeSame(200);
    }

    public function testResponseCarriesACorrelationId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/readyz');

        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) $client->getResponse()->headers->get('X-Request-Id'));
    }

    public function testAWellFormedClientRequestIdIsEchoed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/readyz', server: ['HTTP_X_REQUEST_ID' => 'probe-12345678']);

        self::assertResponseHeaderSame('X-Request-Id', 'probe-12345678');
    }
}
