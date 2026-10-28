<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * Rolls the signed value of every included account into its primary group and
 * each ancestor of that group, once.
 *
 * Membership is exclusive: one account follows a single lineage, so the same
 * euro never counts twice in groups of one depth. Shares and
 * allocation both read from here so the two can never disagree on what a
 * group is worth.
 */
final class ExclusiveGroupRollup
{
    /**
     * @param list<AccountShareInput> $included
     * @param list<GroupLineage>      $lineages
     *
     * @return array<string, DecimalValue> one signed total per lineage, keyed by group
     */
    public static function totals(array $included, array $lineages): array
    {
        $lineageByGroup = [];
        foreach ($lineages as $lineage) {
            $lineageByGroup[$lineage->groupId] = $lineage;
        }

        $totals = [];
        foreach ($lineages as $lineage) {
            $totals[$lineage->groupId] = DecimalValue::fromString('0');
        }

        foreach ($included as $account) {
            if (null === $account->value || null === $account->primaryGroupId) {
                continue;
            }

            $primary = $lineageByGroup[$account->primaryGroupId] ?? null;
            if (null === $primary) {
                continue;
            }

            $signed = ExactDecimal::signed($account->value, $account->netWorthSign);
            foreach ($primary->ancestorIdsIncludingSelf as $ancestorId) {
                if (isset($totals[$ancestorId])) {
                    $totals[$ancestorId] = ExactDecimal::add($totals[$ancestorId], $signed);
                }
            }
        }

        return $totals;
    }

    /**
     * The signed value of included accounts that sit in no group: the bucket
     * beside the tree, so that tree roots and this bucket partition the
     * eligible net worth.
     *
     * @param list<AccountShareInput> $included
     */
    public static function ungroupedTotal(array $included): DecimalValue
    {
        $total = DecimalValue::fromString('0');
        foreach ($included as $account) {
            if (null === $account->value || null !== $account->primaryGroupId || !$account->includeInNetWorth) {
                continue;
            }

            $total = ExactDecimal::add($total, ExactDecimal::signed($account->value, $account->netWorthSign));
        }

        return $total;
    }
}
