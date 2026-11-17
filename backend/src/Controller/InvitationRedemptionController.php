<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Invitation;
use App\Entity\Principal;
use App\Http\InputHydrator;
use App\Http\JsonBody;
use App\Input\AcceptInvitationInput;
use App\Service\InvitationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The two endpoints behind an invitation link.
 *
 * Both are reachable without a session, because the point of the link is to reach
 * someone who has no account yet. They are enumeration-safe by construction: the
 * preview returns only what the recipient already knows (the organization name,
 * the role, their own address), and accepting never says whether an address has
 * an account — it either accepts, or asks the caller to sign in.
 */
final class InvitationRedemptionController extends AbstractController
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly InputHydrator $hydrator,
    ) {
    }

    #[Route('/api/invitations/{token}', name: 'app_api_invitations_preview', methods: ['GET'])]
    public function preview(string $token): JsonResponse
    {
        $invitation = $this->invitations->preview($token);

        return $this->json([
            'organizationName' => $invitation->getOrganization()->getName(),
            'role' => $invitation->getRole(),
            'email' => $invitation->getEmail(),
            'expired' => $invitation->isExpired(),
            'accepted' => Invitation::STATUS_ACCEPTED === $invitation->getStatus(),
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/auth/accept-invitation', name: 'app_api_auth_accept_invitation', methods: ['POST'])]
    public function accept(Request $request): JsonResponse
    {
        $input = $this->hydrator->hydrate(AcceptInvitationInput::class, JsonBody::decode($request));
        $caller = $this->getUser();
        $result = $this->invitations->accept(
            $input->token,
            $caller instanceof Principal ? $caller : null,
            $input->password,
            $input->displayName,
        );

        return $this->json([
            'organization' => [
                'id' => $result['organization']->getId(),
                'name' => $result['organization']->getName(),
            ],
            'role' => $result['membership']->getRole(),
            'accountCreated' => $result['created'],
        ], $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }
}
