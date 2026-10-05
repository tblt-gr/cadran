<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Foundation\Domain\WorkspaceScope;

interface LifeInsuranceContractRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?LifeInsuranceContract;

    /** Holds the contract row until the transaction ends, serialising fee-terms and rate writers. */
    public function findForUpdate(WorkspaceScope $workspace, string $id): ?LifeInsuranceContract;

    public function findByAccount(WorkspaceScope $workspace, string $accountId): ?LifeInsuranceContract;

    public function add(LifeInsuranceContract $contract): void;

    public function update(LifeInsuranceContract $contract, int $expectedVersion): bool;
}
