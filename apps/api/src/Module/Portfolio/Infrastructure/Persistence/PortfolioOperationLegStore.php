<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\LegDirection;
use App\Module\Portfolio\Domain\OperationLeg;
use App\Module\Portfolio\Domain\PortfolioOperation;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Batched reads keep history replay bounded; legs follow their immutable parent. */
final readonly class PortfolioOperationLegStore
{
    public function __construct(private Connection $connection, private UuidGenerator $uuid, private \App\Module\Reference\Application\AssetCatalog $assets)
    {
    }

    public function add(PortfolioOperation $operation): void
    {
        foreach ($operation->details->legs as $leg) {
            $this->connection->insert('portfolio_operation_legs', [
                'id' => $this->uuid->generate(), 'workspace_id' => $operation->workspace->id,
                'operation_id' => $operation->id, 'leg_index' => $leg->index, 'direction' => $leg->direction->value,
                'instrument_id' => $leg->instrumentId,
                ...PortfolioOperationRow::decimalColumns('quantity', $leg->quantity),
                ...PortfolioOperationRow::decimalColumns('unit_price', $leg->unitPrice),
                ...PortfolioOperationRow::decimalColumns('amount', $leg->amount),
                ...PortfolioOperationRow::decimalColumns('rounding_residual', $leg->roundingResidual),
            ]);
        }
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<PortfolioOperation>
     */
    public function hydrateMany(WorkspaceScope $workspace, array $rows): array
    {
        if ([] === $rows) {
            return [];
        }
        $displays = [];
        $assetDisplays = [];
        foreach ($rows as $row) {
            $code = PortfolioOperationRow::text($row['asset_code']);
            if (!isset($assetDisplays[$code])) {
                $asset = $this->assets->findByCode(\App\Module\Foundation\Domain\AssetCode::fromString($code)) ?? throw new \LogicException('The operation asset is missing.');
                $assetDisplays[$code] = $asset->precision->display;
            }
            $displays[PortfolioOperationRow::text($row['id'])] = $assetDisplays[$code];
        }
        $ids = array_map(static fn (array $row): string => PortfolioOperationRow::text($row['id']), $rows);
        $legRows = $this->connection->fetchAllAssociative(
            'SELECT operation_id, leg_index, direction, instrument_id, quantity_value, quantity_scale, unit_price_value, unit_price_scale, amount_value, amount_scale, rounding_residual_value, rounding_residual_scale FROM portfolio_operation_legs WHERE workspace_id = :workspace_id AND operation_id IN (:ids) ORDER BY operation_id, leg_index',
            ['workspace_id' => $workspace->id, 'ids' => $ids], ['ids' => ArrayParameterType::STRING],
        );
        $legs = [];
        foreach ($legRows as $row) {
            $legs[PortfolioOperationRow::text($row['operation_id'])][] = new OperationLeg(
                (int) PortfolioOperationRow::text($row['leg_index']),
                LegDirection::from(PortfolioOperationRow::text($row['direction'])),
                PortfolioOperationRow::text($row['instrument_id']),
                PortfolioOperationRow::decimal($row, 'quantity') ?? throw new \LogicException('Missing leg quantity.'),
                PortfolioOperationRow::decimal($row, 'unit_price') ?? throw new \LogicException('Missing leg price.'),
                PortfolioOperationRow::decimal($row, 'amount') ?? throw new \LogicException('Missing leg amount.'),
                24, 24, $displays[PortfolioOperationRow::text($row['operation_id'])],
            );
        }

        return array_map(static fn (array $row): PortfolioOperation => PortfolioOperationRow::hydrate($row, $workspace, $legs[PortfolioOperationRow::text($row['id'])] ?? []), $rows);
    }
}
