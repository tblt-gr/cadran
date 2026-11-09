<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\BulkGoalInput;
use App\Module\Budget\Application\Goals\GoalInputError;

final class GoalBulkRequest
{
    /** @param array<mixed> $body */
    public static function from(array $body): BulkGoalInput
    {
        if ([] !== array_diff(array_keys($body), ['items', 'effectiveFrom', 'changes', 'reason']) || !is_array($body['items'] ?? null) || !array_is_list($body['items']) || count($body['items']) > 100 || !is_string($body['effectiveFrom'] ?? null) || !is_array($body['changes'] ?? null)) {
            throw new GoalInputError('invalid_goal_batch', 'A bounded selection, effective month and changes are required.');
        }
        $items = [];
        foreach ($body['items'] as $item) {
            if (!is_array($item) || [] !== array_diff(array_keys($item), ['goalId', 'expectedVersion', 'expectedValueType', 'expectedAssetCode']) || !is_string($item['goalId'] ?? null) || !is_int($item['expectedVersion'] ?? null) || !is_string($item['expectedValueType'] ?? null) || !is_string($item['expectedAssetCode'] ?? null)) {
                throw new GoalInputError('invalid_goal_batch', 'Each selection needs an identifier and expected version.');
            }
            $items[] = ['goalId' => $item['goalId'], 'expectedVersion' => $item['expectedVersion'], 'expectedValueType' => $item['expectedValueType'], 'expectedAssetCode' => $item['expectedAssetCode']];
        }
        $changes = $body['changes'];
        if ([] !== array_diff(array_keys($changes), ['target', 'tolerance'])) {
            throw new GoalInputError('invalid_goal_batch', 'Only target and tolerance can change.');
        }
        foreach ($changes as $value) {
            if (!is_string($value) || strlen($value) > 80) {
                throw new GoalInputError('invalid_goal_batch', 'Changed values are bounded decimal strings.');
            }
        }
        $reason = $body['reason'] ?? null;
        if (null !== $reason && (!is_string($reason) || mb_strlen($reason) > 200)) {
            throw new GoalInputError('invalid_goal_batch', 'A correction reason is a bounded string.');
        }

        return new BulkGoalInput($items, $body['effectiveFrom'], $changes['target'] ?? null, $changes['tolerance'] ?? null, $reason);
    }
}
