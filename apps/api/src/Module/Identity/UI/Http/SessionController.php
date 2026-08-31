<?php

declare(strict_types=1);

namespace App\Module\Identity\UI\Http;

use App\Module\Identity\Application\DefineInitialPassword;
use App\Module\Identity\Application\DefineInitialPasswordInput;
use App\Module\Identity\Application\DescribeSession;
use App\Module\Identity\Application\IdentityAuditEvents;
use App\Module\Identity\Application\InitialPasswordAlreadyDefined;
use App\Module\Identity\Application\OwnerAccountNotProvisioned;
use App\Module\Identity\Application\SessionAuditIntent;
use App\Module\Identity\Application\SessionView;
use App\Module\Identity\Domain\WeakPassword;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The /api/v1/session resource. POST and DELETE are handled by the firewall's
 * json_login authenticator and by explicit session teardown; the controller
 * owns GET (session description) and PUT .../password (first-run password).
 */
final class SessionController
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/session', name: 'api_v1_session_show', methods: ['GET'])]
    public function show(#[CurrentUser] ?UserInterface $user, DescribeSession $describeSession): JsonResponse
    {
        $view = $describeSession($user?->getUserIdentifier());

        return $this->json($view);
    }

    /**
     * The json_login authenticator intercepts a JSON POST before this runs.
     * Reaching the controller means the request was not JSON, so the only
     * correct answer is 415.
     */
    #[Route('/api/v1/session', name: 'api_v1_session_create', methods: ['POST'])]
    public function create(): Response
    {
        return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
    }

    #[Route('/api/v1/session', name: 'api_v1_session_delete', methods: ['DELETE'])]
    public function delete(
        Request $request,
        #[CurrentUser] ?UserInterface $user,
        TokenStorageInterface $tokenStorage,
    ): Response {
        // The actor is captured before the token is dropped and written after
        // the response, so a failed audit write cannot leave the caller signed
        // in. A no-op logout records nothing.
        if (null !== $user) {
            $request->attributes->set(
                SessionAuditIntent::REQUEST_ATTRIBUTE,
                new SessionAuditIntent(IdentityAuditEvents::SESSION_CLOSED, $user->getUserIdentifier()),
            );
        }

        $tokenStorage->setToken(null);
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/session/password', name: 'api_v1_session_define_password', methods: ['PUT'])]
    public function definePassword(Request $request, DefineInitialPassword $defineInitialPassword): Response
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !is_string($payload['password'] ?? null)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }

        try {
            $defineInitialPassword(new DefineInitialPasswordInput($payload['password']));
        } catch (WeakPassword) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.weak_password');
        } catch (InitialPasswordAlreadyDefined) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.password_already_set');
        } catch (OwnerAccountNotProvisioned) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.owner_not_provisioned');
        }

        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }

    private function json(SessionView $view): JsonResponse
    {
        return new JsonResponse(
            data: [
                'provisioned' => $view->provisioned,
                'authenticated' => $view->authenticated,
                'setupRequired' => $view->setupRequired,
                'user' => null === $view->user ? null : [
                    'id' => $view->user->id,
                    'email' => $view->user->email,
                    'displayName' => $view->user->displayName,
                ],
                'workspace' => null === $view->workspace ? null : [
                    'id' => $view->workspace->id,
                    'role' => $view->workspace->role,
                ],
            ],
            headers: ['Cache-Control' => 'no-store'],
        );
    }

    private function problem(int $status, string $translationKey): JsonResponse
    {
        return new JsonResponse(
            data: [
                'type' => 'about:blank',
                'title' => $this->translator->trans($translationKey.'.title'),
                'status' => $status,
                'detail' => $this->translator->trans($translationKey.'.detail'),
            ],
            status: $status,
            headers: [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'application/problem+json',
            ],
        );
    }
}
