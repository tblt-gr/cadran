<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Portfolio\Domain\Cash\CashLedger;
use App\Module\Portfolio\Domain\Cash\CashWarningCode;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Transactions\Application\ListAccountTransactionFacts;
use App\Module\Transactions\Application\ReadTransferLegs;
use App\Module\Transactions\Domain\TransactionNature;

final readonly class ReadPortfolioReconciliation
{
    public function __construct(
        private PortfolioOperationRepository $operations,
        private ListAccountTransactionFacts $facts,
        private ReadTransferLegs $legs,
        private ReconcilePortfolioPositions $positions,
    ) {
    }

    /**
     * @param list<PortfolioOperation> $operations
     *
     * @return list<array{code: string, count: int}>
     */
    public function __invoke(Account $account, \DateTimeImmutable $asOf, array $operations, ?ValuationView $valuation, ?ValuationView $newerPartial = null): array
    {
        if ($asOf < $account->openedOn) {
            return [];
        }
        $scope = $account->workspace;
        $transfers = ($this->facts)($scope, $account->id, $account->openedOn, $asOf, [TransactionNature::TRANSFER]);
        $nonTransfer = ($this->facts)($scope, $account->id, $account->openedOn, $asOf,
            [TransactionNature::INCOME, TransactionNature::EXPENSE, TransactionNature::REFUND, TransactionNature::FEE]);
        $transferIds = array_map(static fn ($fact): string => $fact->id, $transfers);
        $pointedAt = array_values(array_unique(array_filter(array_map(static fn (PortfolioOperation $operation): ?string => $operation->details->linkedTransactionId, $operations))));
        $cashWarnings = CashWarningDetector::detect(CashLedger::replay([], $operations), $operations, $transfers, $nonTransfer,
            $this->operations->linkedAmong($scope, $transferIds), ($this->legs)($scope, $pointedAt));
        $counts = [];
        foreach ($cashWarnings as $warning) {
            if (!in_array($warning->code, [CashWarningCode::UNLINKED_EXTERNAL_FLOW, CashWarningCode::ROUNDING_RESIDUALS], true)) {
                $counts[$warning->code->value] = $warning->count;
            }
        }
        foreach (($this->positions)($scope, $account->id, $asOf)->positions as $position) {
            if (null !== $position->costReason && !$position->quantity->isZero()) {
                $counts[$position->costReason] = ($counts[$position->costReason] ?? 0) + 1;
            }
            if ([] !== $position->orderDependentDates) {
                $counts['ORDER_DEPENDENT_DAY'] = ($counts['ORDER_DEPENDENT_DAY'] ?? 0) + count($position->orderDependentDates);
            }
        }
        $observations = null === $valuation ? [] : [$valuation];
        if (null !== $newerPartial) {
            $observations[] = $newerPartial;
        }
        foreach ($observations as $observation) {
            foreach ($observation->flags as $flag) {
                $counts[$flag->value] = ($counts[$flag->value] ?? 0) + 1;
            }
            if ('PARTIAL' === $observation->valuation->completeness->value) {
                $counts['PARTIAL_VALUATION'] = 1;
            }
            if (PortfolioOverviewQuery::valuation($observation, $asOf)['stale'] ?? false) {
                $counts['STALE_VALUATION'] = ($counts['STALE_VALUATION'] ?? 0) + 1;
            }
        }
        ksort($counts);
        $warnings = [];
        foreach ($counts as $code => $count) {
            $warnings[] = ['code' => $code, 'count' => $count];
        }

        return $warnings;
    }
}
