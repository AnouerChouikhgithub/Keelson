<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Readiness probe: the process is up *and* its required dependency is usable.
 *
 * PostgreSQL is required for every tenant operation, so an unavailable database
 * makes the instance not ready. The check is a single cheap `SELECT 1`; it
 * never reads tenant rows, echoes the DSN or returns the driver message, so the
 * probe is safe to expose to a load balancer.
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

        $ready = 'ok' === $database;

        return new JsonResponse(
            ['status' => $ready ? 'ready' : 'unavailable', 'checks' => ['database' => $database]],
            $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
            ['Cache-Control' => 'no-store'],
        );
    }
}
