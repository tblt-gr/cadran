<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Domain;

use App\Module\Budget\Domain\BudgetScopeType;
use App\Module\Budget\Domain\BudgetTarget;
use App\Module\Budget\Domain\BudgetValueType;
use App\Module\Budget\Domain\InvalidBudgetTarget;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\TestCase;

final class BudgetTargetTest extends TestCase
{
    private const string WORKSPACE_ID = '11111111-1111-4111-8111-111111111111';
    private const string TARGET_ID = '33333333-3333-4333-8333-333333333333';
    private const string PLAN_ID = '22222222-2222-4222-8222-222222222222';
    private const string CATEGORY_ID = '44444444-4444-4444-8444-444444444444';

    public function testAnExactAmountTargetOf30000CentsStoresExactlyThatFigure(): void
    {
        // Hand-computed: a 300.00 EUR grocery target is stored as the exact
        // canonical decimal "300.00", never approximated through a float.
        $target = $this->target(valueType: BudgetValueType::AMOUNT, amount: DecimalValue::fromString('300.00'));

        self::assertSame(BudgetValueType::AMOUNT, $target->valueType);
        self::assertSame('300.00', $target->amount?->toString());
        self::assertNull($target->ratio);
    }

    public function testARatioTargetOf30PercentStoresTheDecimalRatioNotAPercentage(): void
    {
        // Hand-computed: "30 %" is written 0.30, consistent with every other
        // rate in this codebase (never 30).
        $target = $this->target(valueType: BudgetValueType::RATIO, ratio: DecimalValue::fromString('0.30'));

        self::assertSame(BudgetValueType::RATIO, $target->valueType);
        self::assertSame('0.30', $target->ratio?->toString());
        self::assertNull($target->amount);
    }

    public function testAVeryLargeDecimalAmountIsAccepted(): void
    {
        $target = $this->target(valueType: BudgetValueType::AMOUNT, amount: DecimalValue::fromString('99999999999999999999999999.999999999999999999999999'));

        self::assertSame('99999999999999999999999999.999999999999999999999999', $target->amount?->toString());
    }

    public function testAZeroAmountTargetIsRefused(): void
    {
        // A target of exactly 0 carries no information a missing target does
        // not already carry, so it is refused rather than stored as a no-op.
        $this->expectException(InvalidBudgetTarget::class);

        $this->target(valueType: BudgetValueType::AMOUNT, amount: DecimalValue::zero());
    }

    public function testANegativeAmountTargetIsRefused(): void
    {
        $this->expectException(InvalidBudgetTarget::class);

        $this->target(valueType: BudgetValueType::AMOUNT, amount: DecimalValue::fromString('-1'));
    }

    public function testAZeroRatioTargetIsRefused(): void
    {
        $this->expectException(InvalidBudgetTarget::class);

        $this->target(valueType: BudgetValueType::RATIO, ratio: DecimalValue::zero());
    }

    public function testANegativeRatioTargetIsRefused(): void
    {
        $this->expectException(InvalidBudgetTarget::class);

        $this->target(valueType: BudgetValueType::RATIO, ratio: DecimalValue::fromString('-0.1'));
    }

    public function testAnAmountTargetWithoutAnAmountIsRefused(): void
    {
        $this->expectException(InvalidBudgetTarget::class);

        $this->target(valueType: BudgetValueType::AMOUNT, amount: null);
    }

    public function testAnAmountTargetCannotAlsoCarryARatio(): void
    {
        $this->expectException(InvalidBudgetTarget::class);

        $this->target(valueType: BudgetValueType::AMOUNT, amount: DecimalValue::fromString('300.00'), ratio: DecimalValue::fromString('0.1'));
    }

    public function testARatioTargetWithoutARatioIsRefused(): void
    {
        $this->expectException(InvalidBudgetTarget::class);

        $this->target(valueType: BudgetValueType::RATIO, ratio: null);
    }

    public function testAnAxisScopedTargetStoresTheAxisNameAsItsScopeId(): void
    {
        $target = $this->target(scopeType: BudgetScopeType::AXIS, scopeId: 'ESSENTIAL');

        self::assertSame(BudgetScopeType::AXIS, $target->scopeType);
        self::assertSame('ESSENTIAL', $target->scopeId);
    }

    /**
     * @param non-empty-string $scopeId
     */
    private function target(
        BudgetScopeType $scopeType = BudgetScopeType::CATEGORY,
        string $scopeId = self::CATEGORY_ID,
        BudgetValueType $valueType = BudgetValueType::AMOUNT,
        DecimalValue|false|null $amount = false,
        DecimalValue|false|null $ratio = false,
    ): BudgetTarget {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
        if (false === $amount) {
            $amount = BudgetValueType::AMOUNT === $valueType ? DecimalValue::fromString('300.00') : null;
        }
        if (false === $ratio) {
            $ratio = BudgetValueType::RATIO === $valueType ? DecimalValue::fromString('0.30') : null;
        }

        return new BudgetTarget(
            id: self::TARGET_ID,
            workspace: WorkspaceScope::fromString(self::WORKSPACE_ID),
            planId: self::PLAN_ID,
            scopeType: $scopeType,
            scopeId: $scopeId,
            valueType: $valueType,
            amount: $amount,
            ratio: $ratio,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
