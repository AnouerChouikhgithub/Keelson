<?php

declare(strict_types=1);

namespace App\Controller;

use Drenso\OidcBundle\OidcClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(OidcClientInterface $oidcClient): RedirectResponse
    {
        return $oidcClient->generateAuthorizationRedirect(null, ['openid', 'profile', 'email']);
    }

    // The OIDC authenticator handles this request before the controller runs,
    // but Symfony still needs a route to exist for the check_path.
    #[Route('/login_check', name: 'app_login_check', methods: ['GET'])]
    public function check(): never
    {
        throw new \LogicException('The OIDC authenticator handles /login_check.');
    }

}
