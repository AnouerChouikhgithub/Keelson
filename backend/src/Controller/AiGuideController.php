<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Principal;
use App\Exception\ApiException;
use App\Guide\GuideService;
use App\Http\InputHydrator;
use App\Http\JsonBody;
use App\Input\GuideQuestionInput;
use App\Repository\MembershipRepository;
use App\Util\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The product assistant.
 *
 * The body names a question; the tenant is a selector like everywhere else, so an
 * explicit `organizationId` goes through the same active-membership lookup and a
 * foreign one is `404`. Omitting it uses the caller's first active organization (the
 * oldest membership), matching `GET /api/ai/status` and the SPA's default tenant.
 *
 * Validation happens before any lookup: a two-character question or an unsupported
 * locale is a `422`, not a provider call.
 */
final class AiGuideController extends AbstractController
{
    use CurrentPrincipalTrait;

    public function __construct(
        private readonly GuideService $guide,
        private readonly MembershipRepository $memberships,
        private readonly InputHydrator $hydrator,
    ) {
    }

    #[Route('/api/ai/guide', name: 'app_api_ai_guide', methods: ['POST'])]
    public function guide(Request $request): JsonResponse
    {
        $principal = $this->currentPrincipal();
        $input = $this->hydrator->hydrate(GuideQuestionInput::class, JsonBody::decode($request));

        return $this->json(
            $this->guide->answer(
                $principal,
                $this->organizationId($principal, $input->organizationId),
                trim($input->question),
                $input->locale,
            ),
            200,
            ['Cache-Control' => 'no-store, private'],
        );
    }

    /** The explicit id is validated; the default is the caller's oldest membership. */
    private function organizationId(Principal $principal, ?string $requested): string
    {
        $requested = null === $requested ? '' : trim($requested);
        if ('' !== $requested) {
            if (!Uuid::isValid($requested)) {
                throw ApiException::invalidId();
            }

            return $requested;
        }

        $memberships = $this->memberships->findActiveForPrincipal($principal->getId());
        if ([] === $memberships) {
            throw ApiException::notFound();
        }

        return $memberships[0]->getOrganization()->getId();
    }
}
