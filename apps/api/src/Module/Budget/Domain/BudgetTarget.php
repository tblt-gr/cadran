<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * An exact amount or ratio target for one scope of a plan. A target has no
 * state and no currency of its own: both are read from its plan.
 */
final readonly class BudgetTarget
{
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $planId,
        public BudgetScopeType $scopeType,
        public string $scopeId,
        public BudgetValueType $valueType,
        public ?DecimalValue $amount,
        public ?DecimalValue $ratio,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
        self::assertIdentifier($id, 'A budget target');
        self::assertIdentifier($planId, "A budget target's plan");

        if ('' === $scopeId) {
            throw new InvalidBudgetTarget('A budget target scope must not be empty.');
        }

        if (BudgetValueType::AMOUNT === $valueType) {
            if (null === $amount || null !== $ratio) {
                throw new InvalidBudgetTarget('An amount target carries an amount and no ratio.');
            }
            self::assertPositive($amount, 'A budget target amount');
        } else {
            if (null === $ratio || null !== $amount) {
                throw new InvalidBudgetTarget('A ratio target carries a ratio and no amount.');
            }
            self::assertPositive($ratio, 'A budget target ratio');
        }

        if ($version < 1) {
            throw new InvalidBudgetTarget('A budget target version must be positive.');
        }
    }

    private static function assertPositive(DecimalValue $value, string $subject): void
    {
        // Zero carries no information a missing target does not already carry,
        // and a negative target has no financial meaning: never zero, never
        // negative, matching the "never zero by default" invariant of the
        // rest of the codebase for a computed figure.
        if ($value->compareTo(DecimalValue::zero()) <= 0) {
            throw new InvalidBudgetTarget(sprintf('%s must be strictly positive.', $subject));
        }
    }

    private static function assertIdentifier(string $id, string $subject): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidBudgetTarget(sprintf('%s identifier must be a canonical UUID.', $subject));
        }
    }
}
