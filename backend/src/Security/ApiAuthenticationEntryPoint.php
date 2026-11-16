<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\ApiErrorResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Answers an unauthenticated request with `401` JSON.
 *
 * There is no server-rendered sign-in page any more: the only client is the
 * SPA, and `fetch` cannot follow a browser redirect to a form. A redirect would
 * hand the client a 200 HTML document instead of an authentication state it can
 * act on.
 */
final class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ApiErrorResponse::make(
            Response::HTTP_UNAUTHORIZED,
            'unauthenticated',
            'Authentication is required.',
        );
    }
}
