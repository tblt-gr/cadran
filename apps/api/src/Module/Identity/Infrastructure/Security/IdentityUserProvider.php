<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security;

use App\Module\Identity\Application\AuthenticationUserRepository;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class IdentityUserProvider implements UserProviderInterface
{
    /**
     * A fixed Argon2id digest with production cost parameters. Verifying an
     * unknown identifier against it keeps the "user not found" path within the
     * same order of magnitude as a wrong-password path, so a valid account
     * email cannot be told from an invalid one by response latency.
     */
    private const string DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$VMfNEmq0UdV8rd7Z6drKfA$N9VdnNLI83cojk51RkIQMkfxBhARM7YHt+IYAN3JZ44';

    public function __construct(
        private AuthenticationUserRepository $users,
        private PasswordHasherFactoryInterface $passwordHasherFactory,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->loadSecurityUser($identifier);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }

        $refreshed = $this->loadSecurityUser($user->getUserIdentifier());
        if ($refreshed->isDisabled()) {
            // A disable that lands mid-session must drop the firewall token, not
            // merely be noticed downstream. ContextListener nulls the token when
            // refreshUser throws this.
            throw new UserNotFoundException();
        }

        return $refreshed;
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class || is_subclass_of($class, SecurityUser::class);
    }

    private function loadSecurityUser(string $identifier): SecurityUser
    {
        $credentials = $this->users->findCredentialsByEmail($identifier);
        if (null === $credentials) {
            // No account, or an account with no password yet: burn comparable
            // time before failing so latency does not leak which case it is.
            $this->passwordHasherFactory->getPasswordHasher(SecurityUser::class)->verify(self::DUMMY_HASH, $identifier);

            throw new UserNotFoundException();
        }

        return new SecurityUser($credentials->email, $credentials->passwordHash, $credentials->isDisabled());
    }
}
