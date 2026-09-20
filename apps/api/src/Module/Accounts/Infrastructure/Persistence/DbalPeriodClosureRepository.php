<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\PeriodClosureConflict;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PeriodClosureRepository::class)]
final readonly class DbalPeriodClosureRepository implements PeriodClosureRepository
{
    private const string COLUMNS = 'id, workspace_id, year, month, closed_at::text AS closed_at, closed_by, reopened_at::text AS reopened_at, reopen_reason, version';

    public function __construct(private Connection $connection)
    {
    }

    public function lockShared(WorkspaceScope $workspace): void
    {
        $this->connection->executeQuery(
            'SELECT pg_advisory_xact_lock_shared(hashtextextended(:key, 0))',
            ['key' => self::lockKey($workspace)],
        );
    }

    public function lockExclusive(WorkspaceScope $workspace): void
    {
        $this->connection->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
            ['key' => self::lockKey($workspace)],
        );
    }

    public function closedAmong(WorkspaceScope $workspace, array $months): array
    {
        if ([] === $months) {
            return [];
        }
        $conditions = [];
        $parameters = ['workspace_id' => $workspace->id];
        foreach ($months as $index => $month) {
            $conditions[] = sprintf('(year = :year_%1$d AND month = :month_%1$d)', $index);
            $parameters['year_'.$index] = $month->year;
            $parameters['month_'.$index] = $month->month;
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT year, month FROM account_period_closures WHERE workspace_id = :workspace_id AND reopened_at IS NULL AND ('
            .implode(' OR ', $conditions).')',
            $parameters,
        );

        return array_map(
            static fn (array $row): CalendarMonth => new CalendarMonth((int) self::scalar($row['year'] ?? null), (int) self::scalar($row['month'] ?? null)),
            $rows,
        );
    }

    public function hasActive(WorkspaceScope $workspace): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM account_period_closures WHERE workspace_id = :workspace_id AND reopened_at IS NULL LIMIT 1',
            ['workspace_id' => $workspace->id],
        );
    }

    public function activeBetween(WorkspaceScope $workspace, CalendarMonth $from, ?CalendarMonth $to): array
    {
        $parameters = [
            'workspace_id' => $workspace->id,
            'from_index' => $from->year * 12 + $from->month,
        ];
        $upper = '';
        if (null !== $to) {
            $upper = ' AND (year * 12 + month) <= :to_index';
            $parameters['to_index'] = $to->year * 12 + $to->month;
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT year, month FROM account_period_closures WHERE workspace_id = :workspace_id'
            .' AND reopened_at IS NULL AND (year * 12 + month) >= :from_index'.$upper
            .' ORDER BY year, month',
            $parameters,
        );

        return array_map(
            static fn (array $row): CalendarMonth => new CalendarMonth((int) self::scalar($row['year'] ?? null), (int) self::scalar($row['month'] ?? null)),
            $rows,
        );
    }

    public function findActive(WorkspaceScope $workspace, CalendarMonth $month): ?PeriodClosure
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM account_period_closures WHERE workspace_id = :workspace_id'
            .' AND year = :year AND month = :month AND reopened_at IS NULL',
            ['workspace_id' => $workspace->id, 'year' => $month->year, 'month' => $month->month],
        );

        return false === $row ? null : self::hydrate($row, $workspace);
    }

    /** @return list<PeriodClosure> */
    public function listForYear(WorkspaceScope $workspace, int $year): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM account_period_closures WHERE workspace_id = :workspace_id AND year = :year'
            .' ORDER BY month DESC, closed_at DESC, id',
            ['workspace_id' => $workspace->id, 'year' => $year],
        );

        return array_map(static fn (array $row): PeriodClosure => self::hydrate($row, $workspace), $rows);
    }

    public function add(PeriodClosure $closure): void
    {
        try {
            $this->connection->insert('account_period_closures', [
                'id' => $closure->id,
                'workspace_id' => $closure->workspace->id,
                'year' => $closure->month->year,
                'month' => $closure->month->month,
                'closed_at' => $closure->closedAt->format('Y-m-d H:i:s.uP'),
                'closed_by' => $closure->closedBy,
                'reopened_at' => null,
                'reopen_reason' => null,
                'version' => $closure->version,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new PeriodClosureConflict('This period is already closed.', previous: $exception);
        }
    }

    public function update(PeriodClosure $closure, int $expectedVersion): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE account_period_closures SET reopened_at = :reopened_at, reopen_reason = :reopen_reason, version = :version'
            .' WHERE workspace_id = :workspace_id AND id = :id AND version = :expected_version',
            [
                'reopened_at' => $closure->reopenedAt?->format('Y-m-d H:i:s.uP'),
                'reopen_reason' => $closure->reopenReason,
                'version' => $closure->version,
                'workspace_id' => $closure->workspace->id,
                'id' => $closure->id,
                'expected_version' => $expectedVersion,
            ],
        );
    }

    private static function lockKey(WorkspaceScope $workspace): string
    {
        return 'period-closure:'.$workspace->id;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row, WorkspaceScope $workspace): PeriodClosure
    {
        if ($workspace->id !== self::scalar($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A closure row escaped its requested workspace.');
        }
        $reopenedAt = $row['reopened_at'] ?? null;

        return new PeriodClosure(
            id: self::scalar($row['id'] ?? null),
            workspace: $workspace,
            month: new CalendarMonth((int) self::scalar($row['year'] ?? null), (int) self::scalar($row['month'] ?? null)),
            closedAt: new \DateTimeImmutable(self::scalar($row['closed_at'] ?? null)),
            closedBy: self::scalar($row['closed_by'] ?? null),
            version: (int) self::scalar($row['version'] ?? null),
            reopenedAt: null === $reopenedAt ? null : new \DateTimeImmutable(self::scalar($reopenedAt)),
            reopenReason: null === ($row['reopen_reason'] ?? null) ? null : self::scalar($row['reopen_reason']),
        );
    }

    private static function scalar(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \UnexpectedValueException('A closure row carries an unexpected value.');
        }

        return (string) $value;
    }
}
