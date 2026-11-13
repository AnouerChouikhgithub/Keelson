<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Membership;
use App\Entity\Principal;
use App\Repository\MembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class InvoiceVoter extends Voter
{
    public const READ = 'INVOICE_READ';
    public const WRITE = 'INVOICE_WRITE';
    public const DELETE_DRAFT = 'INVOICE_DELETE_DRAFT';

    public function __construct(private readonly MembershipRepository $memberships)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::READ, self::WRITE, self::DELETE_DRAFT], true)
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
            self::WRITE => in_array($membership->getRole(), ['Owner', 'Admin', 'Member'], true),
            self::DELETE_DRAFT => in_array($membership->getRole(), ['Owner', 'Admin'], true),
            default => false,
        };
    }
}
