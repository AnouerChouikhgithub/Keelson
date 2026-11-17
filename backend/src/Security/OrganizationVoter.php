<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Membership;
use App\Entity\Principal;
use App\Repository\MembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorization for organization-level operations: settings, members and
 * invitations.
 *
 * Like {@see InvoiceVoter} it requires an active membership on an active
 * organization, and the caller answers a denial with 404 so a foreign tenant is
 * indistinguishable from a missing one. The role checks are deliberately
 * conservative:
 *
 * - `ORGANIZATION_READ`   any active member
 * - `ORGANIZATION_MANAGE` Owner or Admin (settings, member list, invitations)
 * - `ORGANIZATION_DELETE` Owner only
 *
 * Per-target rules that a voter cannot see — "an Admin may not touch an Owner or
 * another Admin", "the last Owner cannot be removed" — live in the controller,
 * which has the target membership in hand.
 */
/** @extends Voter<string, string> */
final class OrganizationVoter extends Voter
{
    public const READ = 'ORGANIZATION_READ';
    public const MANAGE = 'ORGANIZATION_MANAGE';
    public const DELETE = 'ORGANIZATION_DELETE';

    public function __construct(private readonly MembershipRepository $memberships)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::READ, self::MANAGE, self::DELETE], true)
            && is_string($subject)
            && '' !== $subject;
    }

    protected function voteOnAttribute(string $attribute, mixed $organizationId, TokenInterface $token): bool
    {
        $principal = $token->getUser();
        if (!$principal instanceof Principal) {
            return false;
        }
        $membership = $this->memberships->findForOrganizationAndPrincipal($organizationId, $principal->getId());
        if (!$membership instanceof Membership || !$membership->isActive()) {
            return false;
        }

        return match ($attribute) {
            self::READ => true,
            self::MANAGE => $membership->canAdminister(),
            self::DELETE => $membership->isOwner(),
            default => false,
        };
    }
}
