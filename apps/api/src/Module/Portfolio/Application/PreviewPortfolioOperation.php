<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;

/** Derives create or replacement figures by replaying the candidate without any write. */
final readonly class PreviewPortfolioOperation
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private OperationResolver $resolver,
        private WorkspaceCalendar $calendar,
        private PortfolioOperationRepository $operations,
        private LifeInsurance\ValidateContractHistory $history,
        private ReconcilePortfolioPositions $positions,
    ) {
    }

    public function __invoke(string $accountId, OperationInput $input, ?string $correctingOperationId = null): OperationPreview
    {
        $context = $this->caller->resolveContext();
        $account = $this->gate->forWrite($context->workspace, $accountId, false);
        $original = null;
        if (null !== $correctingOperationId) {
            $original = $this->operations->find($context->workspace, $correctingOperationId) ?? throw new PortfolioOperationNotFound();
            if ($original->accountId !== $account->id) {
                throw new PortfolioOperationNotFound();
            }
            $original->assertActive();
        }
        $resolved = $this->resolver->resolve($account, $input, $this->calendar->today(), false, $original?->id,
            OperationType::FULL_SURRENDER === $original?->details->type,
        );
        $asset = $account->assetCode->toString();
        $amount = static fn (string $value): array => ['value' => $value, 'assetCode' => $asset];

        $expectedGross = $resolved->effect->expectedGrossAmount;
        if ($resolved->details->type->hasSupportLegs() || null !== $original) {
            $existing = $this->operations->activeForAccount($context->workspace, $account->id);
            if (null !== $original) {
                $existing = array_values(array_filter($existing, static fn (PortfolioOperation $operation): bool => $operation->id !== $original->id));
            }
            $sequence = null !== $original && $original->details->tradeDate == $resolved->details->tradeDate
                ? $original->sequence : $this->operations->nextSequence($context->workspace, $account->id, $resolved->details->tradeDate);
            $candidate = new PortfolioOperation(
                '00000000-0000-7000-8000-000000000000', $context->workspace, $account->id, $account->assetCode,
                $resolved->details, $sequence,
                $resolved->effect->cashAmount, $resolved->effect->roundingResidual,
                OperationState::ACTIVE, null, null, null, null, null,
                $this->calendar->today(), $context->actorId, 1,
            );
            $all = [...$existing, $candidate];
            $this->positions->replayOf($context->workspace, $all);
            $historyExpected = $this->history->validate($context->workspace, $all, $candidate->id)['expectedGrossAmount'];
            if (OperationType::FULL_SURRENDER === $resolved->details->type) {
                $expectedGross = $historyExpected;
            }
        }

        return new OperationPreview(
            $amount($resolved->effect->cashAmount->toString()),
            null === $resolved->effect->roundingResidual ? null : $amount($resolved->effect->roundingResidual->toString()),
            null === $expectedGross ? null : $amount($expectedGross->toString()),
            array_map(static fn ($leg): array => OperationLegView::of($leg, $asset), $resolved->details->legs),
            null === $resolved->details->netPaidAmount() ? null : $amount($resolved->details->netPaidAmount()->toString()),
        );
    }
}
