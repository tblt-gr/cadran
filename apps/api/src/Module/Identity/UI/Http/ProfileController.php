<?php

declare(strict_types=1);

namespace App\Module\Identity\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Identity\Application\ChangeOwnerPassword;
use App\Module\Identity\Application\ChangeOwnerPasswordInput;
use App\Module\Identity\Application\DescribeOwnerProfile;
use App\Module\Identity\Application\InvalidCurrentPassword;
use App\Module\Identity\Application\NewPasswordReused;
use App\Module\Identity\Application\OwnerAccountNotProvisioned;
use App\Module\Identity\Application\OwnerProfileView;
use App\Module\Identity\Application\UpdateOwnerProfile;
use App\Module\Identity\Application\UpdateOwnerProfileInput;
use App\Module\Identity\Domain\InvalidDisplayName;
use App\Module\Identity\Domain\PlainPassword;
use App\Module\Identity\Domain\WeakPassword;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The /api/v1/profile resource: the owner's own account, as edited from the
 * settings screen. The firewall has already established who the caller is;
 * which account they may edit is never taken from the request.
 */
final readonly class ProfileController
{
    public function __construct(
        private ProfileHttpEnvelope $envelope,
        // Independent of the login limiter on purpose: throttling the
        // current-password check must not be spendable by, or spend, the
        // sign-in budget of an unauthenticated attacker.
        #[Autowire(service: 'limiter.password_change')]
        private RateLimiterFactoryInterface $passwordChangeLimiter,
    ) {
    }

    #[Route('/api/v1/profile', name: 'api_v1_profile_show', methods: ['GET'])]
    public function show(DescribeOwnerProfile $describeOwnerProfile): Response
    {
        try {
            $profile = $describeOwnerProfile();
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.profile_forbidden');
        } catch (OwnerAccountNotProvisioned) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.owner_not_provisioned');
        }

        return $this->envelope->json(self::representation($profile));
    }

    #[Route('/api/v1/profile', name: 'api_v1_profile_update', methods: ['PATCH'])]
    public function update(Request $request, UpdateOwnerProfile $updateOwnerProfile): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        $fields = ProfileHttpEnvelope::strings($body, ['displayName']);
        if (null === $fields) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }

        try {
            $profile = $updateOwnerProfile(new UpdateOwnerProfileInput($fields['displayName']));
        } catch (InvalidDisplayName) {
            return $this->envelope->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.invalid_display_name',
                ProfileHttpEnvelope::TYPE_INVALID_DISPLAY_NAME,
            );
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.profile_forbidden');
        } catch (OwnerAccountNotProvisioned) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.owner_not_provisioned');
        }

        return $this->envelope->json(self::representation($profile));
    }

    #[Route('/api/v1/profile/password', name: 'api_v1_profile_change_password', methods: ['PUT'])]
    public function changePassword(
        Request $request,
        #[CurrentUser] UserInterface $user,
        ChangeOwnerPassword $changeOwnerPassword,
    ): Response {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        $fields = ProfileHttpEnvelope::strings($body, ['currentPassword', 'newPassword']);
        if (null === $fields) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }

        // The policy check comes before the budget is touched: a candidate that
        // is the wrong length is a malformed request, not a guess at the current
        // password, and must not be able to lock its own owner out. The use case
        // re-runs it — it stays the authority — but by then no token is at stake.
        try {
            PlainPassword::fromString($fields['newPassword']);
        } catch (WeakPassword) {
            return $this->envelope->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.weak_password',
                ProfileHttpEnvelope::TYPE_WEAK_PASSWORD,
            );
        }

        // Keyed by a digest of the identifier so the limiter's storage never
        // holds the email itself.
        $limiter = $this->passwordChangeLimiter->create(hash('sha256', $user->getUserIdentifier()));
        $quota = $limiter->consume();
        if (!$quota->isAccepted()) {
            return $this->envelope->problem(
                Response::HTTP_TOO_MANY_REQUESTS,
                'api.problem.password_change_throttled',
                headers: ['Retry-After' => (string) max(1, $quota->getRetryAfter()->getTimestamp() - time())],
            );
        }

        try {
            $changeOwnerPassword(new ChangeOwnerPasswordInput(
                currentPassword: $fields['currentPassword'],
                newPassword: $fields['newPassword'],
                sessionIdToKeep: self::sessionId($request),
            ));
        } catch (WeakPassword) {
            return $this->envelope->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.weak_password',
                ProfileHttpEnvelope::TYPE_WEAK_PASSWORD,
            );
        } catch (InvalidCurrentPassword) {
            return $this->envelope->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.invalid_current_password',
                ProfileHttpEnvelope::TYPE_INVALID_CURRENT_PASSWORD,
            );
        } catch (NewPasswordReused) {
            // Reaching this branch required the current password, exactly like a
            // success, so refunding the budget hands an attacker nothing: the
            // reuse check runs only after the verification has already passed.
            $limiter->reset();

            return $this->envelope->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.reused_password',
                ProfileHttpEnvelope::TYPE_REUSED_PASSWORD,
            );
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.profile_forbidden');
        } catch (OwnerAccountNotProvisioned) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.owner_not_provisioned');
        }

        // A completed change spends none of the budget: only a caller who
        // cannot produce the current password keeps burning attempts.
        $limiter->reset();

        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * The session the caller is already authenticated on. Read here rather than
     * inside the revocation adapter, so the transaction that drops every other
     * session states which one it spares.
     */
    private static function sessionId(Request $request): ?string
    {
        if (!$request->hasSession(true)) {
            return null;
        }

        $id = $request->getSession()->getId();

        return '' === $id ? null : $id;
    }

    /** @return array<string, string> */
    private static function representation(OwnerProfileView $profile): array
    {
        return [
            'id' => $profile->id,
            'email' => $profile->email,
            'displayName' => $profile->displayName,
        ];
    }
}
