<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Reporting\Domain\MonthlyLedgerCalculator;
use App\Module\Reporting\Domain\MonthlyLedgerEntry;
use App\Module\Reporting\Domain\MonthlyLedgerSource;
use App\Module\Reporting\Domain\MonthlyMovementKind;
use App\Module\Reporting\Domain\MonthlySavingsTransfer;
use App\Module\Reporting\Domain\MonthlySplit;
use App\Module\Transactions\Application\MonthlyTransactionFact;
use App\Module\Transactions\Application\MonthlyTransferPairFact;

final class MonthlyLedgerFacts
{
    /**
     * @param list<MonthlyTransactionFact> $facts
     *
     * @return list<MonthlyLedgerEntry>
     */
    public static function entries(array $facts): array
    {
        return array_map(static fn (MonthlyTransactionFact $fact): MonthlyLedgerEntry => new MonthlyLedgerEntry(
            $fact->id,
            $fact->accountId,
            $fact->amount,
            $fact->asset,
            MonthlyMovementKind::from($fact->nature),
            new \DateTimeImmutable($fact->bookedOn),
            $fact->rawLabel,
            array_map(static fn ($split): MonthlySplit => new MonthlySplit(
                $split->categoryId,
                $split->amount,
                $split->analyticAxes,
            ), $fact->splits),
        ), $facts);
    }

    public static function validTransfer(MonthlyTransferPairFact $pair): bool
    {
        return MonthlyLedgerCalculator::validTransfer(self::transfer($pair));
    }

    public static function wellFormedTransfer(MonthlyTransferPairFact $pair): bool
    {
        return MonthlyLedgerCalculator::wellFormedTransfer(self::transfer($pair));
    }

    private static function transfer(MonthlyTransferPairFact $pair): MonthlySavingsTransfer
    {
        return new MonthlySavingsTransfer(
            $pair->transferId,
            $pair->sourceTransactionId,
            $pair->targetTransactionId,
            $pair->sourceAccountId,
            $pair->targetAccountId,
            $pair->sourceAmount,
            $pair->targetAmount,
            $pair->sourceAsset,
            $pair->targetAsset,
            $pair->sourceState,
            $pair->targetState,
            $pair->sourceBookedOn,
            $pair->targetBookedOn,
            $pair->voided,
        );
    }

    /**
     * @param list<MonthlyTransferPairFact> $pairs
     *
     * @return array<string, list<MonthlyLedgerSource>>
     */
    public static function transferSourcesByAccount(array $pairs): array
    {
        $sources = [];
        foreach ($pairs as $pair) {
            if (!self::validTransfer($pair)) {
                continue;
            }
            assert(null !== $pair->sourceAccountId && null !== $pair->targetAccountId);
            assert(null !== $pair->sourceTransactionId && null !== $pair->targetTransactionId);
            assert(null !== $pair->sourceAmount && null !== $pair->targetAmount);
            assert(null !== $pair->sourceAsset && null !== $pair->targetAsset);
            assert(null !== $pair->sourceBookedOn);
            $day = new \DateTimeImmutable((string) $pair->sourceBookedOn);
            $sources[$pair->sourceAccountId][] = new MonthlyLedgerSource(
                (string) $pair->sourceTransactionId,
                (string) $pair->sourceTransactionId,
                $day,
                'Transfer',
                $pair->sourceAmount,
                $pair->sourceAsset,
            );
            $sources[$pair->targetAccountId][] = new MonthlyLedgerSource(
                (string) $pair->targetTransactionId,
                (string) $pair->targetTransactionId,
                $day,
                'Transfer',
                $pair->targetAmount,
                $pair->targetAsset,
            );
        }

        return $sources;
    }
}
