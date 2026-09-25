<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

final class MonthlySavingsTransferCalculator
{
    /**
     * @param list<string>                 $accountAssets
     * @param array<string, bool>          $savingsDestinationById whether each account sits on the
     *                                                             savings side of a transfer
     *                                                             (`AccountKind::isSavingsDestination()`,
     *                                                             read from Accounts through the
     *                                                             account facts this module already
     *                                                             consumes; this calculator does not
     *                                                             decide the perimeter itself)
     * @param list<MonthlySavingsTransfer> $pairs
     */
    public static function compute(
        array $accountAssets,
        array $savingsDestinationById,
        array $pairs,
        MonthlyMetric $cashIncome,
    ): MonthlySavingsTransferMetrics {
        $assets = array_values(array_unique($accountAssets));
        if (count($assets) > 1) {
            return self::allMissing(MonthlyProjectionReason::MIXED_ASSETS);
        }
        if ([] === $assets) {
            return self::allMissing(MonthlyProjectionReason::NO_ACCOUNT);
        }

        $asset = AssetCode::fromString($assets[0]);
        $inflows = DecimalValue::zero();
        $withdrawals = DecimalValue::zero();
        $sourceTransactions = ['inflows' => [], 'withdrawals' => []];
        $sourceTransfers = ['inflows' => [], 'withdrawals' => []];
        $pendingCounts = ['inflows' => 0, 'withdrawals' => 0];

        foreach ($pairs as $pair) {
            if ($pair->voided) {
                continue;
            }
            $sourceTransactionId = $pair->sourceTransactionId;
            $targetTransactionId = $pair->targetTransactionId;
            $sourceAccountId = $pair->sourceAccountId;
            $targetAccountId = $pair->targetAccountId;
            $sourceAmount = $pair->sourceAmount;
            $targetAmount = $pair->targetAmount;
            $sourceAsset = $pair->sourceAsset;
            $targetAsset = $pair->targetAsset;
            $sourceState = $pair->sourceState;
            $targetState = $pair->targetState;
            $sourceBookedOn = $pair->sourceBookedOn;
            $targetBookedOn = $pair->targetBookedOn;
            if (null === $sourceTransactionId
                || null === $targetTransactionId
                || null === $sourceAccountId
                || null === $targetAccountId
                || null === $sourceAmount
                || null === $targetAmount
                || null === $sourceAsset
                || null === $targetAsset
                || null === $sourceState
                || null === $targetState
                || null === $sourceBookedOn
                || null === $targetBookedOn) {
                return self::allMissing(
                    MonthlyProjectionReason::INCOMPLETE_TRANSFER_PAIR,
                    self::pairTransactionIds($pair),
                    [$pair->transferId],
                );
            }
            if ($sourceState !== $targetState || $sourceBookedOn !== $targetBookedOn) {
                return self::allMissing(
                    MonthlyProjectionReason::MISMATCHED_TRANSFER_PAIR,
                    self::pairTransactionIds($pair),
                    [$pair->transferId],
                );
            }
            if (!$sourceAsset->equals($targetAsset) || !$sourceAsset->equals($asset)) {
                return self::allMissing(
                    MonthlyProjectionReason::MIXED_ASSETS,
                    self::pairTransactionIds($pair),
                    [$pair->transferId],
                );
            }
            if (!$sourceAmount->isNegative()
                || $targetAmount->isNegative()
                || !ExactDecimal::isZero(ExactDecimal::add($sourceAmount, $targetAmount))) {
                return self::allMissing(
                    MonthlyProjectionReason::MISMATCHED_TRANSFER_PAIR,
                    self::pairTransactionIds($pair),
                    [$pair->transferId],
                );
            }

            $sourceInside = $savingsDestinationById[$sourceAccountId] ?? null;
            $targetInside = $savingsDestinationById[$targetAccountId] ?? null;
            if (null === $sourceInside || null === $targetInside) {
                return self::allMissing(
                    MonthlyProjectionReason::MISSING_ACCOUNT_CLASSIFICATION,
                    self::pairTransactionIds($pair),
                    [$pair->transferId],
                );
            }

            if ($sourceInside === $targetInside) {
                continue;
            }
            $direction = $targetInside ? 'inflows' : 'withdrawals';
            if ('PENDING' === $sourceState) {
                ++$pendingCounts[$direction];
                continue;
            }
            if (in_array($sourceState, ['VOIDED', 'REJECTED'], true)) {
                continue;
            }
            if ('BOOKED' !== $sourceState) {
                return self::allMissing(
                    MonthlyProjectionReason::MISMATCHED_TRANSFER_PAIR,
                    self::pairTransactionIds($pair),
                    [$pair->transferId],
                );
            }

            $amount = $targetInside ? $targetAmount : ExactDecimal::negate($sourceAmount);
            if ($targetInside) {
                $inflows = ExactDecimal::add($inflows, $amount);
            } else {
                $withdrawals = ExactDecimal::add($withdrawals, $amount);
            }
            foreach (self::pairTransactionIds($pair) as $transactionId) {
                $sourceTransactions[$direction][$transactionId] = true;
            }
            $sourceTransfers[$direction][$pair->transferId] = true;
        }

        $inflowTransactions = self::sortedKeys($sourceTransactions['inflows']);
        $withdrawalTransactions = self::sortedKeys($sourceTransactions['withdrawals']);
        $inflowTransfers = self::sortedKeys($sourceTransfers['inflows']);
        $withdrawalTransfers = self::sortedKeys($sourceTransfers['withdrawals']);
        $netTransactions = self::sortedUnique([...$inflowTransactions, ...$withdrawalTransactions]);
        $netTransfers = self::sortedUnique([...$inflowTransfers, ...$withdrawalTransfers]);
        $net = ExactDecimal::subtract($inflows, $withdrawals);
        $netPendingCount = $pendingCounts['inflows'] + $pendingCounts['withdrawals'];
        $rateTransactions = self::sortedUnique([...$cashIncome->sourceTransactionIds, ...$netTransactions]);

        $rate = null === $cashIncome->value || ExactDecimal::isZero($cashIncome->value)
            ? MonthlyMetric::missing(
                null === $cashIncome->value && null !== $cashIncome->reason && MonthlyProjectionReason::ZERO_CASH_INCOME !== $cashIncome->reason
                    ? $cashIncome->reason
                    : MonthlyProjectionReason::ZERO_CASH_INCOME,
                $rateTransactions,
                $netPendingCount + $cashIncome->pendingCount,
                $netTransfers,
            )
            : MonthlyMetric::value(
                ExactDecimal::divide($net, $cashIncome->value),
                sourceTransactionIds: $rateTransactions,
                pendingCount: $netPendingCount + $cashIncome->pendingCount,
                sourceTransferIds: $netTransfers,
            );

        return new MonthlySavingsTransferMetrics(
            MonthlyMetric::value($inflows, $asset, $inflowTransactions, $pendingCounts['inflows'], $inflowTransfers),
            MonthlyMetric::value($withdrawals, $asset, $withdrawalTransactions, $pendingCounts['withdrawals'], $withdrawalTransfers),
            MonthlyMetric::value($net, $asset, $netTransactions, $netPendingCount, $netTransfers),
            $rate,
        );
    }

    /** @return list<string> */
    private static function pairTransactionIds(MonthlySavingsTransfer $pair): array
    {
        return self::sortedUnique(array_values(array_filter(
            [$pair->sourceTransactionId, $pair->targetTransactionId],
            static fn (?string $id): bool => null !== $id,
        )));
    }

    /**
     * @param array<string, true> $set
     *
     * @return list<string>
     */
    private static function sortedKeys(array $set): array
    {
        return self::sortedUnique(array_keys($set));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param list<string> $sourceTransactionIds
     * @param list<string> $sourceTransferIds
     */
    private static function allMissing(
        MonthlyProjectionReason $reason,
        array $sourceTransactionIds = [],
        array $sourceTransferIds = [],
    ): MonthlySavingsTransferMetrics {
        $metric = MonthlyMetric::missing($reason, $sourceTransactionIds, sourceTransferIds: $sourceTransferIds);

        return new MonthlySavingsTransferMetrics($metric, $metric, $metric, $metric);
    }
}
