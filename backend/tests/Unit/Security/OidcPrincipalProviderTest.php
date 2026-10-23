<?php
declare(strict_types=1);
namespace App\Tests\Unit\Security;
use App\Entity\Principal;
use App\Repository\PrincipalRepository;
use App\Security\OidcPrincipalProvider;
use Doctrine\ORM\EntityManagerInterface;
use Drenso\OidcBundle\Model\OidcTokens;
use Drenso\OidcBundle\Model\OidcUserData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
final class OidcPrincipalProviderTest extends TestCase
{
    public function testCreatesPrincipalFromVerifiedIssuerAndSubject(): void
    {
        $repository = $this->createMock(PrincipalRepository::class);
        $repository->expects(self::once())->method('findByIssuerAndSubject')->with('https://identity.example/oidc', 'user-123')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(Principal::class));
        $entityManager->expects(self::once())->method('flush');
        $provider = new OidcPrincipalProvider($repository, $entityManager, 'https://identity.example/oidc');
        $provider->ensureUserExists('user-123', new OidcUserData(['sub' => 'user-123', 'name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'email_verified' => true]), $this->createMock(OidcTokens::class));
    }
    public function testRejectsIdentifierNotBoundToOidcSubject(): void
    {
        $repository = $this->createMock(PrincipalRepository::class);
        $repository->expects(self::never())->method('findByIssuerAndSubject');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $provider = new OidcPrincipalProvider($repository, $entityManager, 'https://identity.example/oidc');
        $this->expectException(UserNotFoundException::class);
        $provider->ensureUserExists('different-user', new OidcUserData(['sub' => 'user-123']), $this->createMock(OidcTokens::class));
    }
    public function testUnknownIssuerSubjectDoesNotResolveToAnApplicationPrincipal(): void
    {
        $repository = $this->createMock(PrincipalRepository::class);
        $repository->expects(self::once())->method('findByIssuerAndSubject')->with('https://identity.example/oidc', 'missing-user')->willReturn(null);
        $provider = new OidcPrincipalProvider($repository, $this->createMock(EntityManagerInterface::class), 'https://identity.example/oidc');
        $this->expectException(UserNotFoundException::class);
        $provider->loadOidcUser('missing-user');
    }
}
