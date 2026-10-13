<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Portfolio\Application\OperationPage;
use App\Module\Portfolio\Application\OperationPreview;
use App\Module\Portfolio\Application\OperationView;
use App\Module\Portfolio\Application\TransferCandidateView;

final class PortfolioOperationRepresentation
{
    /** @return array<string, mixed> */
    public static function one(OperationView $view): array
    {
        return [
            'interestBasis' => $view->interestBasis,
            'rateYear' => $view->rateYear,
            'feeNature' => $view->feeNature,
            'id' => $view->id,
            'accountId' => $view->accountId,
            'type' => $view->type,
            'tradeDate' => $view->tradeDate,
            'settlementDate' => $view->settlementDate,
            'sequence' => $view->sequence,
            'activeDayOperationCount' => $view->activeDayOperationCount,
            'instrument' => $view->instrument,
            'counterInstrument' => $view->counterInstrument,
            'quantity' => $view->quantity,
            'unitPrice' => $view->unitPrice,
            'grossAmount' => $view->grossAmount,
            'fees' => $view->fees,
            'taxes' => $view->taxes,
            'cashAmount' => $view->cashAmount,
            'costBasis' => $view->costBasis,
            'counterQuantity' => $view->counterQuantity,
            'ratio' => $view->ratio,
            'costAllocationRatio' => $view->costAllocationRatio,
            'roundingResidual' => $view->roundingResidual,
            'reference' => $view->reference,
            'note' => $view->note,
            'linkedTransactionId' => $view->linkedTransactionId,
            'state' => $view->state,
            'voidedAt' => $view->voidedAt,
            'voidReason' => $view->voidReason,
            'voidKind' => $view->voidKind,
            'correctsOperationId' => $view->correctsOperationId,
            'correctedByOperationId' => $view->correctedByOperationId,
            'version' => $view->version,
            'legs' => $view->legs,
            'withheldIncomeTax' => $view->withheldIncomeTax,
            'withheldSocialContributions' => $view->withheldSocialContributions,
            'netPaidAmount' => $view->netPaidAmount,
            'applicableSwitchFeeRate' => $view->applicableSwitchFeeRate,
            'applicableSwitchFeeFixed' => $view->applicableSwitchFeeFixed,
        ];
    }

    /** @return array<string, mixed> */
    public static function page(OperationPage $page): array
    {
        return [
            'items' => array_map(self::one(...), $page->items),
            'page' => $page->page,
            'pageSize' => $page->pageSize,
            'total' => $page->total,
        ];
    }

    /** @return array<string, mixed> */
    public static function preview(OperationPreview $preview): array
    {
        return [
            'netCreditedAmount' => $preview->netCreditedAmount,
            'cashAmount' => $preview->cashAmount,
            'roundingResidual' => $preview->roundingResidual,
            'expectedGrossAmount' => $preview->expectedGrossAmount,
            'legs' => $preview->legs,
            'netPaidAmount' => $preview->netPaidAmount,
            'expectedIncomingAmount' => $preview->expectedIncomingAmount,
            'difference' => $preview->difference,
            'applicableSwitchFeeRate' => $preview->applicableSwitchFeeRate,
            'applicableSwitchFeeFixed' => $preview->applicableSwitchFeeFixed,
        ];
    }

    /**
     * @param list<TransferCandidateView> $candidates
     *
     * @return array<string, mixed>
     */
    public static function candidates(array $candidates): array
    {
        return ['items' => array_map(static fn (TransferCandidateView $c): array => [
            'transactionId' => $c->transactionId, 'bookedOn' => $c->bookedOn, 'amount' => $c->amount, 'label' => $c->label,
        ], $candidates)];
    }
}
