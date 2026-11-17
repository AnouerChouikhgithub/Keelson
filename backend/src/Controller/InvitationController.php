<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Invitation;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Exception\ApiException;
use App\Http\InputHydrator;
use App\Http\JsonBody;
use App\Input\InviteInput;
use App\Repository\InvitationRepository;
use App\Repository\MembershipRepository;
use App\Repository\OrganizationRepository;
use App\Service\InvitationService;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pending invitations of one organization.
 *
 * Owner and Admin may invite; an Admin may only invite as Member or Viewer, for
 * the same reason they cannot promote anyone: the privilege boundary must not be
 * shrinkable from below. The plaintext token is never in a response — it goes
 * out in the email and nowhere else.
 */
#[Route('/api/organizations/{organizationId}/invitations')]
final class InvitationController extends AbstractController
{
    use CurrentPrincipalTrait;

    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly MembershipRepository $memberships,
        private readonly InvitationRepository $invitations,
        private readonly InvitationService $invitationService,
        private readonly EntityManagerInterface $entityManager,
        private readonly InputHydrator $hydrator,
        #[Autowire(service: 'limiter.invitation_resend')]
        private readonly RateLimiterFactory $resendLimiter,
    ) {
    }

    #[Route('', name: 'app_api_invitations_create', methods: ['POST'])]
    public function create(string $organizationId, Request $request): JsonResponse
    {
        $this->assertId($organizationId);
        $principal = $this->currentPrincipal();
        $requester = $this->requesterMembership($organizationId, $principal);
        $organization = $this->organization($organizationId, $principal);

        $input = $this->hydrator->hydrate(InviteInput::class, JsonBody::decode($request));
        $this->assertMayGrant($requester, $input->role);

        $issued = $this->invitationService->invite($organization, $principal, $input->email, $input->role);
        $this->invitationService->sendInvitationEmail($organization, $principal, $issued['invitation']->getEmail(), $input->role, $issued['token']);

        return $this->json(['invitation' => $this->serialize($issued['invitation'])], Response::HTTP_CREATED);
    }

    #[Route('', name: 'app_api_invitations_list', methods: ['GET'])]
    public function list(string $organizationId): JsonResponse
    {
        $this->assertId($organizationId);
        $principal = $this->currentPrincipal();
        $requester = $this->requesterMembership($organizationId, $principal);
        if (!$requester->canAdminister()) {
            throw ApiException::notFound();
        }

        $items = array_map(
            fn (Invitation $invitation): array => $this->serialize($invitation),
            $this->invitations->findForOrganizationList($organizationId, $principal->getId()),
        );

        return $this->json(['items' => $items, 'nextCursor' => null], Response::HTTP_OK, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/{invitationId}/resend', name: 'app_api_invitations_resend', methods: ['POST'])]
    public function resend(string $organizationId, string $invitationId): JsonResponse
    {
        $this->assertId($organizationId);
        $this->assertId($invitationId);
        $principal = $this->currentPrincipal();
        $requester = $this->requesterMembership($organizationId, $principal);
        $invitation = $this->invitation($organizationId, $invitationId, $principal);
        $this->assertMayGrant($requester, $invitation->getRole());

        if (!$invitation->isPending()) {
            throw ApiException::conflict('This invitation is no longer pending.', 'invitation_not_pending');
        }
        if (!$this->resendLimiter->create($invitation->getId())->consume(1)->isAccepted()) {
            throw ApiException::tooManyRequests();
        }

        $token = $this->invitationService->resend($invitation);
        $this->invitationService->sendInvitationEmail(
            $invitation->getOrganization(),
            $principal,
            $invitation->getEmail(),
            $invitation->getRole(),
            $token,
        );

        return $this->json(['invitation' => $this->serialize($invitation)]);
    }

    #[Route('/{invitationId}', name: 'app_api_invitations_revoke', methods: ['DELETE'])]
    public function revoke(string $organizationId, string $invitationId): JsonResponse
    {
        $this->assertId($organizationId);
        $this->assertId($invitationId);
        $principal = $this->currentPrincipal();
        $requester = $this->requesterMembership($organizationId, $principal);
        $invitation = $this->invitation($organizationId, $invitationId, $principal);
        $this->assertMayGrant($requester, $invitation->getRole());

        if (!$invitation->isPending()) {
            throw ApiException::conflict('This invitation is no longer pending.', 'invitation_not_pending');
        }
        $invitation->revoke();
        $this->entityManager->flush();

        return $this->json(['invitation' => $this->serialize($invitation)]);
    }

    /** An Admin may invite only as Member or Viewer; an Owner has no limit. */
    private function assertMayGrant(Membership $requester, string $role): void
    {
        if ($requester->isOwner()) {
            return;
        }
        if (!in_array($role, ['Member', 'Viewer'], true)) {
            throw ApiException::notFound();
        }
    }

    private function requesterMembership(string $organizationId, Principal $principal): Membership
    {
        $membership = $this->memberships->findForOrganizationAndPrincipal($organizationId, $principal->getId());
        if (null === $membership || !$membership->isActive() || !$membership->canAdminister()) {
            throw ApiException::notFound();
        }

        return $membership;
    }

    private function organization(string $organizationId, Principal $principal): Organization
    {
        $organization = $this->organizations->findActiveForPrincipalInOrganization($organizationId, $principal->getId());
        if (null === $organization) {
            throw ApiException::notFound();
        }

        return $organization;
    }

    private function invitation(string $organizationId, string $invitationId, Principal $principal): Invitation
    {
        $invitation = $this->invitations->findForOrganization($organizationId, $invitationId, $principal->getId());
        if (null === $invitation) {
            throw ApiException::notFound();
        }

        return $invitation;
    }

    /** @return array<string, mixed> */
    private function serialize(Invitation $invitation): array
    {
        return [
            'id' => $invitation->getId(),
            'email' => $invitation->getEmail(),
            'role' => $invitation->getRole(),
            'status' => $invitation->getStatus(),
            'expired' => $invitation->isExpired(),
            'expiresAt' => $invitation->getExpiresAt()->format(DATE_ATOM),
            'createdAt' => $invitation->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    private function assertId(string $id): void
    {
        if (!Uuid::isValid($id)) {
            throw ApiException::invalidId();
        }
    }
}
