<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\OperationFieldMatrix;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;

final readonly class PortfolioOperationPresenter
{
    public function __construct(private InstrumentRepository $instruments, private PortfolioOperationRepository $operations, private SwitchFeeTerms $switchFeeTerms)
    {
    }

    /**
     * @param list<PortfolioOperation> $operations
     *
     * @return list<OperationView>
     */
    public function present(WorkspaceScope $workspace, array $operations): array
    {
        $instrumentIds = [];
        foreach ($operations as $operation) {
            foreach ([$operation->details->instrumentId, $operation->details->counterInstrumentId, ...array_map(static fn ($leg): string => $leg->instrumentId, $operation->details->legs)] as $id) {
                if (null !== $id) {
                    $instrumentIds[$id] = true;
                }
            }
        }
        $instruments = [];
        $instrumentIds = array_map(strval(...), array_keys($instrumentIds));
        foreach ($this->instruments->findMany($workspace, $instrumentIds) as $instrument) {
            $instruments[$instrument->id] = self::instrument($instrument);
        }
        $replacements = $this->operations->replacementIds($workspace, array_map(static fn (PortfolioOperation $o): string => $o->id, $operations));
        $accountIds = array_values(array_unique(array_map(static fn (PortfolioOperation $o): string => $o->accountId, $operations)));
        $tradeDates = [];
        foreach ($operations as $operation) {
            $tradeDates[$operation->details->tradeDate->format('Y-m-d')] = $operation->details->tradeDate;
        }
        $activeDayCounts = $this->operations->activeCountsForDays($workspace, $accountIds, array_values($tradeDates));

        return array_map(fn (PortfolioOperation $o): OperationView => $this->view(
            $o,
            $instruments,
            $replacements[$o->id] ?? null,
            $activeDayCounts[$o->accountId.'|'.$o->details->tradeDate->format('Y-m-d')] ?? 0,
        ), $operations);
    }

    /** @param array<string, array{id: string, name: string, isin: string|null, ticker: string|null, status: string}> $instruments */
    private function view(PortfolioOperation $operation, array $instruments, ?string $replacementId, int $activeDayOperationCount): OperationView
    {
        $details = $operation->details;
        $asset = $operation->assetCode->toString();
        $amount = static fn (?DecimalValue $value): ?array => null === $value ? null : ['value' => $value->toString(), 'assetCode' => $asset];
        $rules = OperationFieldMatrix::rules($details->type);
        $accepted = [...$rules['required'], ...$rules['optional']];
        $terms = $this->switchFeeTerms->forOperation($operation->workspace, $operation->accountId, $details);
        // Fees and taxes are stored as zero when omitted: they read back as zero wherever the type accepts them.
        $optionalAmount = static fn (string $field, ?DecimalValue $value): ?array => in_array($field, $accepted, true)
            ? ['value' => ($value ?? DecimalValue::zero())->toString(), 'assetCode' => $asset]
            : null;

        return new OperationView(
            $operation->id, $operation->accountId, $details->type->value, $details->tradeDate->format('Y-m-d'),
            $details->settlementDate?->format('Y-m-d'), $operation->sequence, $activeDayOperationCount,
            null === $details->instrumentId ? null : ($instruments[$details->instrumentId] ?? null),
            null === $details->counterInstrumentId ? null : ($instruments[$details->counterInstrumentId] ?? null),
            $details->quantity?->toString(), $details->unitPrice?->toString(), $amount($details->grossAmount),
            $optionalAmount('fees', $details->fees), $details->type->isSurrender() ? $amount($details->taxes) : $optionalAmount('taxes', $details->taxes),
            ['value' => $operation->cashAmount->toString(), 'assetCode' => $asset], $amount($details->costBasis),
            $details->counterQuantity?->toString(),
            null === $details->ratioNumerator || null === $details->ratioDenominator ? null : [
                'numerator' => $details->ratioNumerator->toString(), 'denominator' => $details->ratioDenominator->toString(),
            ],
            $details->costAllocationRatio?->toString(), $amount($operation->roundingResidual),
            $details->reference, $details->note, $details->linkedTransactionId, $operation->state->value,
            $operation->voidedAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            $operation->voidReason, $operation->voidKind?->value, $operation->correctsOperationId, $replacementId, $operation->version,
            array_map(static fn ($leg): array => OperationLegView::of($leg, $asset, $instruments[$leg->instrumentId] ?? null), $details->legs),
            $amount($details->withheldIncomeTax), $amount($details->withheldSocialContributions), $amount($details->netPaidAmount()),
            $terms?->switchFeeRate?->toString(), $amount($terms?->switchFeeFixed),
        );
    }

    /** @return array{id: string, name: string, isin: string|null, ticker: string|null, status: string} */
    private static function instrument(Instrument $instrument): array
    {
        return [
            'id' => $instrument->id, 'name' => $instrument->name, 'isin' => $instrument->isin?->toString(),
            'ticker' => $instrument->ticker?->toString(), 'status' => $instrument->status->value,
        ];
    }
}
