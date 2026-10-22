<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\GroupLineage;
use App\Module\Accounts\Domain\NetWorth;
use App\Module\Accounts\Domain\NetWorthContribution;
use App\Module\Accounts\Domain\NetWorthShares;
use App\Module\Accounts\Domain\ValuationQuality;
use App\Module\Foundation\Domain\DecimalValue;

/**
 * Everything the overview needs to weigh a group or an account on one day,
 * built once from the eligible net worth so that headings and rows can never
 * disagree on the denominator.
 */
final readonly class OverviewFigures
{
    /**
     * @param list<AccountGroup>          $groups        every group, archived ones included
     * @param list<GroupLineage>          $lineages
     * @param array<string, DecimalValue> $rollup        signed exclusive total per group, empty when the aggregate is not calculable
     * @param list<NetWorthContribution>  $contributions
     */
    public function __construct(
        public \DateTimeImmutable $day,
        public NetWorth $netWorth,
        public array $groups,
        public array $lineages,
        public NetWorthShares $shares,
        public array $rollup,
        public ?DecimalValue $ungroupedTotal,
        public array $contributions,
    ) {
    }

    /** The freshness of what a group, or the ungrouped section, is made of. */
    public function qualityOf(?string $groupId): ValuationQuality
    {
        if (null === $this->netWorth->total) {
            return ValuationQuality::MISSING;
        }

        $lineageOf = [];
        foreach ($this->lineages as $lineage) {
            $lineageOf[$lineage->groupId] = $lineage->ancestorIdsIncludingSelf;
        }

        foreach ($this->contributions as $contribution) {
            if (!$contribution->eligible || ValuationQuality::STALE !== $contribution->quality) {
                continue;
            }
            $within = null === $groupId
                ? null === $contribution->primaryGroupId
                : null !== $contribution->primaryGroupId && in_array($groupId, $lineageOf[$contribution->primaryGroupId] ?? [], true);
            if ($within) {
                return ValuationQuality::STALE;
            }
        }

        return ValuationQuality::CURRENT;
    }
}
