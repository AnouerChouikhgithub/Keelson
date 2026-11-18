<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiFeatureFlags;
use App\Entity\Membership;
use App\Exception\ApiException;
use App\Repository\MembershipRepository;
use App\Repository\OrganizationAiSettingsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The caller's AI eligibility.
 *
 * `organizationId` is optional; without it the caller's first active organization (the
 * oldest membership) is used, matching how the SPA picks a default tenant. An explicit
 * id goes through the same active-membership lookup as every tenant route, so a foreign
 * one is `404`.
 */
#[Route('/api/ai')]
final class AiStatusController extends AbstractController
{
    use CurrentPrincipalTrait;

    public function __construct(
        private readonly AiFeatureFlags $flags,
        private readonly MembershipRepository $memberships,
        private readonly OrganizationAiSettingsRepository $settings,
    ) {
    }

    #[Route('/status', name: 'app_api_ai_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $principal = $this->currentPrincipal();

        $organizationId = trim((string) $request->query->get('organizationId', ''));
        if ('' !== $organizationId) {
            $membership = $this->memberships->findForOrganizationAndPrincipal($organizationId, $principal->getId());
            if (!$membership instanceof Membership) {
                throw ApiException::notFound();
            }
        } else {
            $memberships = $this->memberships->findActiveForPrincipal($principal->getId());
            $organizationId = [] === $memberships ? '' : $memberships[0]->getOrganization()->getId();
        }

        $settings = '' === $organizationId ? null : $this->settings->findForOrganization($organizationId);
        $period = (new \DateTimeImmutable())->format('Y-m');

        return $this->json([
            'enabled' => $this->flags->isGloballyEnabled(),
            'organizationEnabled' => null !== $settings && $settings->isEnabled(),
            'budgetRemainingTokens' => null === $settings ? 0 : $settings->remainingTokens($period),
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store, private']);
    }
}
