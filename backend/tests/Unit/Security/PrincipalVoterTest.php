<?php
declare(strict_types=1);
namespace App\Tests\Unit\Security;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Repository\MembershipRepository;
use App\Security\PrincipalVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
final class PrincipalVoterTest extends TestCase
{
    public function testDeniesOrganizationWhenNoMembershipExists(): void
    {
        $principal = $this->persistedPrincipal();
        $memberships = $this->createMock(MembershipRepository::class);
        $memberships->expects(self::once())->method('findForOrganizationAndPrincipal')->with('tenant-a', $principal->getId())->willReturn(null);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter($memberships)->vote($this->token($principal), 'tenant-a', [PrincipalVoter::ORGANIZATION_VIEW]));
    }
    public function testViewerCanReadButCannotAdministerOrganization(): void
    {
        [$voter, $token] = $this->forRole('Viewer');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, 'tenant-a', [PrincipalVoter::ORGANIZATION_VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, 'tenant-a', [PrincipalVoter::ORGANIZATION_ADMINISTER]));
    }
    public function testAdminCanAdministerButCannotDeleteOrganization(): void
    {
        [$voter, $token] = $this->forRole('Admin');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, 'tenant-a', [PrincipalVoter::ORGANIZATION_ADMINISTER]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, 'tenant-a', [PrincipalVoter::ORGANIZATION_DELETE]));
    }
    public function testMemberCannotAdministerOrganization(): void
    {
        [$voter, $token] = $this->forRole('Member');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, 'tenant-a', [PrincipalVoter::ORGANIZATION_VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, 'tenant-a', [PrincipalVoter::ORGANIZATION_ADMINISTER]));
    }
    public function testOwnerCanAdministerAndDeleteOrganization(): void
    {
        [$voter, $token] = $this->forRole('Owner');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, 'tenant-a', [PrincipalVoter::ORGANIZATION_ADMINISTER]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, 'tenant-a', [PrincipalVoter::ORGANIZATION_DELETE]));
    }
    public function testInactiveMembershipIsDenied(): void
    {
        $principal = $this->persistedPrincipal();
        $membership = $this->membership('Owner', $principal);
        $status = new \ReflectionProperty($membership, 'status');
        $status->setValue($membership, 'suspended');
        $memberships = $this->createMock(MembershipRepository::class);
        $memberships->method('findForOrganizationAndPrincipal')->willReturn($membership);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter($memberships)->vote($this->token($principal), 'tenant-a', [PrincipalVoter::ORGANIZATION_DELETE]));
    }
    /** @return array{PrincipalVoter, TokenInterface} */
    private function forRole(string $role): array
    {
        $principal = $this->persistedPrincipal();
        $membership = $this->membership($role, $principal);
        $memberships = $this->createMock(MembershipRepository::class);
        $memberships->method('findForOrganizationAndPrincipal')->willReturn($membership);
        return [$this->voter($memberships), $this->token($principal)];
    }
    private function persistedPrincipal(): Principal
    {
        $principal = new Principal('https://identity.example/oidc', 'user-1');
        (new \ReflectionProperty($principal, 'id'))->setValue($principal, 'principal-1');

        return $principal;
    }
    private function membership(string $role, Principal $principal): Membership
    {
        return new Membership(new Organization('Example', 'example'), $principal, $role);
    }
    private function voter(MembershipRepository $memberships): PrincipalVoter
    {
        return new PrincipalVoter($memberships);
    }
    private function token(Principal $principal): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($principal);
        return $token;
    }
}
