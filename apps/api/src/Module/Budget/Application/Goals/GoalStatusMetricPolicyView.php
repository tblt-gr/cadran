<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\GoalPolicyState;

final readonly class GoalStatusMetricPolicyView
{
    /** @param list<int> $versions */
    public function __construct(public GoalPolicyState $state, public ?int $version, public array $versions)
    {
    }
}
