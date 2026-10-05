<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Foundation\Domain\WorkspaceScope;

interface FeeTermsRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?FeeTerms;

    /** @return list<FeeTerms> most recent `valid_from` first */
    public function listForContract(WorkspaceScope $workspace, string $contractId): array;

    public function count(WorkspaceScope $workspace, string $contractId): int;

    public function existsFrom(WorkspaceScope $workspace, string $contractId, \DateTimeImmutable $validFrom): bool;

    public function add(FeeTerms $terms): void;

    public function delete(WorkspaceScope $workspace, string $id): bool;
}
