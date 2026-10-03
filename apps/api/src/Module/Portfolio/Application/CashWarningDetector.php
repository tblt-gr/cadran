<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Domain\Cash\CashLedgerResult;
use App\Module\Portfolio\Domain\Cash\CashWarning;
use App\Module\Portfolio\Domain\Cash\CashWarningCode;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Transactions\Application\AccountTransactionFact;
use App\Module\Transactions\Application\TransferLegFact;

/** Every cash inconsistency of a period, in a fixed order. Nothing here blocks a read. */
final class CashWarningDetector
{
    /**
     * @param list<PortfolioOperation>     $operations   active operations of the range
     * @param list<AccountTransactionFact> $transfers    transfer legs of the account in the range
     * @param list<AccountTransactionFact> $nonTransfer  income, expense, refund and fee transactions of the account in the range
     * @param list<string>                 $linkedLegIds legs among $transfers that back an active operation
     * @param list<TransferLegFact>        $linkedLegs   the legs the operations of the range point at
     *
     * @return list<CashWarning>
     */
    public static function detect(CashLedgerResult $ledger, array $operations, array $transfers, array $nonTransfer, array $linkedLegIds, array $linkedLegs): array
    {
        $linked = array_flip($linkedLegIds);
        $unlinkedIn = [];
        $unlinkedOut = [];
        foreach ($transfers as $leg) {
            if ($leg->voided || 'BOOKED' !== $leg->state || isset($linked[$leg->id])) {
                continue;
            }
            if ($leg->amount->value->isNegative()) {
                $unlinkedOut[] = $leg->id;
            } else {
                $unlinkedIn[] = $leg->id;
            }
        }

        $legsById = [];
        foreach ($linkedLegs as $leg) {
            $legsById[$leg->id] = $leg;
        }
        $external = [];
        $changed = [];
        $residuals = [];
        foreach ($operations as $operation) {
            $details = $operation->details;
            if ($details->type->isExternalCashFlow()) {
                if (null === $details->linkedTransactionId) {
                    $external[] = $operation->id;
                } elseif (!self::mirrors($operation, $legsById[$details->linkedTransactionId] ?? null)) {
                    $changed[] = $operation->id;
                }
            }
            if (null !== $operation->roundingResidual && !ExactDecimal::isZero($operation->roundingResidual)) {
                $residuals[] = $operation->id;
            }
        }

        $stray = [];
        foreach ($nonTransfer as $fact) {
            if (!$fact->voided) {
                $stray[] = $fact->id;
            }
        }

        $candidates = [
            [CashWarningCode::NEGATIVE_CASH, $ledger->negativeOperationIds, $ledger->firstNegativeDate, null],
            [CashWarningCode::UNLINKED_TRANSFER_IN, $unlinkedIn, null, null],
            [CashWarningCode::UNLINKED_TRANSFER_OUT, $unlinkedOut, null, null],
            [CashWarningCode::UNLINKED_EXTERNAL_FLOW, $external, null, null],
            [CashWarningCode::LINKED_TRANSFER_CHANGED, $changed, null, null],
            [CashWarningCode::NON_TRANSFER_TRANSACTION, $stray, null, null],
            [CashWarningCode::ROUNDING_RESIDUALS, $residuals, null, $ledger->totals->roundingResiduals],
        ];
        $warnings = [];
        foreach ($candidates as [$code, $ids, $firstDate, $amount]) {
            if ([] !== $ids) {
                $warnings[] = CashWarning::of($code, $ids, $firstDate, $amount);
            }
        }

        return $warnings;
    }

    private static function mirrors(PortfolioOperation $operation, ?TransferLegFact $leg): bool
    {
        $details = $operation->details;
        $gross = $details->grossAmount;

        return null !== $leg && null !== $gross
            && !$leg->voided && 'BOOKED' === $leg->state
            && $leg->accountId === $operation->accountId
            && $leg->amount->value->isNegative() === (OperationType::WITHDRAWAL === $details->type)
            && 0 === ExactDecimal::absolute($leg->amount->value)->compareTo($gross)
            && $leg->bookedOn->format('Y-m-d') === $details->tradeDate->format('Y-m-d');
    }
}
