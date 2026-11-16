<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Invitation;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Service\InvitationService;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Team management: role changes, leaving, and the invite/accept round trip.
 *
 * The receipt side of an invitation is exercised as a browser would: a client
 * with no session opens the public link. Tokens come from the service that
 * issues them, because the database stores only a digest — which is the property
 * that makes a leaked table useless.
 */
final class TeamInvitationTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Organization $organization;

    /** @var array<string, Principal> */
    private array $principals = [];

    /** @var array<string, Membership> */
    private array $memberships = [];

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Team Tenant '.$this->suffix, 'team-'.$this->suffix);
        $this->entityManager->persist($this->organization);
        foreach (['Owner', 'Admin', 'Member', 'Viewer', 'Outsider'] as $role) {
            $principal = new Principal('team-'.strtolower($role).'-'.$this->suffix.'@example.test', $role);
            $this->entityManager->persist($principal);
            $this->principals[$role] = $principal;
        }
        $this->entityManager->flush();
        foreach (['Owner', 'Admin', 'Member', 'Viewer'] as $role) {
            $membership = new Membership($this->organization, $this->principals[$role], $role);
            $this->entityManager->persist($membership);
            $this->memberships[$role] = $membership;
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $connection->executeStatement(
                    "DELETE FROM organizations WHERE subdomain LIKE 'team-%'"
                );
                $connection->executeStatement(
                    'DELETE FROM principals WHERE email LIKE ?',
                    ['team-%@example.test'],
                );
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testRoleChangesFollowTheOwnerAdminSplit(): void
    {
        $this->client->loginUser($this->principals['Admin']);
        $this->send('PATCH', $this->membersPath().'/'.$this->memberships['Member']->getId(), ['role' => 'Viewer']);
        self::assertResponseIsSuccessful();
        self::assertSame('Viewer', $this->payload()['member']['role']);

        // An Admin cannot promote to Admin or Owner…
        $this->send('PATCH', $this->membersPath().'/'.$this->memberships['Viewer']->getId(), ['role' => 'Admin']);
        self::assertResponseStatusCodeSame(404);
        // …nor touch an Owner or another Admin.
        $this->send('PATCH', $this->membersPath().'/'.$this->memberships['Owner']->getId(), ['role' => 'Viewer']);
        self::assertResponseStatusCodeSame(404);
        $this->send('PATCH', $this->membersPath().'/'.$this->memberships['Admin']->getId(), ['role' => 'Viewer']);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->principals['Owner']);
        $this->send('PATCH', $this->membersPath().'/'.$this->memberships['Viewer']->getId(), ['role' => 'Admin']);
        self::assertResponseIsSuccessful();
        $this->send('PATCH', $this->membersPath().'/'.$this->memberships['Viewer']->getId(), ['role' => 'Viewer']);
        self::assertResponseIsSuccessful();

        // Member and Viewer cannot manage anybody.
        $this->client->loginUser($this->principals['Viewer']);
        $this->send('PATCH', $this->membersPath().'/'.$this->memberships['Member']->getId(), ['role' => 'Viewer']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheLastOwnerCannotBeDemotedOrLeave(): void
    {
        $this->client->loginUser($this->principals['Owner']);
        $this->send('PATCH', $this->membersPath().'/'.$this->memberships['Owner']->getId(), ['role' => 'Admin']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('last_owner', $this->payload()['error']['code']);

        $this->send('DELETE', $this->membersPath().'/'.$this->memberships['Owner']->getId());
        self::assertResponseStatusCodeSame(409);
        self::assertSame('last_owner', $this->payload()['error']['code']);

        // With a second Owner, the first may step down.
        $this->send('PATCH', $this->membersPath().'/'.$this->memberships['Admin']->getId(), ['role' => 'Owner']);
        self::assertResponseIsSuccessful();
        $this->send('PATCH', $this->membersPath().'/'.$this->memberships['Owner']->getId(), ['role' => 'Member']);
        self::assertResponseIsSuccessful();
    }

    public function testAMemberMayLeaveAndAVisitorCannotRemoveAnyone(): void
    {
        $this->client->loginUser($this->principals['Viewer']);
        $this->send('DELETE', $this->membersPath().'/'.$this->memberships['Member']->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->principals['Member']);
        $this->send('DELETE', $this->membersPath().'/'.$this->memberships['Member']->getId());
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Membership::class)->find($this->memberships['Member']->getId()));
    }

    public function testAForeignMembershipIdIsNotResolvable(): void
    {
        $this->client->loginUser($this->principals['Owner']);
        $this->send('PATCH', $this->membersPath().'/'.bin2hex(random_bytes(16)), ['role' => 'Admin']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testMemberListIsVisibleToEveryMemberAndHidesNothingSensitive(): void
    {
        foreach (['Owner', 'Admin', 'Member', 'Viewer'] as $role) {
            $this->client->loginUser($this->principals[$role]);
            $this->client->request('GET', $this->membersPath());
            self::assertResponseIsSuccessful($role);
            $items = $this->payload()['items'];
            self::assertCount(4, $items);
            self::assertSame(['id', 'principalId', 'email', 'name', 'role', 'joinedAt'], array_keys($items[0]));
        }

        $this->client->loginUser($this->principals['Outsider']);
        $this->client->request('GET', $this->membersPath());
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvitingRespectsTheAdminCeilingAndDuplicates(): void
    {
        $email = 'invitee-'.$this->suffix.'@example.test';

        $this->client->loginUser($this->principals['Admin']);
        $this->send('POST', $this->invitationsPath(), ['email' => $email, 'role' => 'Admin']);
        self::assertResponseStatusCodeSame(404);

        $this->send('POST', $this->invitationsPath(), ['email' => $email, 'role' => 'Viewer']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $this->payload()['invitation']['status']);
        self::assertArrayNotHasKey('token', $this->payload()['invitation']);

        $this->send('POST', $this->invitationsPath(), ['email' => $email, 'role' => 'Member']);
        self::assertResponseStatusCodeSame(409);

        $this->send('POST', $this->invitationsPath(), ['email' => $this->principals['Member']->getEmail(), 'role' => 'Viewer']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_member', $this->payload()['error']['code']);

        $this->client->request('GET', $this->invitationsPath());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload()['items']);

        $this->client->loginUser($this->principals['Member']);
        $this->client->request('GET', $this->invitationsPath());
        self::assertResponseStatusCodeSame(404);
    }

    public function testThePublicPreviewRevealsOnlyTheInvitationItself(): void
    {
        $email = 'preview-'.$this->suffix.'@example.test';
        $token = $this->issue($email, 'Member');

        self::ensureKernelShutdown();
        $anonymous = static::createClient();
        $anonymous->request('GET', '/api/invitations/'.$token);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $anonymous->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['organizationName', 'role', 'email', 'expired', 'accepted'], array_keys($payload));
        self::assertSame($this->organization->getName(), $payload['organizationName']);
        self::assertSame($email, $payload['email']);
        self::assertFalse($payload['expired']);
        self::assertFalse($payload['accepted']);

        $anonymous->request('GET', '/api/invitations/not-a-real-token');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAcceptingCreatesAVerifiedAccountAndNotifiesTheAdministration(): void
    {
        $email = 'newcomer-'.$this->suffix.'@example.test';
        $token = $this->issue($email, 'Member');
        $ownerId = $this->principals['Owner']->getId();
        $adminId = $this->principals['Admin']->getId();

        self::ensureKernelShutdown();
        $anonymous = static::createClient();
        $anonymous->request('POST', '/api/auth/accept-invitation', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
        ], content: json_encode(['token' => $token, 'password' => 'Keelson-Sprint5!', 'displayName' => 'Newcomer']));

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $anonymous->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($payload['accountCreated']);
        self::assertSame('Member', $payload['role']);

        $manager = $this->freshManager();
        $account = $manager->getRepository(Principal::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Principal::class, $account);
        self::assertTrue($account->isEmailVerified());

        $connection = $manager->getConnection();
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM memberships WHERE organization_id = ? AND principal_id = ?',
            [$this->organization->getId(), $account->getId()],
        ));

        $recipients = $connection->fetchFirstColumn(
            "SELECT principal_id FROM notifications WHERE organization_id = ? AND type = 'member_joined'",
            [$this->organization->getId()],
        );
        self::assertContains($ownerId, $recipients);
        self::assertContains($adminId, $recipients);
        self::assertNotContains($account->getId(), $recipients);

        // A used link is dead.
        $anonymous->request('POST', '/api/auth/accept-invitation', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
        ], content: json_encode(['token' => $token, 'password' => 'Keelson-Sprint5!']));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_token', json_decode((string) $anonymous->getResponse()->getContent(), true)['error']['code']);
    }

    public function testAnExistingAddressMustAcceptFromItsOwnSession(): void
    {
        $email = 'existing-'.$this->suffix.'@example.test';
        $outsider = new Principal($email, 'Outsider');
        $this->entityManager->persist($outsider);
        $this->entityManager->flush();
        $token = $this->issue($email, 'Viewer');

        // Anonymous: nothing is revealed, and nothing is created.
        self::ensureKernelShutdown();
        $anonymous = static::createClient();
        $anonymous->request('POST', '/api/auth/accept-invitation', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
        ], content: json_encode(['token' => $token]));
        self::assertResponseStatusCodeSame(401);
        self::assertSame('sign_in_required', json_decode((string) $anonymous->getResponse()->getContent(), true)['error']['code']);

        // Signed in as somebody else: still refused.
        self::ensureKernelShutdown();
        $wrong = static::createClient();
        $wrong->loginUser(self::getContainer()->get('doctrine')->getRepository(Principal::class)->findOneBy(['email' => $this->principals['Member']->getEmail()]));
        $wrong->request('POST', '/api/auth/accept-invitation', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($wrong),
        ], content: json_encode(['token' => $token]));
        self::assertResponseStatusCodeSame(401);

        // Signed in as the invited account: accepted without a password.
        self::ensureKernelShutdown();
        $self = static::createClient();
        $self->loginUser(self::getContainer()->get('doctrine')->getRepository(Principal::class)->findOneBy(['email' => $email]));
        $self->request('POST', '/api/auth/accept-invitation', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($self),
        ], content: json_encode(['token' => $token]));
        self::assertResponseIsSuccessful();
        self::assertFalse(json_decode((string) $self->getResponse()->getContent(), true)['accountCreated']);

        $connection = $this->freshManager()->getConnection();
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM memberships WHERE organization_id = ? AND principal_id = ?',
            [$this->organization->getId(), $outsider->getId()],
        ));
    }

    public function testAnExpiredInvitationCannotBeAcceptedAndResendingRevivesIt(): void
    {
        $email = 'expiring-'.$this->suffix.'@example.test';
        $token = $this->issue($email, 'Member');
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE invitations SET expires_at = now() - interval \'1 day\' WHERE email = ?',
            [$email],
        );

        self::ensureKernelShutdown();
        $anonymous = static::createClient();
        $anonymous->request('GET', '/api/invitations/'.$token);
        self::assertTrue(json_decode((string) $anonymous->getResponse()->getContent(), true)['expired']);

        $anonymous->request('POST', '/api/auth/accept-invitation', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
        ], content: json_encode(['token' => $token, 'password' => 'Keelson-Sprint5!']));
        self::assertResponseStatusCodeSame(400);

        self::ensureKernelShutdown();
        $owner = static::createClient();
        $manager = self::getContainer()->get('doctrine')->getManager();
        $owner->loginUser($manager->getRepository(Principal::class)->findOneBy(['email' => $this->principals['Owner']->getEmail()]));
        $invitation = $manager->getRepository(Invitation::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Invitation::class, $invitation);

        $owner->request('POST', $this->invitationsPath().'/'.$invitation->getId().'/resend', server: [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($owner),
        ]);
        self::assertResponseIsSuccessful();

        $manager->clear();
        $refreshed = $manager->getRepository(Invitation::class)->find($invitation->getId());
        self::assertFalse($refreshed->isExpired());
        self::assertTrue($refreshed->isPending());

        // Revoking ends the invitation; it can no longer be resent.
        $owner->request('DELETE', $this->invitationsPath().'/'.$invitation->getId(), server: [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($owner),
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('revoked', json_decode((string) $owner->getResponse()->getContent(), true)['invitation']['status']);

        $owner->request('POST', $this->invitationsPath().'/'.$invitation->getId().'/resend', server: [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($owner),
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    /** @return string the plaintext token, which only the service ever holds */
    private function issue(string $email, string $role): string
    {
        $issued = self::getContainer()->get(InvitationService::class)->invite(
            $this->organization,
            $this->principals['Owner'],
            $email,
            $role,
        );

        return $issued['token'];
    }

    private function freshManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine')->getManager();
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null): void
    {
        $this->client->request($method, $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client),
        ], content: null === $body ? null : json_encode($body));
    }

    private function membersPath(): string
    {
        return '/api/organizations/'.$this->organization->getId().'/members';
    }

    private function invitationsPath(): string
    {
        return '/api/organizations/'.$this->organization->getId().'/invitations';
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
