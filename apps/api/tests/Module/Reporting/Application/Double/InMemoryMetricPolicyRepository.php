<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application\Double;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\MetricPolicy;
use App\Module\Reporting\Domain\MetricPolicyActivation;
use App\Module\Reporting\Domain\MetricPolicyRepository;

final class InMemoryMetricPolicyRepository implements MetricPolicyRepository
{
    /** @var array<string, array<int, MetricPolicy>> */
    private array $policies = [];
    /** @var array<string, list<MetricPolicyActivation>> */
    private array $activations = [];

    public function lock(WorkspaceScope $workspace): void
    {
    }

    public function find(WorkspaceScope $workspace, int $version): ?MetricPolicy
    {
        return MetricPolicy::SYSTEM_VERSION === $version ? MetricPolicy::systemV1() : ($this->policies[$workspace->id][$version] ?? null);
    }

    public function listStored(WorkspaceScope $workspace): array
    {
        $stored = $this->policies[$workspace->id] ?? [];
        ksort($stored);

        return array_values($stored);
    }

    public function countStored(WorkspaceScope $workspace): int
    {
        return count($this->policies[$workspace->id] ?? []);
    }

    public function latestVersion(WorkspaceScope $workspace): int
    {
        return max(MetricPolicy::SYSTEM_VERSION, ...array_keys($this->policies[$workspace->id] ?? []));
    }

    public function add(WorkspaceScope $workspace, string $id, MetricPolicy $policy): void
    {
        $this->policies[$workspace->id][$policy->version] = $policy;
    }

    public function activations(WorkspaceScope $workspace): array
    {
        return $this->activations[$workspace->id] ?? [];
    }

    public function addActivation(WorkspaceScope $workspace, string $id, MetricPolicyActivation $activation): void
    {
        $this->activations[$workspace->id][] = $activation;
    }
}
