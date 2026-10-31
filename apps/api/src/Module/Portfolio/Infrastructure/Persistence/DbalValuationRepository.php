<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\Valuation\ObservedPrice;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ValuationRepository::class)]
final readonly class DbalValuationRepository implements ValuationRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?Valuation
    {
        return $this->one($workspace, 'id = :id', ['id' => $id], false);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Valuation
    {
        return $this->one($workspace, 'id = :id', ['id' => $id], true);
    }

    public function findActive(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $valuedOn): ?Valuation
    {
        return $this->one($workspace, 'account_id = :account_id AND valued_on = :valued_on AND superseded_at IS NULL', [
            'account_id' => $accountId, 'valued_on' => $valuedOn->format('Y-m-d'),
        ], false);
    }

    public function latestActiveBefore(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $day, bool $withTotalOnly = false, ?ValuationCompleteness $completeness = null): ?Valuation
    {
        $parameters = ['workspace_id' => $workspace->id, 'account_id' => $accountId, 'day' => $day->format('Y-m-d')];
        if (null !== $completeness) {
            $parameters['completeness'] = $completeness->value;
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.ValuationRow::COLUMNS.' FROM portfolio_valuations WHERE workspace_id = :workspace_id AND account_id = :account_id'
            .' AND superseded_at IS NULL AND valued_on < :day'.($withTotalOnly ? ' AND total_value_value IS NOT NULL' : '')
            .(null === $completeness ? '' : ' AND completeness = :completeness').' ORDER BY valued_on DESC LIMIT 1',
            $parameters,
        );

        return [] === $rows ? null : $this->withPositions($workspace, $rows)[0];
    }

    public function firstActiveCompleteOnOrBefore(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $day): ?Valuation
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.ValuationRow::COLUMNS.' FROM portfolio_valuations WHERE workspace_id = :workspace_id AND account_id = :account_id'
            .' AND superseded_at IS NULL AND completeness = :completeness AND valued_on <= :day ORDER BY valued_on ASC, id LIMIT 1',
            ['workspace_id' => $workspace->id, 'account_id' => $accountId, 'completeness' => ValuationCompleteness::COMPLETE->value, 'day' => $day->format('Y-m-d')],
        );

        return [] === $rows ? null : $this->withPositions($workspace, $rows)[0];
    }

    public function add(Valuation $valuation): void
    {
        $this->connection->insert('portfolio_valuations', ['workspace_id' => $valuation->workspace->id, ...ValuationRow::columns($valuation)], ['withdrawn' => ParameterType::BOOLEAN]);
        foreach ($valuation->positions as $snapshot) {
            $this->connection->insert('portfolio_position_snapshots', ['workspace_id' => $valuation->workspace->id, ...ValuationRow::snapshotColumns($snapshot)]);
        }
    }

    public function markSuperseded(Valuation $superseded, int $expectedVersion): bool
    {
        return 1 === (int) $this->connection->update(
            'portfolio_valuations',
            ValuationRow::supersessionColumns($superseded),
            ['workspace_id' => $superseded->workspace->id, 'id' => $superseded->id, 'version' => $expectedVersion],
            ['withdrawn' => ParameterType::BOOLEAN],
        );
    }

    public function countActive(WorkspaceScope $workspace, string $accountId): int
    {
        return (int) ValuationRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM portfolio_valuations WHERE workspace_id = :workspace_id AND account_id = :account_id AND superseded_at IS NULL',
            ['workspace_id' => $workspace->id, 'account_id' => $accountId],
        ));
    }

    public function search(WorkspaceScope $workspace, string $accountId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit, int $offset): array
    {
        [$where, $parameters] = $this->filter($workspace, $accountId, $from, $to);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.ValuationRow::COLUMNS.' FROM portfolio_valuations WHERE workspace_id = :workspace_id'.$where.' ORDER BY valued_on DESC, id LIMIT :limit OFFSET :offset',
            [...$parameters, 'limit' => $limit, 'offset' => $offset],
        );

        return $this->withPositions($workspace, $rows);
    }

    public function count(WorkspaceScope $workspace, string $accountId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int
    {
        [$where, $parameters] = $this->filter($workspace, $accountId, $from, $to);

        return (int) ValuationRow::text($this->connection->fetchOne('SELECT count(*) FROM portfolio_valuations WHERE workspace_id = :workspace_id'.$where, $parameters));
    }

    public function completeMonths(WorkspaceScope $workspace, array $accountIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ([] === $accountIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT account_id, to_char(valued_on, 'YYYY-MM') AS month FROM portfolio_valuations WHERE workspace_id = :workspace_id"
            ." AND account_id IN (:account_ids) AND superseded_at IS NULL AND completeness = 'COMPLETE' AND valued_on >= :from AND valued_on <= :to",
            ['workspace_id' => $workspace->id, 'account_ids' => $accountIds, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            ['account_ids' => ArrayParameterType::STRING],
        );
        $months = [];
        foreach ($rows as $row) {
            $months[ValuationRow::text($row['account_id'] ?? null)][] = ValuationRow::text($row['month'] ?? null);
        }

        return $months;
    }

    public function observedPrices(WorkspaceScope $workspace, string $accountId, array $instrumentIds, \DateTimeImmutable $onOrBefore): array
    {
        if ([] === $instrumentIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.instrument_id, s.unit_price_value, s.unit_price_scale, s.price_date, s.valuation_id'
            .' FROM portfolio_position_snapshots s JOIN portfolio_valuations v ON v.id = s.valuation_id AND v.workspace_id = s.workspace_id'
            .' WHERE s.workspace_id = :workspace_id AND v.workspace_id = :workspace_id AND v.account_id = :account_id AND v.superseded_at IS NULL'
            .' AND s.unit_price_value IS NOT NULL AND s.price_date <= :on_or_before AND s.instrument_id IN (:instruments)'
            .' ORDER BY s.price_date DESC, v.recorded_at DESC, s.id',
            ['workspace_id' => $workspace->id, 'account_id' => $accountId, 'on_or_before' => $onOrBefore->format('Y-m-d'), 'instruments' => $instrumentIds],
            ['instruments' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): ObservedPrice => new ObservedPrice(
            ValuationRow::text($row['instrument_id']),
            ValuationRow::decimal($row, 'unit_price') ?? throw new \UnexpectedValueException('A priced snapshot has a price.'),
            ValuationRow::day($row['price_date']),
            ValuationRow::text($row['valuation_id']),
        ), $rows);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function one(WorkspaceScope $workspace, string $condition, array $parameters, bool $lock): ?Valuation
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.ValuationRow::COLUMNS.' FROM portfolio_valuations WHERE workspace_id = :workspace_id AND '.$condition.($lock ? ' FOR UPDATE' : ''),
            ['workspace_id' => $workspace->id, ...$parameters],
        );

        return [] === $rows ? null : $this->withPositions($workspace, $rows)[0];
    }

    /** @return array{string, array<string, mixed>} */
    private function filter(WorkspaceScope $workspace, string $accountId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $where = ' AND account_id = :account_id AND superseded_at IS NULL';
        $parameters = ['workspace_id' => $workspace->id, 'account_id' => $accountId];
        if (null !== $from) {
            $where .= ' AND valued_on >= :from';
            $parameters['from'] = $from->format('Y-m-d');
        }
        if (null !== $to) {
            $where .= ' AND valued_on <= :to';
            $parameters['to'] = $to->format('Y-m-d');
        }

        return [$where, $parameters];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Valuation>
     */
    private function withPositions(WorkspaceScope $workspace, array $rows): array
    {
        $ids = array_map(static fn (array $row): string => ValuationRow::text($row['id']), $rows);
        $byValuation = [];
        if ([] !== $ids) {
            $snapshotRows = $this->connection->fetchAllAssociative(
                'SELECT '.ValuationRow::SNAPSHOT_COLUMNS.' FROM portfolio_position_snapshots WHERE workspace_id = :workspace_id AND valuation_id IN (:ids) ORDER BY instrument_id',
                ['workspace_id' => $workspace->id, 'ids' => $ids],
                ['ids' => ArrayParameterType::STRING],
            );
            foreach ($snapshotRows as $snapshotRow) {
                $snapshot = ValuationRow::snapshot($snapshotRow);
                $byValuation[$snapshot->valuationId][] = $snapshot;
            }
        }

        return array_map(
            static fn (array $row): Valuation => ValuationRow::hydrate($row, $workspace, $byValuation[ValuationRow::text($row['id'])] ?? []),
            $rows,
        );
    }
}
