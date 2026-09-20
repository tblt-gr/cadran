<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The period, currency and lifecycle state a budget commits to. Every target
 * under this plan inherits these three facts rather than repeating and
 * possibly contradicting them.
 */
final readonly class BudgetPlan
{
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public BudgetPeriod $period,
        public AssetCode $assetCode,
        public BudgetPlanState $state,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
        self::assertIdentifier($id);
        if ($version < 1) {
            throw new InvalidBudgetPlan('A budget plan version must be positive.');
        }
    }

    public function activate(\DateTimeImmutable $updatedAt): self
    {
        if (BudgetPlanState::DRAFT !== $this->state) {
            throw new InvalidBudgetPlan('Only a draft budget plan can be activated.');
        }

        return $this->transitionTo(BudgetPlanState::ACTIVE, $updatedAt);
    }

    public function reconfigure(BudgetPeriod $period, AssetCode $assetCode, \DateTimeImmutable $updatedAt): self
    {
        if (BudgetPlanState::DRAFT !== $this->state) {
            throw new InvalidBudgetPlan('Only a draft budget plan can be edited.');
        }

        return new self(
            id: $this->id,
            workspace: $this->workspace,
            period: $period,
            assetCode: $assetCode,
            state: $this->state,
            version: $this->version + 1,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function close(\DateTimeImmutable $updatedAt): self
    {
        if (BudgetPlanState::ACTIVE !== $this->state) {
            throw new InvalidBudgetPlan('Only an active budget plan can be closed.');
        }

        return $this->transitionTo(BudgetPlanState::CLOSED, $updatedAt);
    }

    private function transitionTo(BudgetPlanState $state, \DateTimeImmutable $updatedAt): self
    {
        return new self(
            id: $this->id,
            workspace: $this->workspace,
            period: $this->period,
            assetCode: $this->assetCode,
            state: $state,
            version: $this->version + 1,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
        );
    }

    private static function assertIdentifier(string $id): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidBudgetPlan('A budget plan identifier must be a canonical UUID.');
        }
    }
}
