<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * Weighs the parts of one group against the group's own signed roll-up.
 *
 * Only the eligible accounts of the group's subtree are read, so a missing
 * valuation or another unit elsewhere in the workspace never blocks it. The
 * amounts come from {@see ExclusiveGroupRollup}, the same functions the
 * overview uses, so a slice can never disagree with a heading.
 */
final class GroupCompositionCalculator
{
    /**
     * @param list<AccountShareInput> $subtree  eligible accounts whose primary group lies in the subtree, in display order
     * @param list<GroupLineage>      $lineages
     * @param list<string>            $childIds direct sub-groups in display order
     */
    public static function compute(string $groupId, array $subtree, array $lineages, array $childIds): GroupComposition
    {
        $blocked = self::blockingReason($subtree);
        $assets = [];
        foreach ($subtree as $input) {
            if (null !== $input->asset) {
                $assets[$input->asset->toString()] = $input->asset;
            }
        }
        $asset = 1 === count($assets) ? reset($assets) : null;

        $rollup = ExclusiveGroupRollup::totals($subtree, $lineages);
        $total = $rollup[$groupId] ?? DecimalValue::fromString('0');

        $denominator = null;
        $shareReason = $blocked;
        if (null === $blocked) {
            if (ExactDecimal::isZero($total)) {
                $shareReason = NetWorthShareReason::ZERO_GROUP_TOTAL;
            } elseif ($total->isNegative()) {
                $shareReason = NetWorthShareReason::NEGATIVE_GROUP_TOTAL;
            } else {
                $denominator = $total;
            }
        }

        $slice = static fn (?DecimalValue $value): CompositionSlice => new CompositionSlice(
            $value,
            null === $denominator || null === $value
                ? NetWorthShare::none($shareReason ?? NetWorthShareReason::MISSING_VALUATION)
                : self::ratio($value, $denominator),
        );

        $lineageOf = [];
        foreach ($lineages as $lineage) {
            $lineageOf[$lineage->groupId] = $lineage->ancestorIdsIncludingSelf;
        }

        $accounts = [];
        $signedValues = [];
        foreach ($subtree as $input) {
            $signed = null === $input->value ? null : ExactDecimal::signed($input->value, $input->netWorthSign);
            $accounts[$input->accountId] = $slice($signed);
            if (null !== $signed) {
                $signedValues[] = $signed;
            }
        }

        $subgroups = [];
        foreach ($childIds as $childId) {
            $members = array_values(array_filter(
                $subtree,
                static fn (AccountShareInput $input): bool => null !== $input->primaryGroupId
                    && in_array($childId, $lineageOf[$input->primaryGroupId] ?? [], true),
            ));
            if ([] === $members) {
                continue;
            }
            $value = null === $blocked && self::allValued($members) ? ($rollup[$childId] ?? null) : null;
            $subgroups[$childId] = $slice($value);
            if (null !== $value) {
                $signedValues[] = $value;
            }
        }

        $directMembers = array_values(array_filter(
            $subtree,
            static fn (AccountShareInput $input): bool => $input->primaryGroupId === $groupId,
        ));
        $direct = null;
        if ([] !== $directMembers) {
            $directTotals = ExclusiveGroupRollup::directTotals($subtree, $lineages);
            $directValue = null === $blocked && self::allValued($directMembers) ? ($directTotals[$groupId] ?? null) : null;
            $direct = $slice($directValue);
            if (null !== $directValue) {
                $signedValues[] = $directValue;
            }
        }

        return new GroupComposition(
            total: null === $blocked ? $total : null,
            asset: $asset,
            totalReason: $blocked,
            chartReason: self::chartReason($blocked, $shareReason, $signedValues),
            subgroups: $subgroups,
            direct: $direct,
            accounts: $accounts,
        );
    }

    /** @param list<AccountShareInput> $subtree */
    private static function blockingReason(array $subtree): ?NetWorthShareReason
    {
        if (!self::allValued($subtree)) {
            return NetWorthShareReason::MISSING_VALUATION;
        }

        $codes = [];
        foreach ($subtree as $input) {
            if (null !== $input->asset) {
                $codes[$input->asset->toString()] = true;
            }
        }

        return count($codes) > 1 ? NetWorthShareReason::MIXED_ASSETS : null;
    }

    /** @param list<AccountShareInput> $inputs */
    private static function allValued(array $inputs): bool
    {
        foreach ($inputs as $input) {
            if (null === $input->value) {
                return false;
            }
        }

        return true;
    }

    /** @param list<DecimalValue> $signedValues */
    private static function chartReason(?NetWorthShareReason $blocked, ?NetWorthShareReason $shareReason, array $signedValues): ?GroupCompositionChartReason
    {
        $reason = $blocked ?? $shareReason;
        if (null !== $reason) {
            return GroupCompositionChartReason::from($reason->value);
        }

        foreach ($signedValues as $value) {
            if ($value->isNegative()) {
                return GroupCompositionChartReason::NEGATIVE_COMPONENT;
            }
        }

        return null;
    }

    private static function ratio(DecimalValue $numerator, DecimalValue $total): NetWorthShare
    {
        $ratio = ExactDecimal::divide($numerator, $total);

        return NetWorthShare::of($ratio, ExactDecimal::timesHundred($ratio));
    }
}
