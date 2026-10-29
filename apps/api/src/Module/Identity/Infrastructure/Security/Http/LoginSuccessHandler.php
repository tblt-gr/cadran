<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security\Http;

use App\Module\Identity\Application\IdentityAuditEvents;
use App\Module\Identity\Application\SessionAuditIntent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * A successful login answers 204. The browser then refetches GET /api/v1/session
 * to read the user and workspace, so the session shape lives in one place.
 *
 * The audit event is parked on the request and written after the response, so
 * an audit failure can never undo a sign-in that already issued its cookie.
 *
 * The session cookie dies with the browser unless the caller opted in with
 * `remember: true`; the opt-in is only flagged here and {@see RememberedSessionCookie}
 * gives the cookie its lifetime, so a shared computer stays safe by default.
 */
final class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $request->attributes->set(
            SessionAuditIntent::REQUEST_ATTRIBUTE,
            new SessionAuditIntent(IdentityAuditEvents::SESSION_OPENED, $token->getUserIdentifier()),
        );

        if ($this->wantsToBeRemembered($request)) {
            $request->attributes->set(RememberedSessionCookie::REQUEST_ATTRIBUTE, true);
        }

        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }

    private function wantsToBeRemembered(Request $request): bool
    {
        $body = json_decode($request->getContent(), true);

        return \is_array($body) && true === ($body['remember'] ?? false);
    }
}
