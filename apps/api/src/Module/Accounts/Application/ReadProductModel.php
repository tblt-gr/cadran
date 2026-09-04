<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Foundation\Application\CallerWorkspace;

/**
 * Reads one model of the calling workspace, archived or not.
 *
 * An archived model stays readable by identifier on purpose: an account
 * created from it must keep resolving against the description it was created
 * with, which is exactly what archiving is meant to preserve.
 */
final readonly class ReadProductModel
{
    public function __construct(
        private CallerWorkspace $caller,
        private ProductModelRepository $models,
    ) {
    }

    public function __invoke(string $id): ProductModelView
    {
        $model = $this->models->find($this->caller->resolve(), $id);
        if (null === $model) {
            throw new ProductModelNotFound('No model carries this identifier in this workspace.');
        }

        return ProductModelView::fromModel($model);
    }
}
