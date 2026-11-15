<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Principal;
use Drenso\OidcBundle\Model\OidcTokens;
use Drenso\OidcBundle\Security\Token\OidcToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * Sign-out for a real OIDC-authenticated session.
 *
 * The session holds an OidcToken exactly as the firewall stores it after a
 * provider login. Posting /logout with the session's CSRF token must invalidate
 * the local session AND redirect the browser to the provider's end-session
 * endpoint, so the provider's SSO session ends too. Discovery is served by the
 * offline test fetcher (config/services_test.yaml); no provider is contacted.
 */
final class OidcSessionLogoutTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private Principal $principal;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);

        $issuer = $_SERVER['OIDC_ISSUER'] ?? $_ENV['OIDC_ISSUER'] ?? null;
        self::assertNotNull($issuer, 'OIDC_ISSUER must be configured for the test session user.');
        $this->principal = new Principal($issuer, 'oidc-logout-'.bin2hex(random_bytes(8)));
        $this->principal->updateProfile('OIDC Logout User', 'oidc-logout@example.test', true);
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen() && isset($this->principal)) {
                $this->entityManager->getConnection()->executeStatement(
                    'DELETE FROM principals WHERE id = ?',
                    [$this->principal->getId()],
                );
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testLogoutEndsTheProviderSessionAndInvalidatesTheLocalSession(): void
    {
        $this->startOidcSession();

        $this->client->request('GET', '/api/session');
        self::assertResponseIsSuccessful();
        $session = $this->json();
        self::assertTrue($session['authenticated']);
        self::assertSame('/logout', $session['logout']['url']);
        $csrfToken = (string) $session['logout']['csrfToken'];
        self::assertNotSame('', $csrfToken);

        $this->client->request('POST', '/logout', ['_csrf_token' => $csrfToken]);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/session/end', $location, 'Logout must reach the provider end-session endpoint.');
        self::assertStringContainsString('id_token_hint=id.token.value', $location);
        self::assertStringContainsString('post_logout_redirect_uri='.rawurlencode('http://localhost/'), $location);
        self::assertStringContainsString('client_id=test-client', $location);

        // The local session is really gone, not just hidden in the SPA.
        $this->client->request('GET', '/api/session');
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['authenticated' => false, 'user' => null, 'logout' => null],
            $this->json(),
        );

        $this->client->request('GET', '/api/organizations/11111111-1111-4111-8111-111111111111/customers');
        self::assertResponseRedirects('/login');
    }

    public function testForgedCsrfTokenDoesNotEndTheOidcSession(): void
    {
        $this->startOidcSession();

        $this->client->request('POST', '/logout', ['_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/session');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['authenticated'], 'An invalid CSRF token ended the session.');
    }

    private function startOidcSession(): void
    {
        $tokens = new OidcTokens((object) [
            'id_token' => 'id.token.value',
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]);
        $passport = new Passport($this->principal);
        $passport->setAttribute(OidcToken::AUTH_DATA_ATTR, $tokens);
        $token = new OidcToken($passport, 'main');

        // Mirrors KernelBrowser::loginUser(), but with the OidcToken shape the
        // real firewall stores so the end-session listener actually runs.
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->set('_security_main', serialize($token));
        $session->save();

        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
