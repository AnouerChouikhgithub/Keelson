<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ApiException;
use App\Security\OrganizationVoter;
use App\Service\DashboardService;
use App\Util\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tenant dashboard for any active member.
 *
 * It is a read, so the Viewer role is enough; the authorization is the same
 * membership check every other tenant route uses.
 */
final class DashboardController extends AbstractController
{
    use CurrentPrincipalTrait;

    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    #[Route('/api/organizations/{organizationId}/dashboard', name: 'app_api_dashboard', methods: ['GET'])]
    public function show(string $organizationId): JsonResponse
    {
        if (!Uuid::isValid($organizationId)) {
            throw ApiException::invalidId();
        }
        if (!$this->isGranted(OrganizationVoter::READ, $organizationId)) {
            throw ApiException::notFound();
        }

        return $this->json($this->dashboard->summarize($organizationId), 200, ['Cache-Control' => 'no-store, private']);
    }
}
