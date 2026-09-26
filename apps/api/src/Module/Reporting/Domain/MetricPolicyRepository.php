<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Append-only store of workspace metric policies and their activations: it
 * offers no update and no delete, so a version and the history stay immutable.
 */
interface MetricPolicyRepository
{
    public const int MAX_VERSIONS = 100;
    public const int MAX_ACTIVATIONS = 5000;

    /** Serializes every policy write of one workspace until the surrounding transaction ends. */
    public function lock(WorkspaceScope $workspace): void;

    /** The stored version, or the built-in one for version 1; null when it cannot be loaded. */
    public function find(WorkspaceScope $workspace, int $version): ?MetricPolicy;

    /** @return list<MetricPolicy> decodable stored versions, ascending; an undecodable row is skipped, never replaced */
    public function listStored(WorkspaceScope $workspace): array;

    /** Number of stored rows, decodable or not. */
    public function countStored(WorkspaceScope $workspace): int;

    /** Highest stored version number, decodable or not; 1 when only the built-in version exists. */
    public function latestVersion(WorkspaceScope $workspace): int;

    public function add(WorkspaceScope $workspace, string $id, MetricPolicy $policy): void;

    /** @return list<MetricPolicyActivation> ascending by activation instant */
    public function activations(WorkspaceScope $workspace): array;

    public function addActivation(WorkspaceScope $workspace, string $id, MetricPolicyActivation $activation): void;
}
