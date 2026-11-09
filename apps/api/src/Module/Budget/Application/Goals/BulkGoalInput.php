<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Foundation\Domain\AssetCode;

final readonly class BulkGoalInput
{
    /** @param list<array{goalId: string, expectedVersion: int, expectedValueType: string, expectedAssetCode: string}> $items */
    public function __construct(
        public array $items,
        public string $effectiveFrom,
        public ?string $target,
        public ?string $tolerance,
        public ?string $reason = null,
    ) {
        if ([] === $items || count($items) > 100 || (null === $target && null === $tolerance)) {
            throw new GoalInputError('invalid_goal_batch', 'Select between one and 100 goals and at least one field.');
        }
        $ids = [];
        foreach ($items as $item) {
            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $item['goalId']) || $item['expectedVersion'] < 1 || isset($ids[$item['goalId']])) {
                throw new GoalInputError('invalid_goal_batch', 'Goal identifiers are distinct UUIDs and expected versions are positive.');
            }
            if (null === GoalValueType::tryFrom($item['expectedValueType'])) {
                throw new GoalInputError('invalid_goal_batch', 'An expected target value type is required.');
            }
            try {
                AssetCode::fromString($item['expectedAssetCode']);
            } catch (\InvalidArgumentException) {
                throw new GoalInputError('invalid_goal_batch', 'An expected target asset is required.');
            }
            $ids[$item['goalId']] = true;
        }
        try {
            CalendarMonth::fromString($effectiveFrom);
        } catch (\DomainException|\InvalidArgumentException) {
            throw new GoalInputError('invalid_effective_month', 'An effective month is written YYYY-MM.');
        }
    }
}
