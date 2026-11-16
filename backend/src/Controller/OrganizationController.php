<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Exception\ApiException;
use App\Http\InputHydrator;
use App\Http\JsonBody;
use App\Input\CreateOrganizationInput;
use App\Input\DeleteOrganizationInput;
use App\Input\UpdateOrganizationInput;
use App\Repository\OrganizationRepository;
use App\Security\OrganizationVoter;
use App\Service\TransactionBoundary;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Creating and administering an organization.
 *
 * The tenant in the path is a selector: it is resolved through the caller's
 * active membership, so a made-up or foreign id is a 404 and never a foreign
 * record. Only the organization's Owner may rename it, change its address or
 * delete it; being an Admin is enough for everything else in
 * {@see MemberController} and {@see InvitationController}.
 */
#[Route('/api/organizations')]
final class OrganizationController extends AbstractController
{
    use CurrentPrincipalTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrganizationRepository $organizations,
        private readonly InputHydrator $hydrator,
        private readonly TransactionBoundary $transactions,
        #[Autowire(service: 'limiter.subdomain_availability')]
        private readonly RateLimiterFactory $availabilityLimiter,
    ) {
    }

    #[Route('/subdomain-availability', name: 'app_api_organizations_subdomain_availability', methods: ['GET'])]
    public function subdomainAvailability(Request $request): JsonResponse
    {
        $principal = $this->currentPrincipal();
        if (!$this->availabilityLimiter->create($principal->getId())->consume(1)->isAccepted()) {
            throw ApiException::tooManyRequests();
        }

        $value = mb_strtolower(trim((string) $request->query->get('value', '')));
        $available = 1 === preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/', $value)
            && !in_array($value, CreateOrganizationInput::RESERVED_SUBDOMAINS, true)
            && null === $this->organizations->findOneBy(['subdomain' => $value]);

        // Always 200 with a boolean: the answer is a hint for a form, and a 404
        // would make "not free" an error the client has to translate.
        return $this->json(['available' => $available], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('', name: 'app_api_organizations_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $principal = $this->currentPrincipal();
        $input = $this->hydrator->hydrate(CreateOrganizationInput::class, JsonBody::decode($request));
        $subdomain = mb_strtolower(trim($input->subdomain));
        $this->assertNotReserved($subdomain);

        try {
            return $this->transactions->run(function () use ($principal, $input, $subdomain): JsonResponse {
                $organization = new Organization($input->name, $subdomain);
                $this->entityManager->persist($organization);
                $this->entityManager->persist(new Membership($organization, $principal, 'Owner'));
                $this->entityManager->flush();

                return $this->json(['organization' => $this->serialize($organization, 'Owner')], Response::HTTP_CREATED);
            });
        } catch (UniqueConstraintViolationException) {
            // The pre-check can lose a race; the unique index is the authority.
            throw ApiException::conflict('That subdomain is already taken.', 'subdomain_taken');
        }
    }

    #[Route('/{organizationId}', name: 'app_api_organizations_update', methods: ['PATCH'])]
    public function update(string $organizationId, Request $request): JsonResponse
    {
        $principal = $this->currentPrincipal();
        if (!$this->isGranted(OrganizationVoter::DELETE, $organizationId)) {
            throw ApiException::notFound();
        }
        $organization = $this->organizations->findActiveForPrincipalInOrganization($organizationId, $principal->getId());
        if (null === $organization) {
            throw ApiException::notFound();
        }

        $input = $this->hydrator->hydrate(UpdateOrganizationInput::class, JsonBody::decode($request));
        if (null === $input->name && null === $input->subdomain) {
            throw ApiException::validationFailed('Provide a name or a subdomain to change.');
        }

        if (null !== $input->name) {
            $organization->rename($input->name);
        }
        if (null !== $input->subdomain) {
            $subdomain = mb_strtolower(trim($input->subdomain));
            $this->assertNotReserved($subdomain);
            if ($subdomain !== $organization->getSubdomain() && null !== $this->organizations->findOneBy(['subdomain' => $subdomain])) {
                throw ApiException::conflict('That subdomain is already taken.', 'subdomain_taken');
            }
            $organization->changeSubdomain($subdomain);
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('That subdomain is already taken.', 'subdomain_taken');
        }

        return $this->json(['organization' => $this->serialize($organization, 'Owner')]);
    }

    #[Route('/{organizationId}', name: 'app_api_organizations_delete', methods: ['DELETE'])]
    public function delete(string $organizationId, Request $request): JsonResponse
    {
        $principal = $this->currentPrincipal();
        if (!$this->isGranted(OrganizationVoter::DELETE, $organizationId)) {
            throw ApiException::notFound();
        }
        $organization = $this->organizations->findActiveForPrincipalInOrganization($organizationId, $principal->getId());
        if (null === $organization) {
            throw ApiException::notFound();
        }

        $input = $this->hydrator->hydrate(DeleteOrganizationInput::class, JsonBody::decode($request));
        if (trim($input->confirmName) !== $organization->getName()) {
            throw ApiException::validationFailed('The confirmation does not match.', ['confirmName' => ['Type the organization name exactly to confirm deletion.']]);
        }

        $organization->markDeleted();
        $this->entityManager->flush();

        return $this->json(['organization' => $this->serialize($organization, 'Owner')]);
    }

    /** A reserved hostname is a product name, not a tenant's to claim. */
    private function assertNotReserved(string $subdomain): void
    {
        if (in_array($subdomain, CreateOrganizationInput::RESERVED_SUBDOMAINS, true)) {
            throw ApiException::validationFailed('The request contains invalid fields.', ['subdomain' => ['This subdomain is reserved.']]);
        }
    }

    /** @return array<string, string> */
    private function serialize(Organization $organization, ?string $role): array
    {
        return [
            'id' => $organization->getId(),
            'name' => $organization->getName(),
            'subdomain' => $organization->getSubdomain(),
            'status' => $organization->getStatus(),
            'role' => (string) $role,
            'createdAt' => $organization->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
