<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Membership;
use App\Entity\Principal;
use App\Repository\MembershipRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Organizations the current principal may work in.
 *
 * The browser needs the organization IDs and the caller's role to render the
 * tenant selector; without this read there is no way to reach the existing
 * `/api/organizations/{organizationId}/...` routes from a real session. It is a
 * read of the caller's own memberships only: the repository requires an active
 * membership on an active organization, and no request parameter can widen it.
 *
 * The role is the caller's role, not a permission grant: every tenant endpoint
 * still authorizes independently through InvoiceVoter.
 */
final class MeOrganizationsController extends AbstractController
{
    public function __construct(private readonly MembershipRepository $memberships)
    {
    }

    #[Route('/api/me/organizations', name: 'app_api_me_organizations', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $principal = $this->getUser();
        if (!$principal instanceof Principal) {
            throw $this->createAccessDeniedException();
        }

        $items = array_map(static function (Membership $membership): array {
            $organization = $membership->getOrganization();

            return [
                'id' => $organization->getId(),
                'name' => $organization->getName(),
                'subdomain' => $organization->getSubdomain(),
                'role' => $membership->getRole(),
            ];
        }, $this->memberships->findActiveForPrincipal($principal->getId()));

        return $this->json(['items' => $items], 200, ['Cache-Control' => 'no-store, private']);
    }
}
