<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\Principal;
use App\EventListener\OidcEndSessionLogoutListener;
use App\Service\ProviderEndSessionRedirector;
use Drenso\OidcBundle\Model\OidcTokens;
use Drenso\OidcBundle\OidcClientInterface;
use Drenso\OidcBundle\Security\Token\OidcToken;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class OidcEndSessionLogoutListenerTest extends TestCase
{
    public function testOidcLogoutRedirectsToTheProviderEndSessionEndpoint(): void
    {
        $tokens = $this->tokens();
        $event = $this->event($this->oidcToken($tokens));

        $oidc = $this->createMock(OidcClientInterface::class);
        $oidc->expects(self::once())
            ->method('generateEndSessionEndpointRedirect')
            ->with($tokens, 'http://localhost/')
            ->willReturn(new RedirectResponse('https://identity.example/session/end?client_id=test&id_token_hint=id.token.value'));

        $listener = new OidcEndSessionLogoutListener(new ProviderEndSessionRedirector($oidc, $this->createMock(LoggerInterface::class)));
        $listener->onLogout($event);

        self::assertSame(
            'https://identity.example/session/end?client_id=test&id_token_hint=id.token.value',
            $event->getResponse()?->getTargetUrl(),
            'An OIDC session must end at the provider, not only locally.',
        );
    }

    public function testProviderFailureKeepsTheLocalLogoutRedirect(): void
    {
        $event = $this->event($this->oidcToken($this->tokens()));

        $oidc = $this->createMock(OidcClientInterface::class);
        $oidc->method('generateEndSessionEndpointRedirect')->willThrowException(new \RuntimeException('discovery unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'OIDC provider end-session redirect unavailable; completed the local sign-out only.',
                ['exception_class' => \RuntimeException::class],
            );

        $listener = new OidcEndSessionLogoutListener(new ProviderEndSessionRedirector($oidc, $logger));
        $listener->onLogout($event);

        self::assertSame(
            'http://localhost/',
            $event->getResponse()?->getTargetUrl(),
            'A provider outage must not turn a completed local sign-out into an error.',
        );
    }

    public function testNonOidcSessionsAreLeftToTheDefaultLogout(): void
    {
        $event = new LogoutEvent(new Request(), new PostAuthenticationToken(new Principal('https://issuer.example', 'subject'), 'main', ['ROLE_USER']));
        $event->setResponse(new RedirectResponse('http://localhost/'));

        $oidc = $this->createMock(OidcClientInterface::class);
        $oidc->expects(self::never())->method('generateEndSessionEndpointRedirect');

        (new OidcEndSessionLogoutListener(new ProviderEndSessionRedirector($oidc, $this->createMock(LoggerInterface::class))))->onLogout($event);

        self::assertSame('http://localhost/', $event->getResponse()?->getTargetUrl());
    }

    public function testListenerRunsAfterTheSessionIsInvalidated(): void
    {
        // SessionLogoutListener invalidates at priority 0; this must run after it.
        self::assertSame(-10, OidcEndSessionLogoutListener::getSubscribedEvents()[LogoutEvent::class][1]);
    }

    private function event(OidcToken $token): LogoutEvent
    {
        $event = new LogoutEvent(new Request(), $token);
        // Emulates DefaultLogoutListener (priority 64) resolving the configured target.
        $event->setResponse(new RedirectResponse('http://localhost/'));

        return $event;
    }

    private function oidcToken(OidcTokens $tokens): OidcToken
    {
        $principal = new Principal('https://issuer.example', 'subject-'.bin2hex(random_bytes(4)));
        $passport = new SelfValidatingPassport(new UserBadge($principal->getUserIdentifier(), static fn (): Principal => $principal));
        $passport->setAttribute(OidcToken::AUTH_DATA_ATTR, $tokens);

        return new OidcToken($passport, 'main');
    }

    private function tokens(): OidcTokens
    {
        return new OidcTokens((object) [
            'id_token' => 'id.token.value',
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]);
    }
}
