<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Recurrence\OccurrenceStatus;
use App\Module\Transactions\Domain\Recurrence\RecurrenceIntervalKind;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrence;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrence;

/**
 * Row shape of a recurrence and of its generated instalments.
 *
 * Amounts travel with the scale they were submitted at, exactly as
 * {@see TransactionRow} does for a movement: `NUMERIC(50,24)` pads every stored
 * figure, and forgetting the scale column would show `-14.99` back as
 * `-14.990000000000000000000000`.
 */
final readonly class TransactionRecurrenceRow
{
    public const string COLUMNS = 'id, workspace_id, account_id, label, counterparty, expected_amount_value, '
        .'expected_amount_scale, asset_code, amount_tolerance_value, amount_tolerance_scale, interval_kind, '
        .'day_of_period, next_expected_on, confirmed_at, version, created_at, updated_at, archived_at';

    public const string OCCURRENCE_COLUMNS = 'o.id, o.workspace_id, o.recurrence_id, o.expected_on, '
        .'o.expected_amount_value, o.expected_amount_scale, o.amount_tolerance_value, o.amount_tolerance_scale, '
        .'o.matched_transaction_id, o.matched_at, o.status, r.asset_code';

    /** @param array<string, mixed> $row */
    public static function hydrate(array $row, WorkspaceScope $workspace): TransactionRecurrence
    {
        if ($workspace->id !== TransactionRow::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A recurrence row escaped its requested workspace.');
        }
        $asset = AssetCode::fromString(TransactionRow::text($row['asset_code'] ?? null));
        $counterparty = $row['counterparty'] ?? null;

        return new TransactionRecurrence(
            id: TransactionRow::text($row['id'] ?? null),
            workspace: $workspace,
            accountId: TransactionRow::text($row['account_id'] ?? null),
            label: TransactionRow::text($row['label'] ?? null),
            counterparty: null === $counterparty ? null : TransactionRow::text($counterparty),
            expectedAmount: self::amount($row['expected_amount_value'] ?? null, $row['expected_amount_scale'] ?? null, $asset),
            amountTolerance: self::amount($row['amount_tolerance_value'] ?? null, $row['amount_tolerance_scale'] ?? null, $asset),
            intervalKind: RecurrenceIntervalKind::from(TransactionRow::text($row['interval_kind'] ?? null)),
            dayOfPeriod: (int) TransactionRow::text($row['day_of_period'] ?? null),
            nextExpectedOn: self::day($row['next_expected_on'] ?? null),
            confirmedAt: new \DateTimeImmutable(TransactionRow::text($row['confirmed_at'] ?? null)),
            version: (int) TransactionRow::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(TransactionRow::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(TransactionRow::text($row['updated_at'] ?? null)),
            archivedAt: null === ($row['archived_at'] ?? null) ? null : new \DateTimeImmutable(TransactionRow::text($row['archived_at'])),
        );
    }

    /**
     * An instalment stores no asset of its own — a forecast is only ever read
     * through the recurrence it belongs to — so every occurrence query joins
     * that recurrence and carries its `asset_code` into this row.
     *
     * @param array<string, mixed> $row
     */
    public static function hydrateOccurrence(array $row, WorkspaceScope $workspace): TransactionRecurrenceOccurrence
    {
        if ($workspace->id !== TransactionRow::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A recurrence occurrence row escaped its requested workspace.');
        }
        $asset = AssetCode::fromString(TransactionRow::text($row['asset_code'] ?? null));
        $matched = $row['matched_transaction_id'] ?? null;

        return new TransactionRecurrenceOccurrence(
            TransactionRow::text($row['id'] ?? null),
            $workspace,
            TransactionRow::text($row['recurrence_id'] ?? null),
            self::day($row['expected_on'] ?? null),
            self::amount($row['expected_amount_value'] ?? null, $row['expected_amount_scale'] ?? null, $asset),
            self::amount($row['amount_tolerance_value'] ?? null, $row['amount_tolerance_scale'] ?? null, $asset),
            null === $matched ? null : TransactionRow::text($matched),
            null === ($row['matched_at'] ?? null) ? null : new \DateTimeImmutable(TransactionRow::text($row['matched_at'])),
            OccurrenceStatus::from(TransactionRow::text($row['status'] ?? null)),
        );
    }

    /** @return array<string, mixed> */
    public static function columns(TransactionRecurrence $recurrence): array
    {
        return [
            'account_id' => $recurrence->accountId,
            'label' => $recurrence->label,
            'counterparty' => $recurrence->counterparty,
            'expected_amount_value' => $recurrence->expectedAmount->value->toString(),
            'expected_amount_scale' => $recurrence->expectedAmount->value->scale(),
            'asset_code' => $recurrence->expectedAmount->asset->toString(),
            'amount_tolerance_value' => $recurrence->amountTolerance->value->toString(),
            'amount_tolerance_scale' => $recurrence->amountTolerance->value->scale(),
            'interval_kind' => $recurrence->intervalKind->value,
            'day_of_period' => $recurrence->dayOfPeriod,
            'next_expected_on' => $recurrence->nextExpectedOn->format('Y-m-d'),
            'confirmed_at' => $recurrence->confirmedAt->format('Y-m-d H:i:s.uP'),
            'version' => $recurrence->version,
            'created_at' => $recurrence->createdAt->format('Y-m-d H:i:s.uP'),
            'updated_at' => $recurrence->updatedAt->format('Y-m-d H:i:s.uP'),
            'archived_at' => $recurrence->archivedAt?->format('Y-m-d H:i:s.uP'),
        ];
    }

    /** @return array<string, mixed> */
    public static function occurrenceColumns(TransactionRecurrenceOccurrence $occurrence): array
    {
        return [
            'id' => $occurrence->id,
            'workspace_id' => $occurrence->workspace->id,
            'recurrence_id' => $occurrence->recurrenceId,
            'expected_on' => $occurrence->expectedOn->format('Y-m-d'),
            'expected_amount_value' => $occurrence->expectedAmount->value->toString(),
            'expected_amount_scale' => $occurrence->expectedAmount->value->scale(),
            'amount_tolerance_value' => $occurrence->amountTolerance->value->toString(),
            'amount_tolerance_scale' => $occurrence->amountTolerance->value->scale(),
            'matched_transaction_id' => $occurrence->matchedTransactionId,
            'matched_at' => $occurrence->matchedAt?->format('Y-m-d H:i:s.uP'),
            'status' => $occurrence->status->value,
        ];
    }

    private static function amount(mixed $value, mixed $scale, AssetCode $asset): AssetAmount
    {
        return new AssetAmount(DecimalValue::fromString(TransactionRow::decimal($value, $scale)), $asset);
    }

    private static function day(mixed $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable(TransactionRow::text($value), new \DateTimeZone('UTC'));
    }
}
