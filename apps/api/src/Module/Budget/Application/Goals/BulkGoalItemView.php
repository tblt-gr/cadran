<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class BulkGoalItemView
{
    /**
     * @param array{target: ?string, tolerance: string}                                           $before
     * @param array{target: ?string, tolerance: string}                                           $after
     * @param list<array{versionNo: int, effectiveFrom: string}>                                  $laterRevisions
     * @param list<array{goalId: string, label: string, startPeriod: string, endPeriod: ?string}> $overrides
     * @param array{code: string, field: string}|null                                             $conflict
     */
    public function __construct(
        public string $goalId,
        public string $label,
        public int $expectedVersion,
        public string $valueType,
        public string $assetCode,
        public array $before,
        public array $after,
        public string $firstAffectedMonth,
        public ?string $lastAffectedMonth,
        public array $laterRevisions,
        public array $overrides,
        public bool $changed,
        public ?array $conflict,
    ) {
    }
}
