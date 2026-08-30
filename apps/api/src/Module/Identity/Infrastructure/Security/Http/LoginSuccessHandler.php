<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * A successful login answers 204. The browser then refetches GET /api/v1/session
 * to read the user and workspace, so the session shape lives in one place.
 */
final class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }
}
