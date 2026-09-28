<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class Goal
{
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $label,
        public GoalKind $kind,
        public GoalMetric $metric,
        public GoalScopeType $scopeType,
        public ?string $scopeRef,
        public AssetCode $assetCode,
        public GoalState $state,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public string $createdBy,
        public ?\DateTimeImmutable $closedAt = null,
    ) {
        self::identifier($id);
        self::identifier($createdBy);
        if ($label !== trim($label) || mb_strlen($label) < 1 || mb_strlen($label) > 80) {
            throw new InvalidGoal('A goal label contains between 1 and 80 trimmed characters.');
        }
        if (!$metric->accepts($kind, $scopeType)) {
            throw new InvalidGoal('The goal metric, kind and scope do not match.');
        }
        if ((GoalScopeType::WORKSPACE === $scopeType) !== (null === $scopeRef)) {
            throw new InvalidGoal('Only a workspace goal omits its scope reference.');
        }
        if (null !== $scopeRef && ('' === $scopeRef || strlen($scopeRef) > 64)) {
            throw new InvalidGoal('A goal scope reference contains between 1 and 64 characters.');
        }
        if ($version < 1) {
            throw new InvalidGoal('A goal version must be positive.');
        }
        if ((GoalState::CLOSED === $state) !== (null !== $closedAt)) {
            throw new InvalidGoal('A closed goal carries its closing instant.');
        }
    }

    public function direction(): GoalDirection
    {
        return $this->metric->direction();
    }

    public function reviseLabel(string $label): self
    {
        return $this->with($label, $this->state, $this->closedAt);
    }

    public function reviseDefinition(): self
    {
        return $this->with($this->label, $this->state, $this->closedAt);
    }

    public function close(\DateTimeImmutable $at): self
    {
        if (GoalState::CLOSED === $this->state) {
            throw new InvalidGoal('A closed goal cannot be closed again.');
        }

        return $this->with($this->label, GoalState::CLOSED, $at);
    }

    private function with(string $label, GoalState $state, ?\DateTimeImmutable $closedAt): self
    {
        return new self($this->id, $this->workspace, $label, $this->kind, $this->metric, $this->scopeType, $this->scopeRef, $this->assetCode, $state, $this->version + 1, $this->createdAt, $this->createdBy, $closedAt);
    }

    private static function identifier(string $value): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            throw new InvalidGoal('A goal identifier must be a canonical UUID.');
        }
    }
}
