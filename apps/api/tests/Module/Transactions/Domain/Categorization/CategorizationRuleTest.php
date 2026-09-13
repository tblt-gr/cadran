<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Categorization;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\Categorization\CategorizationRule;
use App\Module\Transactions\Domain\Categorization\CategorizationSubject;
use App\Module\Transactions\Domain\Categorization\InvalidCategorizationRule;
use App\Module\Transactions\Domain\Categorization\RuleConditions;
use App\Module\Transactions\Domain\Categorization\RuleDeactivationReason;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategorizationRuleTest extends TestCase
{
    private const string RULE = '00000000-0000-7000-8000-0000000000e1';
    private const string CATEGORY = '00000000-0000-7000-8000-0000000000c1';
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRules(): iterable
    {
        yield 'priority zero' => [['priority' => 0]];
        yield 'priority above 999' => [['priority' => 1000]];
        yield 'blank label' => [['label' => '']];
        yield 'untrimmed label' => [['label' => ' Courses']];
        yield 'label beyond 80 characters' => [['label' => str_repeat('a', 81)]];
        yield 'scope beyond twenty accounts' => [['accountScope' => array_map(
            static fn (int $i): string => sprintf('00000000-0000-7000-8000-%012d', $i),
            range(1, 21),
        )]];
        yield 'duplicated scope account' => [['accountScope' => [self::ACCOUNT, self::ACCOUNT]]];
        yield 'malformed scope account' => [['accountScope' => ['not-a-uuid']]];
        yield 'duplicated axis' => [['targetAxes' => [AnalyticAxis::FIXED, AnalyticAxis::FIXED]]];
        yield 'blank counterparty' => [['targetCounterparty' => '']];
        yield 'counterparty beyond 80 characters' => [['targetCounterparty' => str_repeat('a', 81)]];
        yield 'period ending before it starts' => [['effectiveTo' => new \DateTimeImmutable('2025-12-31')]];
        yield 'active rule carrying a deactivation reason' => [['deactivatedReason' => RuleDeactivationReason::USER]];
        yield 'inactive rule without a reason' => [['active' => false]];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidRules')]
    public function testAnInvalidRuleIsRefused(array $overrides): void
    {
        $this->expectException(InvalidCategorizationRule::class);
        $this->rule($overrides);
    }

    public function testTheEffectivePeriodIsInclusiveOnBothEnds(): void
    {
        $rule = $this->rule(['effectiveTo' => new \DateTimeImmutable('2026-03-31')]);

        self::assertFalse($rule->appliesTo($this->subject('2025-12-31')));
        self::assertTrue($rule->appliesTo($this->subject('2026-01-01')));
        self::assertTrue($rule->appliesTo($this->subject('2026-03-31')));
        self::assertFalse($rule->appliesTo($this->subject('2026-04-01')));
    }

    public function testAnOpenEndedPeriodHasNoUpperBound(): void
    {
        self::assertTrue($this->rule()->appliesTo($this->subject('2099-12-31')));
    }

    public function testTheAccountScopeRestrictsAndAnEmptyScopeCoversEveryAccount(): void
    {
        $scoped = $this->rule(['accountScope' => [self::OTHER_ACCOUNT]]);

        self::assertFalse($scoped->appliesTo($this->subject('2026-02-01')));
        self::assertTrue($scoped->appliesTo($this->subject('2026-02-01', self::OTHER_ACCOUNT)));
        self::assertTrue($this->rule()->appliesTo($this->subject('2026-02-01')));
    }

    public function testAnInactiveOrArchivedRuleNeverApplies(): void
    {
        $now = new \DateTimeImmutable('2026-03-15T10:00:00+00:00');

        self::assertFalse($this->rule()->deactivate(RuleDeactivationReason::USER, $now)->appliesTo($this->subject('2026-02-01')));
        self::assertFalse($this->rule()->archive($now)->appliesTo($this->subject('2026-02-01')));
    }

    public function testDeactivatingForABudgetRecordsTheReasonAndBumpsTheVersion(): void
    {
        $now = new \DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $deactivated = $this->rule()->deactivate(RuleDeactivationReason::PATTERN_BUDGET_EXCEEDED, $now);

        self::assertFalse($deactivated->active);
        self::assertSame(RuleDeactivationReason::PATTERN_BUDGET_EXCEEDED, $deactivated->deactivatedReason);
        self::assertSame(2, $deactivated->version);
        self::assertEquals($now, $deactivated->updatedAt);
    }

    public function testReactivatingClearsTheReasonAndDeactivatingByEditUsesUser(): void
    {
        $now = new \DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $rule = $this->rule()->deactivate(RuleDeactivationReason::CATEGORY_ARCHIVED, $now);

        $reactivated = $this->revise($rule, active: true, now: $now);
        self::assertTrue($reactivated->active);
        self::assertNull($reactivated->deactivatedReason);

        $userDeactivated = $this->revise($reactivated, active: false, now: $now);
        self::assertSame(RuleDeactivationReason::USER, $userDeactivated->deactivatedReason);

        $stillInactive = $this->revise($rule, active: false, now: $now);
        self::assertSame(RuleDeactivationReason::CATEGORY_ARCHIVED, $stillInactive->deactivatedReason);
    }

    public function testAnArchivedRuleIsReadOnly(): void
    {
        $now = new \DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $archived = $this->rule()->archive($now);

        self::assertFalse($archived->active);
        self::assertNotNull($archived->archivedAt);
        $this->expectException(InvalidCategorizationRule::class);
        $this->revise($archived, active: true, now: $now);
    }

    public function testArchivingTwiceIsRefused(): void
    {
        $now = new \DateTimeImmutable('2026-03-15T10:00:00+00:00');

        $this->expectException(InvalidCategorizationRule::class);
        $this->rule()->archive($now)->archive($now);
    }

    /** @param array<string, mixed> $overrides */
    private function rule(array $overrides = []): CategorizationRule
    {
        /** @var array{label: string, priority: int, accountScope: list<string>, targetAxes: list<AnalyticAxis>, targetCounterparty: ?string, effectiveTo: ?\DateTimeImmutable, active: bool, deactivatedReason: ?RuleDeactivationReason} $values */
        $values = [
            'label' => 'Courses',
            'priority' => 10,
            'accountScope' => [],
            'targetAxes' => [AnalyticAxis::ESSENTIAL],
            'targetCounterparty' => null,
            'effectiveTo' => null,
            'active' => true,
            'deactivatedReason' => null,
            ...$overrides,
        ];
        $now = new \DateTimeImmutable('2026-03-14T09:00:00+00:00');

        return new CategorizationRule(
            id: self::RULE,
            workspace: WorkspaceFixture::own(),
            label: $values['label'],
            priority: $values['priority'],
            accountScope: $values['accountScope'],
            conditions: RuleConditions::fromDocument(self::textConditions('RAW_LABEL', 'CONTAINS', 'CARREFOUR')),
            targetCategoryId: self::CATEGORY,
            targetAxes: $values['targetAxes'],
            targetCounterparty: $values['targetCounterparty'],
            effectiveFrom: new \DateTimeImmutable('2026-01-01'),
            effectiveTo: $values['effectiveTo'],
            active: $values['active'],
            deactivatedReason: $values['deactivatedReason'],
            appliedCount: 0,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
            archivedAt: null,
        );
    }

    private function revise(CategorizationRule $rule, bool $active, \DateTimeImmutable $now): CategorizationRule
    {
        return $rule->revise(
            label: $rule->label, priority: $rule->priority, accountScope: $rule->accountScope,
            conditions: $rule->conditions, targetCategoryId: $rule->targetCategoryId, targetAxes: $rule->targetAxes,
            targetCounterparty: $rule->targetCounterparty, effectiveFrom: $rule->effectiveFrom,
            effectiveTo: $rule->effectiveTo, active: $active, updatedAt: $now,
        );
    }

    /** @return array{text: array{combinator: string, predicates: list<array{source: string, operator: string, value: string, negated: bool}>}} */
    private static function textConditions(string $source, string $operator, string $value): array
    {
        return ['text' => ['combinator' => 'AND', 'predicates' => [[
            'source' => $source, 'operator' => $operator, 'value' => $value, 'negated' => false,
        ]]]];
    }

    private function subject(string $bookedOn, string $accountId = self::ACCOUNT): CategorizationSubject
    {
        return new CategorizationSubject(
            accountId: $accountId,
            amount: new AssetAmount(DecimalValue::fromString('-42.90'), AssetCode::fromString('EUR')),
            nature: TransactionNature::EXPENSE,
            state: TransactionState::BOOKED,
            bookedOn: new \DateTimeImmutable($bookedOn),
            rawLabel: 'CB CARREFOUR 1234',
            counterparty: null,
            mcc: null,
        );
    }
}
