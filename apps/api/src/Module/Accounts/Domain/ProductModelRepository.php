<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface ProductModelRepository
{
    /** Reads one model without locking it, for a query that will not write. */
    public function find(WorkspaceScope $workspace, string $id): ?ProductModel;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?ProductModel;

    /** @return list<ProductModel> */
    public function list(WorkspaceScope $workspace, bool $includeArchived, int $limit, int $offset): array;

    public function count(WorkspaceScope $workspace, bool $includeArchived): int;

    public function hasActiveName(WorkspaceScope $workspace, string $name, ?string $excludingId = null): bool;

    public function add(ProductModel $model): void;

    /** Returns false when the expected version is stale. */
    public function update(ProductModel $model, int $expectedVersion): bool;
}
