<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `/readyz` is the probe a load balancer uses: 200 only when the database is
 * reachable and the connecting role is actually subject to row-level security.
 * It runs against the disposable test database, never the shared Compose volume.
 */
final class ReadinessTest extends WebTestCase
{
    public function testReadinessReportsDatabaseConnectivity(): void
    {
        $client = static::createClient();
        $client->request('GET', '/readyz');

        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['checks']['database']);

        // The probe must agree with the database it is talking to. The local test
        // database is created by a superuser, which bypasses every policy, so the
        // honest answer there is 503 — and the assertion is not relaxed to avoid
        // it: it checks the probe against `pg_roles` instead of assuming either
        // role.
        $connection = self::getContainer()->get('doctrine')->getConnection();
        $bypasses = 1 === (int) $connection->fetchOne(
            'SELECT r.rolsuper::int | r.rolbypassrls::int FROM pg_catalog.pg_roles r WHERE r.rolname = current_user',
        );

        if ($bypasses) {
            self::assertResponseStatusCodeSame(503);
            self::assertSame('unavailable', $payload['status']);
            self::assertSame('bypass', $payload['checks']['rowLevelSecurity']);
        } else {
            self::assertResponseStatusCodeSame(200);
            self::assertSame('ready', $payload['status']);
            self::assertSame('ok', $payload['checks']['rowLevelSecurity']);
        }
    }

    public function testReadinessAnswersWithoutAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/readyz', server: ['HTTP_ACCEPT' => 'text/html']);

        // A browser-style request must not be redirected to a sign-in page. The
        // status depends on the connecting role's RLS exemption (see the first
        // test); what matters here is that no session is required.
        self::assertContains($client->getResponse()->getStatusCode(), [200, 503]);
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
