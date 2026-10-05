<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRate;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRateBasis;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRateRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(EuroFundRateRepository::class)]
final readonly class DbalEuroFundRateRepository implements EuroFundRateRepository
{
    private const string COLUMNS = 'id, contract_id, instrument_id, year, served_rate_value, served_rate_scale, bonus_rate_value, bonus_rate_scale, basis, source_note, recorded_at, recorded_by';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?EuroFundRate
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM portfolio_euro_fund_rates WHERE workspace_id = :workspace_id AND id = :id',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : self::hydrate($row, $workspace);
    }

    public function listForContract(WorkspaceScope $workspace, string $contractId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM portfolio_euro_fund_rates WHERE workspace_id = :workspace_id AND contract_id = :contract_id ORDER BY year DESC, instrument_id',
            ['workspace_id' => $workspace->id, 'contract_id' => $contractId],
        );

        return array_map(static fn (array $row): EuroFundRate => self::hydrate($row, $workspace), $rows);
    }

    public function count(WorkspaceScope $workspace, string $contractId): int
    {
        return (int) ValuationRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM portfolio_euro_fund_rates WHERE workspace_id = :workspace_id AND contract_id = :contract_id',
            ['workspace_id' => $workspace->id, 'contract_id' => $contractId],
        ));
    }

    public function exists(WorkspaceScope $workspace, string $contractId, string $instrumentId, int $year): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM portfolio_euro_fund_rates WHERE workspace_id = :workspace_id AND contract_id = :contract_id AND instrument_id = :instrument_id AND year = :year',
            ['workspace_id' => $workspace->id, 'contract_id' => $contractId, 'instrument_id' => $instrumentId, 'year' => $year],
        );
    }

    public function add(EuroFundRate $rate): void
    {
        $this->connection->insert('portfolio_euro_fund_rates', [
            'id' => $rate->id,
            'workspace_id' => $rate->workspace->id,
            'contract_id' => $rate->contractId,
            'instrument_id' => $rate->instrumentId,
            'year' => $rate->year,
            'served_rate_value' => $rate->servedRate->toString(),
            'served_rate_scale' => $rate->servedRate->scale(),
            'bonus_rate_value' => $rate->bonusRate?->toString(),
            'bonus_rate_scale' => $rate->bonusRate?->scale(),
            'basis' => $rate->basis->value,
            'source_note' => $rate->sourceNote,
            'recorded_at' => $rate->recordedAt->format('Y-m-d H:i:s.uP'),
            'recorded_by' => $rate->recordedBy,
        ]);
    }

    public function delete(WorkspaceScope $workspace, string $id): bool
    {
        return 1 === (int) $this->connection->delete('portfolio_euro_fund_rates', ['workspace_id' => $workspace->id, 'id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row, WorkspaceScope $workspace): EuroFundRate
    {
        return new EuroFundRate(
            id: ValuationRow::text($row['id']),
            workspace: $workspace,
            contractId: ValuationRow::text($row['contract_id']),
            instrumentId: ValuationRow::text($row['instrument_id']),
            year: (int) ValuationRow::text($row['year']),
            servedRate: ValuationRow::decimal($row, 'served_rate') ?? throw new \LogicException('A served rate is required.'),
            bonusRate: ValuationRow::decimal($row, 'bonus_rate'),
            basis: EuroFundRateBasis::from(ValuationRow::text($row['basis'])),
            sourceNote: null === $row['source_note'] ? null : ValuationRow::text($row['source_note']),
            recordedAt: new \DateTimeImmutable(ValuationRow::text($row['recorded_at'])),
            recordedBy: ValuationRow::text($row['recorded_by']),
        );
    }
}
