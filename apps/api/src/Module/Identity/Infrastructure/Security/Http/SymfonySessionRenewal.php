<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security\Http;

use App\Module\Identity\Application\CurrentSessionRenewal;
use App\Module\Identity\Infrastructure\Security\IdentityUserProvider;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Two steps, both required.
 *
 * `migrate(true)` issues a new session identifier and destroys the old record,
 * which is the session-fixation defence a credential change calls for. Reloading
 * the user then rebinds the firewall token to the new password hash: Symfony
 * deauthenticates any token whose stored hash no longer matches the one the
 * provider returns, so without this the acting browser would be signed out
 * alongside the sessions the change was meant to revoke.
 */
#[AsAlias(CurrentSessionRenewal::class)]
final readonly class SymfonySessionRenewal implements CurrentSessionRenewal
{
    public function __construct(
        private RequestStack $requestStack,
        private TokenStorageInterface $tokenStorage,
        private IdentityUserProvider $userProvider,
    ) {
    }

    public function renew(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return;
        }

        $request->getSession()->migrate(destroy: true);

        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        if (null === $token || null === $user) {
            return;
        }

        $token->setUser($this->userProvider->refreshUser($user));
    }
}
