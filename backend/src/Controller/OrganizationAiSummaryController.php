<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ApiException;
use App\Security\OrganizationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Routed stub for the organization summaries.
 *
 * Any active member may ask; the tenant is resolved through the voter before the stub
 * answers, so a foreign or unknown organization is still `404` rather than `501`.
 */
final class OrganizationAiSummaryController extends AbstractController
{
    #[Route(
        '/api/organizations/{organizationId}/ai/summaries/{kind}',
        name: 'app_api_organization_ai_summary',
        methods: ['POST'],
        requirements: ['kind' => 'organization|invoices|customers'],
    )]
    public function summary(string $organizationId, string $kind): never
    {
        if (!$this->isGranted(OrganizationVoter::READ, $organizationId)) {
            throw ApiException::notFound();
        }

        throw new ApiException(Response::HTTP_NOT_IMPLEMENTED, 'not_implemented', sprintf('The "%s" AI summary is not implemented yet.', $kind));
    }
}
