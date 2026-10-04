<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\PortfolioOperation;
use Brick\Math\BigDecimal;

/** Builds net investor flows: assets acquired are negative, assets received positive. */
final readonly class MoneyWeightedReturnCalculator
{
    public function __construct(private MoneyWeightedReturnSolver $solver)
    {
    }

    /** @param list<PortfolioOperation> $operations
     * @return array{result: MoneyWeightedReturnResult, cashFlows: list<array{date: string, amount: string}>, cashFlowCount: int}
     */
    public function calculate(PeriodAnchor $start, PeriodAnchor $end, array $operations): array
    {
        $startAmount = DecimalValue::fromString((string) BigDecimal::of($start->totalValue->toString())->negated());
        $flows = [['day' => 0, 'amount' => $startAmount]];
        $amounts = [0 => BigDecimal::of($startAmount->toString())];
        $days = (int) $start->date->diff($end->date)->format('%r%a');
        foreach ($operations as $operation) {
            if (ExternalFlow::isUnvalued($operation)) {
                return ['result' => new MoneyWeightedReturnResult(null, null, PerformanceReason::UNVALUED_SECURITY_TRANSFER, null), 'cashFlows' => [], 'cashFlowCount' => 0];
            }
            $amount = ExternalFlow::signedAmount($operation);
            if (null !== $amount) {
                $day = (int) $start->date->diff($operation->details->tradeDate)->format('%r%a');
                $investor = DecimalValue::fromString((string) BigDecimal::of($amount->toString())->negated());
                $flows[] = ['day' => $day, 'amount' => $investor];
                $amounts[$day] = ($amounts[$day] ?? BigDecimal::zero())->plus($investor->toString());
            }
        }
        $flows[] = ['day' => $days, 'amount' => $end->totalValue];
        $amounts[$days] = ($amounts[$days] ?? BigDecimal::zero())->plus($end->totalValue->toString());
        $amounts = array_filter($amounts, static fn (BigDecimal $amount): bool => !$amount->isZero());
        ksort($amounts);
        $cashFlows = [];
        foreach ($amounts as $day => $amount) {
            if (count($cashFlows) < 100) {
                $cashFlows[] = ['date' => $start->date->modify('+'.$day.' days')->format('Y-m-d'), 'amount' => (string) $amount];
            }
        }

        return ['result' => $this->solver->solve($flows, $days), 'cashFlows' => $cashFlows, 'cashFlowCount' => count($amounts)];
    }
}
