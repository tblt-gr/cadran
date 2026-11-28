<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\Infrastructure\Persistence\DbalValue;
use App\Module\Transactions\Domain\PaymentMethod;
use App\Module\Transactions\Domain\Reconciliation\ReviewReason;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;

final readonly class TransactionRow
{
    /**
     * @param array<string, mixed>   $row
     * @param list<TransactionSplit> $splits
     */
    public static function hydrate(array $row, WorkspaceScope $workspace, array $splits): Transaction
    {
        if ($workspace->id !== DbalValue::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A transaction row escaped its requested workspace.');
        }

        $asset = AssetCode::fromString(DbalValue::text($row['asset_code'] ?? null));
        $originalValue = DbalValue::nullableText($row['original_amount_value'] ?? null);
        $originalAsset = DbalValue::nullableText($row['original_asset_code'] ?? null);
        $rate = DbalValue::nullableText($row['exchange_rate'] ?? null);
        $paymentMethod = DbalValue::nullableText($row['payment_method'] ?? null);

        return new Transaction(
            id: DbalValue::text($row['id'] ?? null),
            workspace: $workspace,
            accountId: DbalValue::text($row['account_id'] ?? null),
            amount: new AssetAmount(
                DecimalValue::fromString(self::decimal($row['amount_value'] ?? null, $row['amount_scale'] ?? null)),
                $asset,
            ),
            originalAmount: null === $originalValue || null === $originalAsset ? null : new AssetAmount(
                DecimalValue::fromString(self::decimal($originalValue, $row['original_amount_scale'] ?? null)),
                AssetCode::fromString($originalAsset),
            ),
            exchangeRate: null === $rate ? null : DecimalValue::fromString(self::trimNumeric($rate)),
            state: TransactionState::from(DbalValue::text($row['state'] ?? null)),
            nature: TransactionNature::from(DbalValue::text($row['nature'] ?? null)),
            source: TransactionSource::from(DbalValue::text($row['source'] ?? null)),
            sourceRef: DbalValue::nullableText($row['source_ref'] ?? null),
            bookedOn: DbalValue::businessDay($row['booked_on'] ?? null),
            valueOn: self::nullableDay($row['value_on'] ?? null),
            authorizedOn: self::nullableDay($row['authorized_on'] ?? null),
            rawLabel: DbalValue::text($row['raw_label'] ?? null),
            counterparty: DbalValue::nullableText($row['counterparty'] ?? null),
            note: DbalValue::nullableText($row['note'] ?? null),
            paymentMethod: null === $paymentMethod ? null : PaymentMethod::from($paymentMethod),
            mcc: DbalValue::nullableText($row['mcc'] ?? null),
            maskedCard: DbalValue::nullableText($row['masked_card'] ?? null),
            bankReference: DbalValue::nullableText($row['bank_reference'] ?? null),
            splits: $splits,
            version: (int) DbalValue::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(DbalValue::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(DbalValue::text($row['updated_at'] ?? null)),
            voidedAt: DbalValue::nullableInstant($row['voided_at'] ?? null),
            lastEditorId: DbalValue::nullableText($row['last_editor_id'] ?? null),
            userLabel: DbalValue::nullableText($row['user_label'] ?? null),
            reviewReason: null === ($row['review_reason'] ?? null) ? null : ReviewReason::from(DbalValue::text($row['review_reason'])),
            autoBook: (bool) ($row['auto_book'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public static function mutableColumns(Transaction $transaction): array
    {
        return [
            'account_id' => $transaction->accountId,
            'amount_value' => $transaction->amount->value->toString(),
            'amount_scale' => $transaction->amount->value->scale(),
            'state' => $transaction->state->value,
            'nature' => $transaction->nature->value,
            'booked_on' => $transaction->bookedOn->format('Y-m-d'),
            'value_on' => $transaction->valueOn?->format('Y-m-d'),
            'authorized_on' => $transaction->authorizedOn?->format('Y-m-d'),
            'counterparty' => $transaction->counterparty,
            'note' => $transaction->note,
            'payment_method' => $transaction->paymentMethod?->value,
            'mcc' => $transaction->mcc,
            'masked_card' => $transaction->maskedCard,
            'bank_reference' => $transaction->bankReference,
            'version' => $transaction->version,
            'updated_at' => $transaction->updatedAt->format('Y-m-d H:i:s.uP'),
            'voided_at' => $transaction->voidedAt?->format('Y-m-d H:i:s.uP'),
            'last_editor_id' => $transaction->lastEditorId,
            'review_reason' => $transaction->reviewReason?->value,
            'user_label' => $transaction->userLabel,
            'auto_book' => $transaction->autoBook,
        ];
    }

    /**
     * A PostgreSQL array literal for a bound parameter: each element is quoted on its own, so no
     * element can end the literal or smuggle another one into it.
     *
     * @param list<string> $values
     */
    public static function postgresArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"', $values)).'}';
    }

    public static function decimal(mixed $value, mixed $scale): string
    {
        $numeric = DbalValue::text($value);
        $requestedScale = (int) DbalValue::text($scale);
        [$integer, $fraction] = array_pad(explode('.', $numeric, 2), 2, '');

        return 0 === $requestedScale
            ? $integer
            : $integer.'.'.substr(str_pad($fraction, $requestedScale, '0'), 0, $requestedScale);
    }

    public static function canonicalNumeric(string $numeric): string
    {
        if (!str_contains($numeric, '.')) {
            return $numeric;
        }
        $trimmed = rtrim(rtrim($numeric, '0'), '.');

        return '-0' === $trimmed ? '0' : $trimmed;
    }

    private static function trimNumeric(string $numeric): string
    {
        if (!str_contains($numeric, '.')) {
            return $numeric;
        }

        $trimmed = rtrim(rtrim($numeric, '0'), '.');

        return '-0' === $trimmed ? '0' : $trimmed;
    }

    private static function nullableDay(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : DbalValue::businessDay($value);
    }
}
