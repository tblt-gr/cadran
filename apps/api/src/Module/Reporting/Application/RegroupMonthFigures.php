<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\CurrentGroupTree;
use App\Module\Accounts\Application\ReadsCurrentGroupTree;
use App\Module\Accounts\Domain\AccountShareInput;
use App\Module\Accounts\Domain\ExclusiveGroupRollup;
use App\Module\Accounts\Domain\NetWorthShareCalculator;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Rebuilds the group cells and the allocation of a month from the frozen account values and the
 * group tree as it stands today. Closing a month freezes what each account was worth, not which
 * group it belongs to: a group is presentation of the exclusive tree, read at display time.
 *
 * The roll-up and the share rules are the ones of the live net worth, so a regrouped month and an
 * open month never disagree. The frozen account and net worth cells are not touched.
 */
final readonly class RegroupMonthFigures
{
    public function __construct(private ReadsCurrentGroupTree $tree)
    {
    }

    /**
     * Reads the tree of the workspace once and returns the regrouping to apply to each month.
     *
     * @return \Closure(MonthFigures): MonthFigures
     */
    public function forWorkspace(WorkspaceScope $workspace): \Closure
    {
        $tree = ($this->tree)($workspace);

        return static fn (MonthFigures $figures): MonthFigures => self::regroup($figures, $tree);
    }

    private static function regroup(MonthFigures $figures, CurrentGroupTree $tree): MonthFigures
    {
        if (null === $figures->accountWeights) {
            throw new \LogicException('A snapshot without account weights is recaptured before it is regrouped.');
        }

        $inputs = array_map(static fn (AccountWeight $weight): AccountShareInput => new AccountShareInput(
            $weight->accountId,
            $weight->includeInNetWorth,
            1,
            $tree->primaryGroupByAccount[$weight->accountId] ?? null,
            null === $weight->signedValue ? null : DecimalValue::fromString($weight->signedValue),
            null === $weight->assetCode ? null : AssetCode::fromString($weight->assetCode),
        ), $figures->accountWeights);
        $included = array_values(array_filter($inputs, static fn (AccountShareInput $input): bool => $input->includeInNetWorth));

        $reason = self::reason($included);
        $asset = null === $reason ? self::asset($included) : null;
        $totals = null === $reason ? ExclusiveGroupRollup::totals($included, $tree->lineages) : [];
        $directTotals = null === $reason ? ExclusiveGroupRollup::directTotals($included, $tree->lineages) : [];
        $shares = NetWorthShareCalculator::compute($inputs, $tree->lineages);

        $cells = array_filter($figures->cells, static fn (string $id): bool => !str_starts_with($id, 'group:'), ARRAY_FILTER_USE_KEY);
        $allocation = [];
        foreach ($tree->lineages as $lineage) {
            $total = $totals[$lineage->groupId] ?? null;
            $directTotal = $directTotals[$lineage->groupId] ?? null;
            $share = $shares->group($lineage->groupId);
            $directShare = $shares->directGroup($lineage->groupId);
            $cells['group:'.$lineage->groupId] = new MonthlyMetricView(
                $total?->toString(),
                null === $total ? null : $asset,
                null === $total ? $reason : null,
            );
            $allocation[] = new AllocationFigure(
                $lineage->groupId,
                $total?->toString(),
                null === $total ? null : $asset,
                $share->ratio?->toString(),
                $share->reason?->value,
                $lineage->ancestorIdsIncludingSelf[1] ?? null,
                count($lineage->ancestorIdsIncludingSelf),
                $directTotal?->toString(),
                $directShare->ratio?->toString(),
                $directShare->reason?->value,
            );
        }

        return new MonthFigures(
            $figures->month,
            $figures->policyVersion,
            $figures->quality,
            $figures->pendingCount,
            $figures->netWorthAsOf,
            $cells,
            $figures->budgetExpenseCategoryIds,
            $allocation,
            $figures->accountWeights,
        );
    }

    /**
     * Why no group can be totalled, with the vocabulary of the net worth the cells quote.
     *
     * @param list<AccountShareInput> $included
     */
    private static function reason(array $included): ?string
    {
        if ([] === $included) {
            return 'NO_ELIGIBLE_ACCOUNT';
        }
        foreach ($included as $input) {
            if (null === $input->value) {
                return 'MISSING_VALUATION';
            }
        }

        return count(self::assetCodes($included)) > 1 ? 'MIXED_ASSETS' : null;
    }

    /** @param list<AccountShareInput> $included */
    private static function asset(array $included): ?string
    {
        return self::assetCodes($included)[0] ?? null;
    }

    /**
     * @param list<AccountShareInput> $included
     *
     * @return list<string>
     */
    private static function assetCodes(array $included): array
    {
        $codes = [];
        foreach ($included as $input) {
            if (null !== $input->asset) {
                $codes[$input->asset->toString()] = true;
            }
        }

        return array_keys($codes);
    }
}
