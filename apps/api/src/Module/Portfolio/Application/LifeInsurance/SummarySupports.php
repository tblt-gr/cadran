<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Application\PortfolioUnrealizedGain;
use App\Module\Portfolio\Application\ValuationView;
use App\Module\Portfolio\Domain\InstrumentType;
use App\Module\Portfolio\Domain\LifeInsurance\ContractAllocation;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRate;
use App\Module\Portfolio\Domain\PortfolioOperation;

final class SummarySupports
{
    /** @param list<EuroFundRate> $rates
     * @param list<PortfolioOperation> $operations
     *
     * @return array{euroFundValue: string, unitLinkedValue: string, supports: list<array<string, mixed>>, missingRates: int}
     */
    public static function of(ValuationView $view, array $rates, array $operations, \DateTimeImmutable $asOf): array
    {
        $euro = $units = '0';
        $rows = [];
        $missing = 0;
        $lastYear = (int) $asOf->format('Y') - 1;
        foreach ($view->valuation->positions as $line) {
            if (ExactDecimal::isZero($line->quantity)) {
                continue;
            }
            $instrument = $view->instruments[$line->instrumentId] ?? throw new \LogicException('A valuation support must exist.');
            $isEuro = InstrumentType::EURO_FUND === $instrument->instrumentType;
            $value = $line->marketValue?->toString();
            if (null !== $value) {
                if ($isEuro) {
                    $euro = ExactDecimal::addForResponse($euro, $value);
                } else {
                    $units = ExactDecimal::addForResponse($units, $value);
                }
            }
            $latest = null;
            foreach ($rates as $rate) {
                if ($rate->instrumentId === $instrument->id && $rate->year <= (int) $asOf->format('Y') && (null === $latest || $rate->year > $latest->year)) {
                    $latest = $rate;
                }
            }
            $credited = '0';
            foreach ($operations as $operation) {
                $details = $operation->details;
                if ($details->instrumentId === $instrument->id && $details->type->isCreditedInterest() && $details->rateYear === $lastYear) {
                    $credited = ExactDecimal::addForResponse($credited, $details->netCreditedAmount()?->toString() ?? '0');
                }
            }
            $cost = $line->averageCostBasis?->toString();
            $gain = !$isEuro && null !== $value && null !== $cost ? ExactDecimal::subtractForResponse($value, $cost) : null;
            $missingLastYear = $isEuro && (null === $latest || $latest->year < $lastYear);
            $missing += $missingLastYear ? 1 : 0;
            $rows[] = ['instrumentId' => $instrument->id, 'name' => $instrument->name, 'type' => $isEuro ? 'EURO_FUND' : 'UNIT_LINKED',
                'quantity' => $line->quantity->toString(), 'unitPrice' => $line->unitPrice?->toString(),
                'priceDate' => $line->priceDate?->format('Y-m-d'), 'priceQuality' => $line->priceQuality->value,
                'marketValue' => $value, 'weight' => null === $value ? null : ContractAllocation::share($value, $view->valuation->totalValue?->toString() ?? '0'),
                'costBasis' => $cost, 'unrealizedGain' => $gain, 'unrealizedPerformance' => $isEuro ? null : PortfolioUnrealizedGain::performance($gain, $cost),
                'latestServedRate' => !$isEuro || null === $latest ? null : ['year' => $latest->year, 'rate' => $latest->servedRate->toString(), 'bonusRate' => $latest->bonusRate?->toString(), 'basis' => $latest->basis->value],
                'creditedLastClosedYear' => $isEuro ? $credited : null, 'lastClosedYear' => $lastYear, 'missingLastYear' => $missingLastYear];
        }

        return ['euroFundValue' => $euro, 'unitLinkedValue' => $units, 'supports' => $rows, 'missingRates' => $missing];
    }
}
