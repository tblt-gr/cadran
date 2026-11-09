<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class BulkGoalInput
{
    /** @param list<array{goalId: string, expectedVersion: int, lastPeriod?: string}> $items */
    public function __construct(public array $items)
    {
        if ([] === $items || count($items) > 100) {
            throw new GoalInputError('invalid_goal_batch', 'Select between one and 100 goals.');
        }
        $ids = [];
        foreach ($items as $item) {
            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $item['goalId']) || $item['expectedVersion'] < 1 || isset($ids[$item['goalId']])) {
                throw new GoalInputError('invalid_goal_batch', 'Goal identifiers are distinct UUIDs and expected versions are positive.');
            }
            $ids[$item['goalId']] = true;
        }
    }
}
