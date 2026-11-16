<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Unauthenticated liveness probe.
 *
 * Intentionally reports only that the application boots: it performs no
 * database or provider call, so a probe cannot leak tenant data or turn a
 * dependency outage into an authentication attempt. Readiness is verified by
 * the operator against PostgreSQL/Compose (see README.md).
 */
final class HealthController
{
    #[Route('/healthz', name: 'app_healthz', methods: ['GET'])]
    public function healthz(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'service' => 'keelson-api',
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
