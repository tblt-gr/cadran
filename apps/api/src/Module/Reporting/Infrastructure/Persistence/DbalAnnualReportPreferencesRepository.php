<?php

declare(strict_types=1);

namespace App\Module\Reporting\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\AnnualReportPreferences;
use App\Module\Reporting\Domain\AnnualReportPreferencesRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AnnualReportPreferencesRepository::class)]
final readonly class DbalAnnualReportPreferencesRepository implements AnnualReportPreferencesRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace): ?AnnualReportPreferences
    {
        $row = $this->connection->fetchAssociative(
            'SELECT workspace_id, columns, incomplete_months, version, updated_at
             FROM reporting_annual_report_preferences WHERE workspace_id = :workspace_id',
            ['workspace_id' => $workspace->id],
        );
        if (false === $row) {
            return null;
        }
        if ($workspace->id !== $row['workspace_id']) {
            throw new \UnexpectedValueException('An annual preference row escaped its requested workspace.');
        }
        $columns = json_decode(RecapPreferencesRow::text($row['columns']), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($columns)) {
            throw new \UnexpectedValueException('Expected an annual preference array column.');
        }

        return new AnnualReportPreferences(
            $workspace,
            array_values(array_map(RecapPreferencesRow::text(...), $columns)),
            IncompleteMonths::from(RecapPreferencesRow::text($row['incomplete_months'])),
            (int) RecapPreferencesRow::text($row['version']),
            new \DateTimeImmutable(RecapPreferencesRow::text($row['updated_at'])),
        );
    }

    public function save(AnnualReportPreferences $preferences, int $expectedVersion): bool
    {
        if (null === $preferences->updatedAt) {
            throw new \UnexpectedValueException('A stored annual selection carries the instant it was saved.');
        }
        $columns = [
            'columns' => json_encode($preferences->columns, JSON_THROW_ON_ERROR),
            'incomplete_months' => $preferences->incompleteMonths->value,
            'version' => $preferences->version,
            'updated_at' => $preferences->updatedAt->format('Y-m-d H:i:s.uP'),
        ];
        if (AnnualReportPreferences::UNSAVED_VERSION === $expectedVersion) {
            try {
                $this->connection->insert('reporting_annual_report_preferences', ['workspace_id' => $preferences->workspace->id, ...$columns]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            return true;
        }

        return 1 === (int) $this->connection->update(
            'reporting_annual_report_preferences',
            $columns,
            ['workspace_id' => $preferences->workspace->id, 'version' => $expectedVersion],
        );
    }
}
