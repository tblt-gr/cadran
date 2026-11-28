<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository;

final readonly class AutoCategorizeTransaction
{
    public function __construct(private CategorizationRuleRepository $rules, private CategoryRepository $categories, private CategorizationResolver $resolver, private ElapsedTime $elapsedTime, private CategorizationExecutionBudget $budget, private DeactivateTrippedRules $deactivate, private UuidGenerator $ids)
    {
    }

    /** The caller holds the workspace lock and calls finish() inside the same database transaction. */
    public function begin(WorkspaceScope $workspace, ?string $actorId, \DateTimeImmutable $now): AutoCategorizationBatch
    {
        return new AutoCategorizationBatch(
            $workspace, $actorId, $now, $this->rules, $this->categories, $this->resolver,
            $this->elapsedTime, $this->budget, $this->deactivate, $this->ids,
        );
    }
}
