<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Repository\MembershipRepository;
use App\Security\InvoiceVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class InvoiceVoterTest extends TestCase
{
    public function testMembersMayWriteViewersAreReadOnlyAndOnlyAdminsMayDeleteDrafts(): void
    {
        foreach (['Owner', 'Admin', 'Member', 'Viewer'] as $role) {
            $principal = new Principal('https://issuer.example', 'user-'.$role);
            (new \ReflectionProperty($principal, 'id'))->setValue($principal, 'principal-'.$role);
            $membership = new Membership(new Organization('Example', 'example'), $principal, $role);
            $repository = $this->createMock(MembershipRepository::class);
            $repository->method('findForOrganizationAndPrincipal')->willReturn($membership);
            $token = $this->createMock(TokenInterface::class);
            $token->method('getUser')->willReturn($principal);
            $voter = new InvoiceVoter($repository);

            self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, 'tenant-a', [InvoiceVoter::READ]));
            self::assertSame(in_array($role, ['Owner', 'Admin', 'Member'], true) ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $voter->vote($token, 'tenant-a', [InvoiceVoter::WRITE]));
            self::assertSame(in_array($role, ['Owner', 'Admin'], true) ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $voter->vote($token, 'tenant-a', [InvoiceVoter::DELETE_DRAFT]));
        }
    }

    public function testMissingMembershipIsDenied(): void
    {
        $principal = new Principal('https://issuer.example', 'user-1');
        (new \ReflectionProperty($principal, 'id'))->setValue($principal, 'principal-1');
        $repository = $this->createMock(MembershipRepository::class);
        $repository->expects(self::once())->method('findForOrganizationAndPrincipal')->willReturn(null);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($principal);

        self::assertSame(VoterInterface::ACCESS_DENIED, (new InvoiceVoter($repository))->vote($token, 'foreign-tenant', [InvoiceVoter::READ]));
    }
}
