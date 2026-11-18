<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\OrganizationAiSettings;
use App\Entity\SecurityEvent;
use App\Exception\ApiException;
use App\Http\InputHydrator;
use App\Http\JsonBody;
use App\Input\UpdateAiSettingsInput;
use App\Repository\OrganizationAiSettingsRepository;
use App\Repository\OrganizationRepository;
use App\Security\OrganizationVoter;
use App\Service\SecurityEventRecorder;
use App\Service\TransactionBoundary;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The tenant's AI opt-in and monthly budget.
 *
 * Both operations require the `MANAGE` grant (Owner or Admin). A Viewer, a Member or a
 * foreign tenant gets the same `404`, so the settings endpoint does not confirm that a
 * tenant exists. Turning AI on writes a `security_events` row: it is the moment the
 * organization starts sending data to a provider, which is exactly what an audit trail
 * is for.
 */
#[Route('/api/organizations')]
final class OrganizationAiSettingsController extends AbstractController
{
    use CurrentPrincipalTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrganizationRepository $organizations,
        private readonly OrganizationAiSettingsRepository $settings,
        private readonly InputHydrator $hydrator,
        private readonly TransactionBoundary $transactions,
        private readonly SecurityEventRecorder $securityEvents,
    ) {
    }

    #[Route('/{organizationId}/ai-settings', name: 'app_api_organization_ai_settings_read', methods: ['GET'])]
    public function read(string $organizationId): JsonResponse
    {
        $organization = $this->authorize($organizationId);

        return $this->json($this->serialize($this->settings->findForOrganization($organization->getId()), $organization->getId()));
    }

    #[Route('/{organizationId}/ai-settings', name: 'app_api_organization_ai_settings_update', methods: ['PUT'])]
    public function update(string $organizationId, Request $request): JsonResponse
    {
        $principal = $this->currentPrincipal();
        $organization = $this->authorize($organizationId);
        $input = $this->hydrator->hydrate(UpdateAiSettingsInput::class, JsonBody::decode($request));
        $enabled = true === $input->enabled;
        $monthlyTokenBudget = (int) $input->monthlyTokenBudget;

        try {
            $payload = $this->transactions->run(function () use ($organization, $enabled, $monthlyTokenBudget, $principal): array {
                $settings = $this->settings->findForOrganization($organization->getId());
                $wasEnabled = null !== $settings && $settings->isEnabled();
                if (null === $settings) {
                    $settings = new OrganizationAiSettings($organization, $enabled, $monthlyTokenBudget);
                    $this->entityManager->persist($settings);
                } else {
                    $settings->update($enabled, $monthlyTokenBudget);
                }

                if ($enabled && !$wasEnabled) {
                    $this->securityEvents->record(SecurityEvent::AI_ENABLED, $principal, [
                        'organizationId' => $organization->getId(),
                        'monthlyTokenBudget' => $monthlyTokenBudget,
                    ]);
                }

                $this->entityManager->flush();

                return $this->serialize($settings, $organization->getId());
            });
        } catch (UniqueConstraintViolationException) {
            // Two admins enabled AI at the same time; the unique index is the authority.
            throw ApiException::conflict('The AI settings were changed concurrently; reload and retry.');
        }

        return $this->json($payload, Response::HTTP_OK, ['Cache-Control' => 'no-store, private']);
    }

    /** Resolves the tenant through the caller's active membership, with the MANAGE grant. */
    private function authorize(string $organizationId): Organization
    {
        if (!$this->isGranted(OrganizationVoter::MANAGE, $organizationId)) {
            throw ApiException::notFound();
        }

        $organization = $this->organizations->findActiveForPrincipalInOrganization(
            $organizationId,
            $this->currentPrincipal()->getId(),
        );
        if (null === $organization) {
            throw ApiException::notFound();
        }

        return $organization;
    }

    /** @return array{enabled: bool, monthlyTokenBudget: int, budgetRemainingTokens: int} */
    private function serialize(?OrganizationAiSettings $settings, string $organizationId): array
    {
        $period = (new \DateTimeImmutable())->format('Y-m');

        return [
            'enabled' => null !== $settings && $settings->isEnabled(),
            'monthlyTokenBudget' => $settings?->getMonthlyTokenBudget() ?? 0,
            'budgetRemainingTokens' => $settings?->remainingTokens($period) ?? 0,
        ];
    }
}
