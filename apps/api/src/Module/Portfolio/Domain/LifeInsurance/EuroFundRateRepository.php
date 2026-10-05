<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Foundation\Domain\WorkspaceScope;

interface EuroFundRateRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?EuroFundRate;

    /** @return list<EuroFundRate> most recent year first */
    public function listForContract(WorkspaceScope $workspace, string $contractId): array;

    public function count(WorkspaceScope $workspace, string $contractId): int;

    public function exists(WorkspaceScope $workspace, string $contractId, string $instrumentId, int $year): bool;

    public function add(EuroFundRate $rate): void;

    public function delete(WorkspaceScope $workspace, string $id): bool;
}
