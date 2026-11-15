<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Answers an unauthenticated `/api/...` request with 401 JSON instead of an
 * HTML sign-in redirect.
 *
 * `fetch` follows the provider redirect and hands the SPA a 200 HTML document;
 * the browser could only infer "signed out" from the content type, which is
 * fragile. A browser navigation (the shell itself) keeps the redirect to
 * `/login`, which starts the OIDC flow.
 */
final class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return new JsonResponse(
                ['error' => ['code' => 'unauthenticated', 'message' => 'Authentication is required.']],
                Response::HTTP_UNAUTHORIZED,
                ['Cache-Control' => 'no-store'],
            );
        }

        return new RedirectResponse('/login');
    }
}
