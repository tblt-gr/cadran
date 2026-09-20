<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

/**
 * Flags parent/child and duplicate scope overlaps between the targets of one
 * plan, computed at read time from the stored rows — never a separate
 * materialized table. An overlap is a warning only: actual totals are read
 * elsewhere and are never double-counted by this detector.
 */
final class BudgetOverlapDetector
{
    public const int MAX_TARGETS = 500;

    /**
     * @param list<BudgetTarget>             $targets     targets of a single plan
     * @param callable(string): list<string> $ancestorsOf resolves a category's ancestor category ids
     *
     * @return array<string, bool> keyed by target id
     */
    public static function detect(array $targets, callable $ancestorsOf): array
    {
        if (count($targets) > self::MAX_TARGETS) {
            throw new \LengthException(sprintf('Overlap detection accepts at most %d targets.', self::MAX_TARGETS));
        }

        /** @var array<string, bool> $overlaps */
        $overlaps = [];
        /** @var array<string, list<string>> $axisTargets */
        $axisTargets = [];
        /** @var array<string, list<string>> $treeTargets */
        $treeTargets = [];
        foreach ($targets as $target) {
            $overlaps[$target->id] = false;
            if (BudgetScopeType::AXIS === $target->scopeType) {
                $axisTargets[$target->scopeId][] = $target->id;
            } else {
                $treeTargets[$target->scopeId][] = $target->id;
            }
        }

        foreach ($axisTargets as $ids) {
            if (count($ids) > 1) {
                self::mark($overlaps, $ids);
            }
        }
        foreach ($treeTargets as $ids) {
            if (count($ids) > 1) {
                self::mark($overlaps, $ids);
            }
        }

        foreach ($treeTargets as $scopeId => $ids) {
            foreach ($ancestorsOf($scopeId) as $ancestorId) {
                if (!isset($treeTargets[$ancestorId])) {
                    continue;
                }
                self::mark($overlaps, $ids);
                self::mark($overlaps, $treeTargets[$ancestorId]);
            }
        }

        return $overlaps;
    }

    /**
     * @param array<string, bool> $overlaps
     * @param list<string>        $ids
     */
    private static function mark(array &$overlaps, array $ids): void
    {
        foreach ($ids as $id) {
            $overlaps[$id] = true;
        }
    }
}
