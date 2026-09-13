<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * A prioritised, dated rule that assigns a category, analytic axes and
 * possibly an empty counterparty. It never edits anything else about a
 * movement. An archived rule is read-only and never takes part in resolution.
 */
final readonly class CategorizationRule
{
    public const int MIN_PRIORITY = 1;
    public const int MAX_PRIORITY = 999;
    public const int MAX_LABEL_LENGTH = 80;
    public const int MAX_COUNTERPARTY_LENGTH = 80;
    public const int MAX_SCOPE = 20;

    /**
     * @param list<string>       $accountScope
     * @param list<AnalyticAxis> $targetAxes
     */
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $label,
        public int $priority,
        public array $accountScope,
        public RuleConditions $conditions,
        public string $targetCategoryId,
        public array $targetAxes,
        public ?string $targetCounterparty,
        public \DateTimeImmutable $effectiveFrom,
        public ?\DateTimeImmutable $effectiveTo,
        public bool $active,
        public ?RuleDeactivationReason $deactivatedReason,
        public int $appliedCount,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $archivedAt,
    ) {
        self::identifier($id);
        self::identifier($targetCategoryId);
        self::text($label, self::MAX_LABEL_LENGTH, 'label');
        if (null !== $targetCounterparty) {
            self::text($targetCounterparty, self::MAX_COUNTERPARTY_LENGTH, 'target counterparty');
        }
        if ($priority < self::MIN_PRIORITY || $priority > self::MAX_PRIORITY) {
            throw new InvalidCategorizationRule('A rule priority must be between 1 and 999.');
        }
        if (count($accountScope) > self::MAX_SCOPE || count($accountScope) !== count(array_unique($accountScope))) {
            throw new InvalidCategorizationRule('A rule scope names at most twenty distinct accounts.');
        }
        array_walk($accountScope, static fn (string $accountId) => self::identifier($accountId));
        $axes = array_map(static fn (AnalyticAxis $axis): string => $axis->value, $targetAxes);
        if (count($axes) !== count(array_unique($axes))) {
            throw new InvalidCategorizationRule('A rule target axis cannot be selected twice.');
        }
        if (null !== $effectiveTo && $effectiveTo->format('Y-m-d') < $effectiveFrom->format('Y-m-d')) {
            throw new InvalidCategorizationRule('A rule effective period cannot end before it starts.');
        }
        if ($active === (null !== $deactivatedReason) || ($active && null !== $archivedAt)) {
            throw new InvalidCategorizationRule('Only an inactive rule carries a deactivation reason, and an archived rule is inactive.');
        }
        if ($appliedCount < 0 || $version < 1 || $updatedAt < $createdAt) {
            throw new InvalidCategorizationRule('A rule counter, version and timestamps must be ordered.');
        }
    }

    /** Whether the rule takes part in resolution for this movement: live, in period and in scope. */
    public function appliesTo(CategorizationSubject $subject): bool
    {
        $day = $subject->bookedOn->format('Y-m-d');

        return $this->active && null === $this->archivedAt
            && $day >= $this->effectiveFrom->format('Y-m-d')
            && (null === $this->effectiveTo || $day <= $this->effectiveTo->format('Y-m-d'))
            && ([] === $this->accountScope || in_array($subject->accountId, $this->accountScope, true));
    }

    /**
     * @param list<string>       $accountScope
     * @param list<AnalyticAxis> $targetAxes
     */
    public function revise(
        string $label,
        int $priority,
        array $accountScope,
        RuleConditions $conditions,
        string $targetCategoryId,
        array $targetAxes,
        ?string $targetCounterparty,
        \DateTimeImmutable $effectiveFrom,
        ?\DateTimeImmutable $effectiveTo,
        bool $active,
        \DateTimeImmutable $updatedAt,
    ): self {
        if (null !== $this->archivedAt) {
            throw new InvalidCategorizationRule('An archived rule cannot be edited.');
        }
        $reason = match (true) {
            $active => null,
            $this->active => RuleDeactivationReason::USER,
            default => $this->deactivatedReason,
        };

        return new self(
            $this->id, $this->workspace, $label, $priority, $accountScope, $conditions, $targetCategoryId,
            $targetAxes, $targetCounterparty, $effectiveFrom, $effectiveTo, $active, $reason, $this->appliedCount,
            $this->version + 1, $this->createdAt, $updatedAt, null,
        );
    }

    /** A protective or cascading deactivation; an already inactive rule keeps its first reason. */
    public function deactivate(RuleDeactivationReason $reason, \DateTimeImmutable $updatedAt): self
    {
        if (!$this->active) {
            return $this;
        }

        return $this->with(false, $reason, $updatedAt, $this->archivedAt);
    }

    public function archive(\DateTimeImmutable $archivedAt): self
    {
        if (null !== $this->archivedAt) {
            throw new InvalidCategorizationRule('A rule can only be archived once.');
        }

        return $this->with(false, $this->deactivatedReason ?? RuleDeactivationReason::USER, $archivedAt, $archivedAt);
    }

    private function with(bool $active, ?RuleDeactivationReason $reason, \DateTimeImmutable $updatedAt, ?\DateTimeImmutable $archivedAt): self
    {
        return new self(
            $this->id, $this->workspace, $this->label, $this->priority, $this->accountScope, $this->conditions,
            $this->targetCategoryId, $this->targetAxes, $this->targetCounterparty, $this->effectiveFrom,
            $this->effectiveTo, $active, $reason, $this->appliedCount, $this->version + 1, $this->createdAt,
            $updatedAt, $archivedAt,
        );
    }

    private static function identifier(string $id): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidCategorizationRule('A rule identifier must be a canonical UUID.');
        }
    }

    private static function text(string $value, int $maximum, string $field): void
    {
        if ($value !== trim($value) || '' === $value || mb_strlen($value) > $maximum) {
            throw new InvalidCategorizationRule(sprintf('A rule %s must contain between 1 and %d characters.', $field, $maximum));
        }
    }
}
