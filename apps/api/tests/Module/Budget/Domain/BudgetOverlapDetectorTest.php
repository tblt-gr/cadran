<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Domain;

use App\Module\Budget\Domain\BudgetOverlapDetector;
use App\Module\Budget\Domain\BudgetScopeType;
use App\Module\Budget\Domain\BudgetTarget;
use App\Module\Budget\Domain\BudgetValueType;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\TestCase;

final class BudgetOverlapDetectorTest extends TestCase
{
    private const string WORKSPACE_ID = '11111111-1111-4111-8111-111111111111';
    private const string PLAN_ID = '22222222-2222-4222-8222-222222222222';
    private const string PARENT_CATEGORY_ID = '44444444-4444-4444-8444-444444444444';
    private const string CHILD_CATEGORY_ID = '55555555-5555-4555-8555-555555555555';
    private const string UNRELATED_CATEGORY_ID = '66666666-6666-4666-8666-666666666666';
    private const string TARGET_A_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const string TARGET_B_ID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const string TARGET_C_ID = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    public function testAParentGroupTargetAndAChildCategoryTargetOverlap(): void
    {
        $parent = $this->target(self::TARGET_A_ID, BudgetScopeType::GROUP, self::PARENT_CATEGORY_ID);
        $child = $this->target(self::TARGET_B_ID, BudgetScopeType::CATEGORY, self::CHILD_CATEGORY_ID);

        $overlaps = BudgetOverlapDetector::detect(
            [$parent, $child],
            static fn (string $categoryId): array => self::CHILD_CATEGORY_ID === $categoryId ? [self::PARENT_CATEGORY_ID] : [],
        );

        self::assertTrue($overlaps[self::TARGET_A_ID]);
        self::assertTrue($overlaps[self::TARGET_B_ID]);
    }

    public function testTwoUnrelatedCategoryTargetsDoNotOverlap(): void
    {
        $one = $this->target(self::TARGET_A_ID, BudgetScopeType::CATEGORY, self::PARENT_CATEGORY_ID);
        $two = $this->target(self::TARGET_B_ID, BudgetScopeType::CATEGORY, self::UNRELATED_CATEGORY_ID);

        $overlaps = BudgetOverlapDetector::detect([$one, $two], static fn (string $categoryId): array => []);

        self::assertFalse($overlaps[self::TARGET_A_ID]);
        self::assertFalse($overlaps[self::TARGET_B_ID]);
    }

    public function testTwoTargetsOnTheExactSameCategoryOverlap(): void
    {
        $one = $this->target(self::TARGET_A_ID, BudgetScopeType::CATEGORY, self::PARENT_CATEGORY_ID);
        $two = $this->target(self::TARGET_B_ID, BudgetScopeType::GROUP, self::PARENT_CATEGORY_ID);

        $overlaps = BudgetOverlapDetector::detect([$one, $two], static fn (string $categoryId): array => []);

        self::assertTrue($overlaps[self::TARGET_A_ID]);
        self::assertTrue($overlaps[self::TARGET_B_ID]);
    }

    public function testTwoTargetsOnTheSameAnalyticAxisOverlap(): void
    {
        $one = $this->target(self::TARGET_A_ID, BudgetScopeType::AXIS, 'ESSENTIAL');
        $two = $this->target(self::TARGET_B_ID, BudgetScopeType::AXIS, 'ESSENTIAL');

        $overlaps = BudgetOverlapDetector::detect([$one, $two], static fn (string $categoryId): array => []);

        self::assertTrue($overlaps[self::TARGET_A_ID]);
        self::assertTrue($overlaps[self::TARGET_B_ID]);
    }

    public function testAnAxisTargetNeverOverlapsACategoryTarget(): void
    {
        $axis = $this->target(self::TARGET_A_ID, BudgetScopeType::AXIS, 'ESSENTIAL');
        $category = $this->target(self::TARGET_B_ID, BudgetScopeType::CATEGORY, self::PARENT_CATEGORY_ID);

        $overlaps = BudgetOverlapDetector::detect([$axis, $category], static fn (string $categoryId): array => []);

        self::assertFalse($overlaps[self::TARGET_A_ID]);
        self::assertFalse($overlaps[self::TARGET_B_ID]);
    }

    public function testASingleTargetNeverOverlaps(): void
    {
        $only = $this->target(self::TARGET_A_ID, BudgetScopeType::CATEGORY, self::PARENT_CATEGORY_ID);

        $overlaps = BudgetOverlapDetector::detect([$only], static fn (string $categoryId): array => []);

        self::assertFalse($overlaps[self::TARGET_A_ID]);
    }

    public function testCategoryAncestorsAreResolvedOncePerUniqueScope(): void
    {
        $calls = [];
        $targets = [
            $this->target(self::TARGET_A_ID, BudgetScopeType::GROUP, self::PARENT_CATEGORY_ID),
            $this->target(self::TARGET_B_ID, BudgetScopeType::CATEGORY, self::CHILD_CATEGORY_ID),
            $this->target(self::TARGET_C_ID, BudgetScopeType::CATEGORY, self::CHILD_CATEGORY_ID),
        ];

        $overlaps = BudgetOverlapDetector::detect($targets, static function (string $categoryId) use (&$calls): array {
            $calls[] = $categoryId;

            return self::CHILD_CATEGORY_ID === $categoryId ? [self::PARENT_CATEGORY_ID] : [];
        });

        self::assertCount(2, $calls);
        self::assertEqualsCanonicalizing([self::PARENT_CATEGORY_ID, self::CHILD_CATEGORY_ID], $calls);
        self::assertNotContains(false, $overlaps);
    }

    public function testTheDetectorRefusesAnUnboundedTargetSet(): void
    {
        $targets = [];
        for ($index = 0; $index <= BudgetOverlapDetector::MAX_TARGETS; ++$index) {
            $targets[] = $this->target(
                sprintf('00000000-0000-4000-8000-%012d', $index),
                BudgetScopeType::AXIS,
                'AXIS_'.$index,
            );
        }

        $this->expectException(\LengthException::class);

        BudgetOverlapDetector::detect($targets, static fn (string $categoryId): array => []);
    }

    private function target(string $id, BudgetScopeType $scopeType, string $scopeId): BudgetTarget
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');

        return new BudgetTarget(
            id: $id,
            workspace: WorkspaceScope::fromString(self::WORKSPACE_ID),
            planId: self::PLAN_ID,
            scopeType: $scopeType,
            scopeId: $scopeId,
            valueType: BudgetValueType::AMOUNT,
            amount: DecimalValue::fromString('100.00'),
            ratio: null,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
