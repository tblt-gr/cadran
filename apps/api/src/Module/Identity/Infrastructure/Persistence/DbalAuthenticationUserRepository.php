<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\AuthenticationUserRepository;
use App\Module\Identity\Application\OwnerCredentials;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AuthenticationUserRepository::class)]
final readonly class DbalAuthenticationUserRepository implements AuthenticationUserRepository
{
    use DbalRowValues;

    private const string COLUMNS = 'id, email, display_name, password_hash, disabled_at';

    public function __construct(private Connection $connection)
    {
    }

    public function findByEmail(string $email): ?AuthenticatedUser
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM identity_users WHERE LOWER(email) = LOWER(?)',
            [$email],
        );

        return false === $row ? null : $this->hydrateUser($row);
    }

    public function findCredentialsByEmail(string $email): ?OwnerCredentials
    {
        $row = $this->connection->fetchAssociative(
            'SELECT email, password_hash, disabled_at FROM identity_users WHERE LOWER(email) = LOWER(?)',
            [$email],
        );

        if (false === $row || null === $row['password_hash']) {
            return null;
        }

        return new OwnerCredentials(
            email: self::asString($row['email'] ?? null),
            passwordHash: self::asString($row['password_hash']),
            disabledAt: self::toDate(self::asNullableString($row['disabled_at'] ?? null)),
        );
    }

    public function findProvisionedOwner(): ?AuthenticatedUser
    {
        // A correctly bootstrapped install holds exactly one owner row; order by
        // creation so the result is deterministic if that ever changes.
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM identity_users ORDER BY created_at ASC LIMIT 1',
        );

        return false === $row ? null : $this->hydrateUser($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateUser(array $row): AuthenticatedUser
    {
        return new AuthenticatedUser(
            id: self::asString($row['id'] ?? null),
            email: self::asString($row['email'] ?? null),
            displayName: self::asString($row['display_name'] ?? null),
            hasPassword: null !== ($row['password_hash'] ?? null),
            disabledAt: self::toDate(self::asNullableString($row['disabled_at'] ?? null)),
        );
    }

    private static function toDate(?string $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable($value);
    }
}
