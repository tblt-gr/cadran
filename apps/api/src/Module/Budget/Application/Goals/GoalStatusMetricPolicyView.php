<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalStatusMetricPolicyView
{
    /** @param list<int> $versions */
    public function __construct(public string $state, public ?int $version, public array $versions)
    {
    }
}
