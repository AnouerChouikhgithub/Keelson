<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Membership;
use App\Entity\Principal;
use App\Exception\ApiException;
use App\Http\InputHydrator;
use App\Http\JsonBody;
use App\Input\MemberRoleInput;
use App\Repository\MembershipRepository;
use App\Security\OrganizationVoter;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The team of one organization.
 *
 * The roster is visible to every active member; changing it needs administration
 * rights, and even an Owner cannot demote or remove the last Owner. An Admin may
 * only touch Members and Viewers and may not hand out Admin or Owner: the
 * difference between the two roles must not be one Admin promoting a friend.
 *
 * Anyone may leave an organization themselves — removing your own membership is
 * how "leave" is expressed, and it is allowed even for a Viewer, except for the
 * last Owner, who would leave the tenant ownerless.
 */
#[Route('/api/organizations/{organizationId}/members')]
final class MemberController extends AbstractController
{
    use CurrentPrincipalTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MembershipRepository $memberships,
        private readonly InputHydrator $hydrator,
    ) {
    }

    #[Route('', name: 'app_api_members_list', methods: ['GET'])]
    public function list(string $organizationId): JsonResponse
    {
        $this->assertId($organizationId);
        $principal = $this->currentPrincipal();
        if (!$this->isGranted(OrganizationVoter::READ, $organizationId)) {
            throw ApiException::notFound();
        }

        $items = array_map(
            static fn (Membership $membership): array => [
                'id' => $membership->getId(),
                'principalId' => $membership->getPrincipal()->getId(),
                'email' => $membership->getPrincipal()->getEmail(),
                'name' => $membership->getPrincipal()->getDisplayName(),
                'role' => $membership->getRole(),
                'joinedAt' => $membership->getCreatedAt()->format(DATE_ATOM),
            ],
            $this->memberships->findForOrganization($organizationId, $principal->getId()),
        );

        return $this->json(['items' => $items, 'nextCursor' => null], Response::HTTP_OK, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/{membershipId}', name: 'app_api_members_update', methods: ['PATCH'])]
    public function updateRole(string $organizationId, string $membershipId, Request $request): JsonResponse
    {
        $this->assertId($organizationId);
        $this->assertId($membershipId);
        $principal = $this->currentPrincipal();
        $requester = $this->requesterMembership($organizationId, $principal);
        if (!$requester->canAdminister()) {
            throw ApiException::notFound();
        }

        $target = $this->memberships->findForOrganizationById($organizationId, $membershipId, $principal->getId());
        if (null === $target) {
            throw ApiException::notFound();
        }

        $input = $this->hydrator->hydrate(MemberRoleInput::class, JsonBody::decode($request));
        $this->assertMayManage($requester, $target, $input->role);

        if ($target->isOwner() && 'Owner' !== $input->role && 0 === $this->memberships->countActiveOwners($organizationId, $target->getId())) {
            throw ApiException::lastOwner();
        }

        $target->changeRole($input->role);
        $this->entityManager->flush();

        return $this->json(['member' => $this->serialize($target)], Response::HTTP_OK, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/{membershipId}', name: 'app_api_members_delete', methods: ['DELETE'])]
    public function remove(string $organizationId, string $membershipId): Response
    {
        $this->assertId($organizationId);
        $this->assertId($membershipId);
        $principal = $this->currentPrincipal();
        $requester = $this->requesterMembership($organizationId, $principal);

        $target = $this->memberships->findForOrganizationById($organizationId, $membershipId, $principal->getId());
        if (null === $target) {
            throw ApiException::notFound();
        }

        $isSelf = $target->getPrincipal()->getId() === $principal->getId();
        if (!$isSelf) {
            if (!$requester->canAdminister()) {
                throw ApiException::notFound();
            }
            $this->assertMayManage($requester, $target, null);
        }

        if ($target->isOwner() && 0 === $this->memberships->countActiveOwners($organizationId, $target->getId())) {
            throw ApiException::lastOwner();
        }

        $this->entityManager->remove($target);
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * An Admin may only manage Members and Viewers, and may not grant Admin or
     * Owner. An Owner has no restriction here.
     */
    private function assertMayManage(Membership $requester, Membership $target, ?string $newRole): void
    {
        if ($requester->isOwner()) {
            return;
        }
        if (!in_array($target->getRole(), ['Member', 'Viewer'], true) || (null !== $newRole && !in_array($newRole, ['Member', 'Viewer'], true))) {
            // Deliberately a 404: an Admin cannot even confirm that the id exists.
            throw ApiException::notFound();
        }
    }

    private function requesterMembership(string $organizationId, Principal $principal): Membership
    {
        $membership = $this->memberships->findForOrganizationAndPrincipal($organizationId, $principal->getId());
        if (null === $membership || !$membership->isActive()) {
            throw ApiException::notFound();
        }

        return $membership;
    }

    /** @return array<string, string|null> */
    private function serialize(Membership $membership): array
    {
        return [
            'id' => $membership->getId(),
            'principalId' => $membership->getPrincipal()->getId(),
            'email' => $membership->getPrincipal()->getEmail(),
            'name' => $membership->getPrincipal()->getDisplayName(),
            'role' => $membership->getRole(),
            'joinedAt' => $membership->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    private function assertId(string $id): void
    {
        if (!Uuid::isValid($id)) {
            throw ApiException::invalidId();
        }
    }
}
