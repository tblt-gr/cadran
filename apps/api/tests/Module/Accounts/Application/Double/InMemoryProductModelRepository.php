<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Accounts\Domain\ProductModel;
use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * A model store for the use cases whose behaviour is not about SQL. It
 * filters on the workspace exactly like the database does, so a use case that
 * forgot to pass a scope fails here too rather than only in integration.
 */
final class InMemoryProductModelRepository implements ProductModelRepository
{
    /** @var list<ProductModel> */
    private array $models;

    public function __construct(ProductModel ...$models)
    {
        $this->models = array_values($models);
    }

    public function find(WorkspaceScope $workspace, string $id): ?ProductModel
    {
        foreach ($this->models as $model) {
            if ($model->id === $id && $model->workspace->equals($workspace)) {
                return $model;
            }
        }

        return null;
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?ProductModel
    {
        return $this->find($workspace, $id);
    }

    public function list(WorkspaceScope $workspace, bool $includeArchived, int $limit, int $offset): array
    {
        return array_slice($this->matching($workspace, $includeArchived), $offset, $limit);
    }

    public function count(WorkspaceScope $workspace, bool $includeArchived): int
    {
        return count($this->matching($workspace, $includeArchived));
    }

    public function hasActiveName(WorkspaceScope $workspace, string $name, ?string $excludingId = null): bool
    {
        $normalized = mb_strtolower(trim($name));

        foreach ($this->models as $model) {
            if (!$model->workspace->equals($workspace) || $model->isArchived()) {
                continue;
            }
            if ($model->id !== $excludingId && mb_strtolower(trim($model->name)) === $normalized) {
                return true;
            }
        }

        return false;
    }

    public function add(ProductModel $model): void
    {
        $this->models[] = $model;
    }

    public function update(ProductModel $model, int $expectedVersion): bool
    {
        foreach ($this->models as $position => $stored) {
            if ($stored->id !== $model->id || !$stored->workspace->equals($model->workspace)) {
                continue;
            }
            if ($stored->version !== $expectedVersion) {
                return false;
            }

            $this->models[$position] = $model;

            return true;
        }

        return false;
    }

    /**
     * @return list<ProductModel>
     */
    private function matching(WorkspaceScope $workspace, bool $includeArchived): array
    {
        return array_values(array_filter(
            $this->models,
            static fn (ProductModel $model): bool => $model->workspace->equals($workspace)
                && ($includeArchived || !$model->isArchived()),
        ));
    }
}
