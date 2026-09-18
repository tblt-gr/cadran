<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
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
        if ($workspace->id !== self::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A transaction row escaped its requested workspace.');
        }

        $asset = AssetCode::fromString(self::text($row['asset_code'] ?? null));
        $originalValue = self::nullableText($row['original_amount_value'] ?? null);
        $originalAsset = self::nullableText($row['original_asset_code'] ?? null);
        $rate = self::nullableText($row['exchange_rate'] ?? null);
        $paymentMethod = self::nullableText($row['payment_method'] ?? null);

        return new Transaction(
            id: self::text($row['id'] ?? null),
            workspace: $workspace,
            accountId: self::text($row['account_id'] ?? null),
            amount: new AssetAmount(
                DecimalValue::fromString(self::decimal($row['amount_value'] ?? null, $row['amount_scale'] ?? null)),
                $asset,
            ),
            originalAmount: null === $originalValue || null === $originalAsset ? null : new AssetAmount(
                DecimalValue::fromString(self::decimal($originalValue, $row['original_amount_scale'] ?? null)),
                AssetCode::fromString($originalAsset),
            ),
            exchangeRate: null === $rate ? null : DecimalValue::fromString(self::trimNumeric($rate)),
            state: TransactionState::from(self::text($row['state'] ?? null)),
            nature: TransactionNature::from(self::text($row['nature'] ?? null)),
            source: TransactionSource::from(self::text($row['source'] ?? null)),
            sourceRef: self::nullableText($row['source_ref'] ?? null),
            bookedOn: self::day($row['booked_on'] ?? null),
            valueOn: self::nullableDay($row['value_on'] ?? null),
            authorizedOn: self::nullableDay($row['authorized_on'] ?? null),
            rawLabel: self::text($row['raw_label'] ?? null),
            counterparty: self::nullableText($row['counterparty'] ?? null),
            note: self::nullableText($row['note'] ?? null),
            paymentMethod: null === $paymentMethod ? null : PaymentMethod::from($paymentMethod),
            mcc: self::nullableText($row['mcc'] ?? null),
            maskedCard: self::nullableText($row['masked_card'] ?? null),
            bankReference: self::nullableText($row['bank_reference'] ?? null),
            splits: $splits,
            version: (int) self::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(self::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(self::text($row['updated_at'] ?? null)),
            voidedAt: self::instant($row['voided_at'] ?? null),
            lastEditorId: self::nullableText($row['last_editor_id'] ?? null),
            reviewReason: null === ($row['review_reason'] ?? null) ? null : ReviewReason::from(self::text($row['review_reason'])),
        );
    }

    /** @return array<string, mixed> */
    public static function mutableColumns(Transaction $transaction): array
    {
        return [
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
        ];
    }

    public static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }

    public static function decimal(mixed $value, mixed $scale): string
    {
        $numeric = self::text($value);
        $requestedScale = (int) self::text($scale);
        [$integer, $fraction] = array_pad(explode('.', $numeric, 2), 2, '');

        return 0 === $requestedScale
            ? $integer
            : $integer.'.'.substr(str_pad($fraction, $requestedScale, '0'), 0, $requestedScale);
    }

    private static function trimNumeric(string $numeric): string
    {
        if (!str_contains($numeric, '.')) {
            return $numeric;
        }

        $trimmed = rtrim(rtrim($numeric, '0'), '.');

        return '-0' === $trimmed ? '0' : $trimmed;
    }

    private static function nullableText(mixed $value): ?string
    {
        return null === $value ? null : self::text($value);
    }

    private static function day(mixed $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::text($value), new \DateTimeZone('UTC'));
    }

    private static function nullableDay(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : self::day($value);
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable(self::text($value));
    }
}
