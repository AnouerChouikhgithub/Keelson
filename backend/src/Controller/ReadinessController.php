<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Readiness probe: the process is up *and* its required dependencies are usable.
 *
 * PostgreSQL is required for every tenant operation, so an unavailable database
 * makes the instance not ready. The check is a cheap `SELECT 1` plus one catalog
 * read; it never reads tenant rows, echoes the DSN or returns the driver message,
 * so the probe is safe to expose to a load balancer.
 *
 * The second check is the tenant isolation itself. Row-level security is only a
 * guarantee when the connecting role is neither a superuser nor BYPASSRLS: either
 * attribute silently exempts it from every policy, which would leave a deployment
 * that looks protected with nothing but the application filters. Reporting that as
 * "ready" would be the worst possible answer, so it is a 503 with the reason
 * visible in the body — no tenant data, no credentials.
 *
 * Liveness stays at `/healthz` and deliberately checks nothing, so a database
 * outage does not cause an orchestrator to restart healthy processes.
 */
final class ReadinessController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/readyz', name: 'app_readyz', methods: ['GET'])]
    public function readiness(): JsonResponse
    {
        $database = 'ok';
        try {
            $this->connection->executeQuery('SELECT 1')->free();
        } catch (\Throwable) {
            // The driver message can contain host/credentials, so it is not
            // logged or returned here; the connection failure is visible in
            // the application log of the failing request.
            $database = 'unavailable';
        }

        // Without a connection there is nothing to ask about the role; the
        // database check already decided the answer.
        $isolation = 'unavailable' === $database ? 'unavailable' : $this->tenantIsolation();

        $ready = 'ok' === $database && 'ok' === $isolation;

        return new JsonResponse(
            [
                'status' => $ready ? 'ready' : 'unavailable',
                'checks' => ['database' => $database, 'rowLevelSecurity' => $isolation],
            ],
            $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * `ok` when the current role is subject to the row-level security policies.
     *
     * `bypass` means the role is a superuser or has BYPASSRLS, so PostgreSQL skips
     * every policy for it; `unknown` means the catalog read failed for a reason
     * other than connectivity.
     */
    private function tenantIsolation(): string
    {
        try {
            $role = $this->connection->fetchAssociative(
                'SELECT r.rolsuper::int AS is_superuser, r.rolbypassrls::int AS bypass_rls
                   FROM pg_catalog.pg_roles r
                  WHERE r.rolname = current_user',
            );
        } catch (\Throwable) {
            return 'unknown';
        }

        if (false === $role) {
            return 'unknown';
        }

        return 1 === (int) $role['is_superuser'] || 1 === (int) $role['bypass_rls'] ? 'bypass' : 'ok';
    }
}
