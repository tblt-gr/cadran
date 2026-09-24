<?php

declare(strict_types=1);

namespace App\Module\Reporting\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\RecapPreferences;
use App\Module\Reporting\Domain\RecapPreferencesRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RecapPreferencesRepository::class)]
final readonly class DbalRecapPreferencesRepository implements RecapPreferencesRepository
{
    private const string COLUMNS = 'workspace_id, visible_category_ids, visible_axes, version, updated_at';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, array $knownAxes): RecapPreferences
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM reporting_recap_preferences WHERE workspace_id = :workspace_id',
            ['workspace_id' => $workspace->id],
        );

        return false === $row
            ? RecapPreferences::unsaved($workspace, $knownAxes)
            : RecapPreferencesRow::hydrate($row, $workspace, $knownAxes);
    }

    public function save(RecapPreferences $preferences, int $expectedVersion): bool
    {
        if (RecapPreferences::UNSAVED_VERSION === $expectedVersion) {
            try {
                $this->connection->insert('reporting_recap_preferences', [
                    'workspace_id' => $preferences->workspace->id,
                    ...RecapPreferencesRow::columns($preferences),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another writer inserted the row between this caller's read
                // and its save: the selection it based itself on is gone.
                return false;
            }

            return true;
        }

        return 1 === (int) $this->connection->update(
            'reporting_recap_preferences',
            RecapPreferencesRow::columns($preferences),
            ['workspace_id' => $preferences->workspace->id, 'version' => $expectedVersion],
        );
    }
}
