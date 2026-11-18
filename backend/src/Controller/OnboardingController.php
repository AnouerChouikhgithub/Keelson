<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ApiException;
use App\Onboarding\OnboardingFacts;
use App\Security\OrganizationVoter;
use App\Util\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The getting-started checklist of one organization.
 *
 * A read, so any active member may see it — a new teammate benefits from the same
 * checklist as the Owner. The organization in the path is re-authorized through the
 * membership voter, so a foreign or unknown tenant is `404`, never a 403 that would
 * confirm it exists.
 */
final class OnboardingController extends AbstractController
{
    public function __construct(private readonly OnboardingFacts $facts)
    {
    }

    #[Route('/api/organizations/{organizationId}/onboarding', name: 'app_api_onboarding', methods: ['GET'])]
    public function show(string $organizationId): JsonResponse
    {
        if (!Uuid::isValid($organizationId)) {
            throw ApiException::invalidId();
        }
        if (!$this->isGranted(OrganizationVoter::READ, $organizationId)) {
            throw ApiException::notFound();
        }

        return $this->json($this->facts->checklist($organizationId), 200, ['Cache-Control' => 'no-store, private']);
    }
}
