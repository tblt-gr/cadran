<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTerms;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTermsRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(FeeTermsRepository::class)]
final readonly class DbalFeeTermsRepository implements FeeTermsRepository
{
    private const string COLUMNS = 'id, contract_id, valid_from, entry_fee_rate_value, entry_fee_rate_scale, euro_fund_management_fee_rate_value, euro_fund_management_fee_rate_scale, unit_linked_management_fee_rate_value, unit_linked_management_fee_rate_scale, switch_fee_rate_value, switch_fee_rate_scale, switch_fee_fixed_value, switch_fee_fixed_scale, source_note, recorded_at, recorded_by';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?FeeTerms
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM portfolio_life_insurance_fee_terms t WHERE t.workspace_id = :workspace_id AND id = :id',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : self::hydrate($row, $workspace);
    }

    public function listForContract(WorkspaceScope $workspace, string $contractId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM portfolio_life_insurance_fee_terms t WHERE t.workspace_id = :workspace_id AND contract_id = :contract_id ORDER BY valid_from DESC, id',
            ['workspace_id' => $workspace->id, 'contract_id' => $contractId],
        );

        return array_map(static fn (array $row): FeeTerms => self::hydrate($row, $workspace), $rows);
    }

    public function count(WorkspaceScope $workspace, string $contractId): int
    {
        return (int) ValuationRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM portfolio_life_insurance_fee_terms t WHERE t.workspace_id = :workspace_id AND contract_id = :contract_id',
            ['workspace_id' => $workspace->id, 'contract_id' => $contractId],
        ));
    }

    public function existsFrom(WorkspaceScope $workspace, string $contractId, \DateTimeImmutable $validFrom): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM portfolio_life_insurance_fee_terms t WHERE t.workspace_id = :workspace_id AND contract_id = :contract_id AND valid_from = :valid_from',
            ['workspace_id' => $workspace->id, 'contract_id' => $contractId, 'valid_from' => $validFrom->format('Y-m-d')],
        );
    }

    public function add(FeeTerms $terms): void
    {
        $this->connection->insert('portfolio_life_insurance_fee_terms', [
            'id' => $terms->id,
            'workspace_id' => $terms->workspace->id,
            'contract_id' => $terms->contractId,
            'valid_from' => $terms->validFrom->format('Y-m-d'),
            ...self::decimal('entry_fee_rate', $terms->entryFeeRate),
            ...self::decimal('euro_fund_management_fee_rate', $terms->euroFundManagementFeeRate),
            ...self::decimal('unit_linked_management_fee_rate', $terms->unitLinkedManagementFeeRate),
            ...self::decimal('switch_fee_rate', $terms->switchFeeRate),
            ...self::decimal('switch_fee_fixed', $terms->switchFeeFixed),
            'source_note' => $terms->sourceNote,
            'recorded_at' => $terms->recordedAt->format('Y-m-d H:i:s.uP'),
            'recorded_by' => $terms->recordedBy,
        ]);
    }

    public function delete(WorkspaceScope $workspace, string $id): bool
    {
        return 1 === (int) $this->connection->delete('portfolio_life_insurance_fee_terms', ['workspace_id' => $workspace->id, 'id' => $id]);
    }

    /** @return array<string, string|int|null> */
    private static function decimal(string $name, ?DecimalValue $value): array
    {
        return [$name.'_value' => $value?->toString(), $name.'_scale' => $value?->scale()];
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row, WorkspaceScope $workspace): FeeTerms
    {
        return new FeeTerms(
            id: ValuationRow::text($row['id']),
            workspace: $workspace,
            contractId: ValuationRow::text($row['contract_id']),
            validFrom: ValuationRow::day($row['valid_from']),
            entryFeeRate: ValuationRow::decimal($row, 'entry_fee_rate'),
            euroFundManagementFeeRate: ValuationRow::decimal($row, 'euro_fund_management_fee_rate'),
            unitLinkedManagementFeeRate: ValuationRow::decimal($row, 'unit_linked_management_fee_rate'),
            switchFeeRate: ValuationRow::decimal($row, 'switch_fee_rate'),
            switchFeeFixed: ValuationRow::decimal($row, 'switch_fee_fixed'),
            sourceNote: null === $row['source_note'] ? null : ValuationRow::text($row['source_note']),
            recordedAt: new \DateTimeImmutable(ValuationRow::text($row['recorded_at'])),
            recordedBy: ValuationRow::text($row['recorded_by']),
        );
    }
}
