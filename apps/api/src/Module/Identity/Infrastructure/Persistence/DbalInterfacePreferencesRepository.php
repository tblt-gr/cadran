<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Identity\Domain\InterfacePreferences;
use App\Module\Identity\Domain\InterfacePreferencesRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(InterfacePreferencesRepository::class)]
final readonly class DbalInterfacePreferencesRepository implements InterfacePreferencesRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace): InterfacePreferences
    {
        $row = $this->connection->fetchAssociative(
            'SELECT include_upcoming, version, updated_at FROM identity_interface_preferences WHERE workspace_id = :workspace_id',
            ['workspace_id' => $workspace->id],
        );
        if (false === $row) {
            return InterfacePreferences::unsaved($workspace);
        }
        $version = $row['version'] ?? null;
        $updatedAt = $row['updated_at'] ?? null;
        if (!is_int($version) && !(is_string($version) && ctype_digit($version)) || !is_string($updatedAt)) {
            throw new \UnexpectedValueException('Unreadable interface preferences row.');
        }

        return new InterfacePreferences($workspace, (bool) $row['include_upcoming'], (int) $version, new \DateTimeImmutable($updatedAt));
    }

    public function save(InterfacePreferences $preferences, int $expectedVersion): bool
    {
        $columns = [
            'include_upcoming' => $preferences->includeUpcoming,
            'version' => $preferences->version,
            'updated_at' => ($preferences->updatedAt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
        ];
        $types = ['include_upcoming' => ParameterType::BOOLEAN];
        if (InterfacePreferences::UNSAVED_VERSION === $expectedVersion) {
            try {
                $this->connection->insert(
                    'identity_interface_preferences',
                    ['workspace_id' => $preferences->workspace->id, ...$columns],
                    ['include_upcoming' => ParameterType::BOOLEAN],
                );
            } catch (UniqueConstraintViolationException) {
                // Another writer inserted the row between this caller's read and its save.
                return false;
            }

            return true;
        }

        return 1 === (int) $this->connection->update(
            'identity_interface_preferences',
            $columns,
            ['workspace_id' => $preferences->workspace->id, 'version' => $expectedVersion],
            $types,
        );
    }
}
