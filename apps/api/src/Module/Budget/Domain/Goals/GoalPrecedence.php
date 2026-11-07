<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

/**
 * Among the goals applying to a period with the same periodicity, metric and scope, the latest
 * start wins, then the latest creation; the others are hidden by it. Monthly and yearly goals
 * never hide each other.
 */
final class GoalPrecedence
{
    /**
     * @param list<Goal> $goals  goals of one periodicity
     * @param string     $period `YYYY-MM` for a monthly goal, `YYYY` for a yearly one
     *
     * @return list<array{goal: Goal, overrides: list<string>}> ordered by winner creation, then identifier
     */
    public static function winners(array $goals, string $period): array
    {
        $groups = [];
        foreach ($goals as $goal) {
            if (!self::applies($goal, $period)) {
                continue;
            }
            $groups[implode('|', [$goal->periodicity->value, $goal->metric->value, $goal->scopeType->value, $goal->scopeRef ?? ''])][] = $goal;
        }
        $winners = [];
        foreach ($groups as $group) {
            usort($group, static fn (Goal $a, Goal $b): int => [$b->startPeriod, $b->createdAt, $b->id] <=> [$a->startPeriod, $a->createdAt, $a->id]);
            $winner = array_shift($group);
            $overrides = array_map(static fn (Goal $goal): string => $goal->id, $group);
            sort($overrides, SORT_STRING);
            $winners[] = ['goal' => $winner, 'overrides' => $overrides];
        }
        usort($winners, static fn (array $a, array $b): int => [$a['goal']->createdAt, $a['goal']->id] <=> [$b['goal']->createdAt, $b['goal']->id]);

        return $winners;
    }

    public static function applies(Goal $goal, string $period): bool
    {
        return $goal->startPeriod <= $period && (null === $goal->endPeriod || $period <= $goal->endPeriod);
    }
}
