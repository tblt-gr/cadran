<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\ReconcilePortfolioPositions;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;

final readonly class ValidateContractHistory
{
    public function __construct(private ReconcilePortfolioPositions $positions)
    {
    }

    /** @param list<PortfolioOperation> $operations
     * @return array{closedOn: ?\DateTimeImmutable, expectedGrossAmount: ?DecimalValue}
     */
    public function validate(WorkspaceScope $workspace, array $operations, ?string $previewCandidateId = null): array
    {
        try {
            return $this->replay($workspace, $operations, $previewCandidateId);
        } catch (\App\Module\Foundation\Domain\PrecisionExceeded|\App\Module\Foundation\Domain\MalformedDecimal) {
            throw new InvalidPortfolioOperation('The cash history is too large to store exactly.', 'cash_amount_too_large');
        }
    }

    /** @param list<PortfolioOperation> $operations
     * @return array{closedOn: ?\DateTimeImmutable, expectedGrossAmount: ?DecimalValue}
     */
    private function replay(WorkspaceScope $workspace, array $operations, ?string $previewCandidateId): array
    {
        usort($operations, static fn (PortfolioOperation $a, PortfolioOperation $b): int => [$a->details->tradeDate, $a->sequence, $a->id] <=> [$b->details->tradeDate, $b->sequence, $b->id]);
        foreach ($operations as $offset => $operation) {
            if (OperationType::FULL_SURRENDER === $operation->details->type && $offset !== count($operations) - 1) {
                throw new InvalidPortfolioOperation('An active operation follows the full surrender.', 'operations_after_full_surrender');
            }
        }
        $cash = DecimalValue::zero();
        $closedOn = null;
        $expectedGross = null;
        $prefix = [];
        foreach ($operations as $operation) {
            $details = $operation->details;
            // Only the candidate may be unbalanced to expose its expected gross.
            $previewFullSurrender = $operation->id === $previewCandidateId && OperationType::FULL_SURRENDER === $details->type;
            if (null !== $closedOn) {
                throw new InvalidPortfolioOperation('An active operation follows the full surrender.', 'operations_after_full_surrender');
            }
            $prefix[] = $operation;
            $before = $cash;
            $cash = ExactDecimal::add($cash, $operation->cashAmount);
            if ($details->type->isSurrender() && $cash->isNegative() && !$previewFullSurrender) {
                throw new InvalidPortfolioOperation('Surrender exceeds available cash and sold supports.', 'surrender_exceeds_available');
            }
            if (OperationType::FULL_SURRENDER !== $details->type) {
                continue;
            }
            $positions = $this->positions->replayOf($workspace, $prefix);
            $remaining = [];
            foreach ($positions->positions as $position) {
                if (!$position->quantity->isZero()) {
                    $remaining[] = $position->instrumentId;
                }
            }
            if ([] !== $remaining) {
                throw new InvalidPortfolioOperation('Full surrender leaves open supports.', 'full_surrender_positions_remaining', ['instrumentIds' => $remaining]);
            }
            $expectedGross = ExactDecimal::add($before, $details->supportsAmount());
            if (!$previewFullSurrender && !ExactDecimal::isZero($cash)) {
                throw new InvalidPortfolioOperation('Full surrender must exhaust cash.', 'full_surrender_not_balanced', ['expectedGrossAmount' => ExactDecimal::add($before, $details->supportsAmount())->toString()]);
            }
            $closedOn = $details->tradeDate;
        }

        return ['closedOn' => $closedOn, 'expectedGrossAmount' => $expectedGross];
    }
}
