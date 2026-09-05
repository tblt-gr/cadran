<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Translates between a snapshot and the row that stores it.
 *
 * The stored numeric column is for later aggregation. The literal is what
 * the domain hydrates: trailing zeros are the source scale and must survive
 * a round trip.
 */
final readonly class AccountBalanceSnapshotRow
{
    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row, WorkspaceScope $workspace): AccountBalanceSnapshot
    {
        if ($workspace->id !== AccountRow::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A snapshot row escaped its requested workspace.');
        }

        return new AccountBalanceSnapshot(
            id: AccountRow::text($row['id'] ?? null),
            workspace: $workspace,
            accountId: AccountRow::text($row['account_id'] ?? null),
            asOf: new \DateTimeImmutable(AccountRow::text($row['as_of'] ?? null), new \DateTimeZone('UTC')),
            amount: new AssetAmount(
                DecimalValue::fromString(AccountRow::text($row['amount_literal'] ?? null)),
                AssetCode::fromString(AccountRow::text($row['amount_asset'] ?? null)),
            ),
            source: BalanceSnapshotSource::from(AccountRow::text($row['source'] ?? null)),
            reconciliationStatus: ReconciliationStatus::from(AccountRow::text($row['reconciliation_status'] ?? null)),
            comment: null === ($row['comment'] ?? null) ? null : AccountRow::text($row['comment']),
            version: (int) AccountRow::text($row['version'] ?? null),
            recordedAt: new \DateTimeImmutable(AccountRow::text($row['recorded_at'] ?? null)),
            recordedBy: AccountRow::text($row['recorded_by'] ?? null),
            supersededAt: null === ($row['superseded_at'] ?? null)
                ? null
                : new \DateTimeImmutable(AccountRow::text($row['superseded_at'])),
        );
    }

    /** @return array<string, mixed> */
    public static function columns(AccountBalanceSnapshot $snapshot): array
    {
        return [
            'account_id' => $snapshot->accountId,
            'as_of' => $snapshot->asOf->format('Y-m-d'),
            'amount_value' => $snapshot->amount->value->toString(),
            'amount_literal' => $snapshot->amount->value->toString(),
            'amount_asset' => $snapshot->amount->asset->toString(),
            'source' => $snapshot->source->value,
            'reconciliation_status' => $snapshot->reconciliationStatus->value,
            'comment' => $snapshot->comment,
            'version' => $snapshot->version,
            'recorded_at' => $snapshot->recordedAt->format('Y-m-d H:i:s.uP'),
            'recorded_by' => $snapshot->recordedBy,
            'superseded_at' => $snapshot->supersededAt?->format('Y-m-d H:i:s.uP'),
        ];
    }
}
