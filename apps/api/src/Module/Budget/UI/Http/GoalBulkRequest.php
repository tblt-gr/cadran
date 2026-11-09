<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\BulkGoalInput;
use App\Module\Budget\Application\Goals\GoalInputError;

final class GoalBulkRequest
{
    /** @param array<mixed> $body */
    public static function from(array $body, bool $stop): BulkGoalInput
    {
        if ([] !== array_diff(array_keys($body), ['items']) || !is_array($body['items'] ?? null) || !array_is_list($body['items']) || count($body['items']) > 100) {
            throw new GoalInputError('invalid_goal_batch', 'A bounded selection is required.');
        }
        $items = [];
        foreach ($body['items'] as $item) {
            $allowed = $stop ? ['goalId', 'expectedVersion', 'lastPeriod'] : ['goalId', 'expectedVersion'];
            if (!is_array($item) || [] !== array_diff(array_keys($item), $allowed) || !is_string($item['goalId'] ?? null) || !is_int($item['expectedVersion'] ?? null)
                || ($stop && (!is_string($item['lastPeriod'] ?? null) || strlen($item['lastPeriod']) > 7))) {
                throw new GoalInputError('invalid_goal_batch', 'Each selection needs an identifier, expected version and, when stopping, last period.');
            }
            $items[] = ['goalId' => $item['goalId'], 'expectedVersion' => $item['expectedVersion'], ...($stop ? ['lastPeriod' => $item['lastPeriod']] : [])];
        }

        return new BulkGoalInput($items);
    }
}
