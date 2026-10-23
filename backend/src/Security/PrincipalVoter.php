<?php
declare(strict_types=1);
namespace App\Security;
use App\Entity\Membership;
use App\Entity\Principal;
use App\Repository\MembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
final class PrincipalVoter extends Voter
{
    public const ORGANIZATION_VIEW = 'ORGANIZATION_VIEW';
    public const ORGANIZATION_ADMINISTER = 'ORGANIZATION_ADMINISTER';
    public const ORGANIZATION_DELETE = 'ORGANIZATION_DELETE';
    public function __construct(private readonly MembershipRepository $memberships) {}
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::ORGANIZATION_VIEW, self::ORGANIZATION_ADMINISTER, self::ORGANIZATION_DELETE], true) && is_string($subject) && '' !== $subject;
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
            self::ORGANIZATION_VIEW => true,
            self::ORGANIZATION_ADMINISTER => $membership->canAdminister(),
            self::ORGANIZATION_DELETE => $membership->isOwner(),
            default => false,
        };
    }
}
