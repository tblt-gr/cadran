<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application\Categorization;

use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Application\Categorization\CategorizationExecutionLimitExceeded;
use App\Module\Transactions\Application\Categorization\CategorizationResolver;
use App\Module\Transactions\Application\Categorization\RuleMatchingRun;
use App\Module\Transactions\Domain\Categorization\CategorizationRule;
use App\Module\Transactions\Domain\Categorization\CategorizationSubject;
use App\Module\Transactions\Domain\Categorization\RuleConditions;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Module\Transactions\Application\Double\SteppingElapsedTime;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategorizationResolverTest extends TestCase
{
    private const string EXPENSE = '00000000-0000-7000-8000-0000000000c1';
    private const string INCOME = '00000000-0000-7000-8000-0000000000c2';
    private const string ARCHIVED_EXPENSE = '00000000-0000-7000-8000-0000000000c3';
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string RULE_A = '00000000-0000-7000-8000-0000000000e1';
    private const string RULE_B = '00000000-0000-7000-8000-0000000000e2';
    private const string RULE_C = '00000000-0000-7000-8000-0000000000e3';

    private CategorizationResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CategorizationResolver();
    }

    public function testTheLowestPriorityNumberWinsAndEveryMatchIsReported(): void
    {
        $low = $this->rule(self::RULE_A, 20);
        $high = $this->rule(self::RULE_B, 10);

        $resolution = $this->resolver->resolve([$low, $high], $this->categories(), $this->subject(), $this->matchingRun(), firstMatchOnly: false);

        self::assertSame(self::RULE_B, $resolution->winner?->id);
        self::assertSame([self::RULE_B, self::RULE_A], $resolution->matchingRuleIds);
    }

    public function testTiesBreakByCreationTimeThenIdentifier(): void
    {
        $later = $this->rule(self::RULE_A, 10, createdAt: '2026-03-02T00:00:00+00:00');
        $earlierHigherId = $this->rule(self::RULE_C, 10, createdAt: '2026-03-01T00:00:00+00:00');
        $earlierLowerId = $this->rule(self::RULE_B, 10, createdAt: '2026-03-01T00:00:00+00:00');

        $resolution = $this->resolver->resolve(
            [$later, $earlierHigherId, $earlierLowerId], $this->categories(), $this->subject(), $this->matchingRun(), firstMatchOnly: false,
        );

        self::assertSame([self::RULE_B, self::RULE_C, self::RULE_A], $resolution->matchingRuleIds);
    }

    public function testAMovementOutsideEveryPeriodStaysUncategorised(): void
    {
        $rule = $this->rule(self::RULE_A, 10, effectiveFrom: '2026-04-01');

        $resolution = $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $this->matchingRun(), firstMatchOnly: true);

        self::assertNull($resolution->winner);
        self::assertSame([], $resolution->matchingRuleIds);
    }

    public function testATargetOfTheWrongTypeOrArchivedOrUnknownIsANonMatch(): void
    {
        $income = $this->rule(self::RULE_A, 1, target: self::INCOME);
        $archived = $this->rule(self::RULE_B, 2, target: self::ARCHIVED_EXPENSE);
        $unknown = $this->rule('00000000-0000-7000-8000-0000000000e4', 3, target: '00000000-0000-7000-8000-0000000000c9');
        $valid = $this->rule(self::RULE_C, 4);

        $resolution = $this->resolver->resolve([$income, $archived, $unknown, $valid], $this->categories(), $this->subject(), $this->matchingRun(), firstMatchOnly: false);

        self::assertSame(self::RULE_C, $resolution->winner?->id);
        self::assertSame([self::RULE_C], $resolution->matchingRuleIds);
    }

    /** @return iterable<string, array{TransactionNature, TransactionState, string}> */
    public static function ineligibleMovements(): iterable
    {
        yield 'a refund' => [TransactionNature::REFUND, TransactionState::BOOKED, '42.90'];
        yield 'a transfer leg' => [TransactionNature::TRANSFER, TransactionState::BOOKED, '-42.90'];
        yield 'a voided expense' => [TransactionNature::EXPENSE, TransactionState::VOIDED, '-42.90'];
        yield 'a rejected expense' => [TransactionNature::EXPENSE, TransactionState::REJECTED, '-42.90'];
    }

    #[DataProvider('ineligibleMovements')]
    public function testAnIneligibleMovementNeverResolves(TransactionNature $nature, TransactionState $state, string $amount): void
    {
        $rule = $this->rule(self::RULE_A, 1, conditions: self::textConditions('RAW_LABEL', 'CONTAINS', 'carrefour'));

        $resolution = $this->resolver->resolve([$rule], $this->categories(), $this->subject(amount: $amount, nature: $nature, state: $state), $this->matchingRun(), firstMatchOnly: false);

        self::assertNull($resolution->winner);
    }

    public function testFirstMatchOnlyStopsBeforeEvaluatingLaterPatterns(): void
    {
        $timer = new SteppingElapsedTime(1);
        $first = $this->rule(self::RULE_A, 1, conditions: self::textConditions('RAW_LABEL', 'CONTAINS', 'carrefour'));
        $second = $this->rule(self::RULE_B, 2, conditions: self::textConditions('RAW_LABEL', 'REGEX', 'carrefour'));

        $resolution = $this->resolver->resolve([$first, $second], $this->categories(), $this->subject(), new RuleMatchingRun($timer), firstMatchOnly: true);

        self::assertSame(self::RULE_A, $resolution->winner?->id);
        self::assertSame(2, $timer->readings, 'Only the run guard and the winning non-regex rule are timed.');
    }

    public function testABacktrackExplosionTripsTheRuleForTheRestOfTheRun(): void
    {
        $limitBefore = ini_get('pcre.backtrack_limit');
        $run = $this->matchingRun();
        $rule = $this->rule(self::RULE_A, 1, conditions: self::textConditions('RAW_LABEL', 'REGEX', '^(a|a)*$'));

        $exploding = $this->resolver->resolve([$rule], $this->categories(), $this->subject(rawLabel: str_repeat('a', 40).'!'), $run, firstMatchOnly: false);
        $harmless = $this->resolver->resolve([$rule], $this->categories(), $this->subject(rawLabel: 'aaa'), $run, firstMatchOnly: false);

        self::assertNull($exploding->winner);
        self::assertNull($harmless->winner, 'A tripped rule is never retried within the run.');
        self::assertSame([self::RULE_A], $run->trippedRuleIds());
        self::assertSame($limitBefore, ini_get('pcre.backtrack_limit'));
    }

    public function testCumulatedMatchingTimeBeyondFiftyMillisecondsTripsTheRule(): void
    {
        $run = new RuleMatchingRun(new SteppingElapsedTime(30_000_000));
        $rule = $this->rule(self::RULE_A, 1, conditions: self::textConditions('RAW_LABEL', 'REGEX', 'carrefour'));

        $first = $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $run, firstMatchOnly: false);
        $second = $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $run, firstMatchOnly: false);

        self::assertSame(self::RULE_A, $first->winner?->id, '30 ms cumulated stays within the budget.');
        self::assertNull($second->winner, '60 ms cumulated exceeds the 50 ms budget.');
        self::assertSame([self::RULE_A], $run->trippedRuleIds());
    }

    public function testRepeatedRegexPredicatesShareTheRuleBudgetAndNegationDoesNotHideTheTrip(): void
    {
        $run = new RuleMatchingRun(new SteppingElapsedTime(30_000_000));
        $conditions = ['text' => ['combinator' => 'AND', 'predicates' => [
            ['source' => 'RAW_LABEL', 'operator' => 'REGEX', 'value' => 'carrefour', 'negated' => false],
            ['source' => 'NORMALIZED_LABEL', 'operator' => 'REGEX', 'value' => 'never', 'negated' => true],
        ]]];
        $rule = $this->rule(self::RULE_A, 1, conditions: $conditions);

        $resolution = $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $run, firstMatchOnly: false);

        self::assertNull($resolution->winner);
        self::assertSame([self::RULE_A], $run->trippedRuleIds());
    }

    public function testAnotherRuleKeepsItsOwnBudgetWhenOneTrips(): void
    {
        $run = $this->matchingRun();
        $exploding = $this->rule(self::RULE_A, 1, conditions: self::textConditions('RAW_LABEL', 'REGEX', '^(a|a)*$'));
        $fallback = $this->rule(self::RULE_B, 2, conditions: self::textConditions('RAW_LABEL', 'CONTAINS', 'aaaa'));

        $resolution = $this->resolver->resolve([$exploding, $fallback], $this->categories(), $this->subject(rawLabel: str_repeat('a', 40).'!'), $run, firstMatchOnly: true);

        self::assertSame(self::RULE_B, $resolution->winner?->id);
    }

    public function testTheRunStopsAtItsGlobalEvaluationLimit(): void
    {
        $run = new RuleMatchingRun(new SteppingElapsedTime(1), maxEvaluations: 1);
        $rule = $this->rule(self::RULE_A, 1);

        $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $run, firstMatchOnly: false);

        $this->expectException(CategorizationExecutionLimitExceeded::class);
        $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $run, firstMatchOnly: false);
    }

    public function testDroppingRulesFromAResolutionPromotesTheNextMatchingRule(): void
    {
        $a = $this->rule(self::RULE_A, 1);
        $b = $this->rule(self::RULE_B, 2);
        $c = $this->rule(self::RULE_C, 3);
        $resolution = $this->resolver->resolve([$a, $b, $c], $this->categories(), $this->subject(), $this->matchingRun(), firstMatchOnly: false);

        $withoutWinner = $resolution->withoutRules([self::RULE_A], [self::RULE_B => $b, self::RULE_C => $c]);
        $withoutAll = $resolution->withoutRules([self::RULE_A, self::RULE_B, self::RULE_C], []);
        $untouched = $resolution->withoutRules([self::RULE_B], [self::RULE_A => $a, self::RULE_C => $c]);

        self::assertSame($b, $withoutWinner->winner);
        self::assertSame([self::RULE_B, self::RULE_C], $withoutWinner->matchingRuleIds);
        self::assertNull($withoutAll->winner);
        self::assertSame([], $withoutAll->matchingRuleIds);
        self::assertSame($a, $untouched->winner);
        self::assertSame([self::RULE_A, self::RULE_C], $untouched->matchingRuleIds);
    }

    public function testASecondPassGetsAFreshEvaluationCapInsteadOfInheritingTheFirstPassCount(): void
    {
        $firstPass = new RuleMatchingRun(new SteppingElapsedTime(1), maxEvaluations: 2);
        $rule = $this->rule(self::RULE_A, 1);
        $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $firstPass, firstMatchOnly: false);
        $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $firstPass, firstMatchOnly: false);

        $secondPass = $firstPass->nextPass();
        $resolution = $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $secondPass, firstMatchOnly: false);
        $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $secondPass, firstMatchOnly: false);

        self::assertSame(self::RULE_A, $resolution->winner?->id);
        $this->expectException(CategorizationExecutionLimitExceeded::class);
        $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $secondPass, firstMatchOnly: false);
    }

    public function testTheRuntimeLimitStaysCumulativeAcrossPasses(): void
    {
        // Construction reads 0, then each evaluation reads 10 ms later: the second pass starts at 20 ms.
        $firstPass = new RuleMatchingRun(new SteppingElapsedTime(10_000_000), maxRunNanoseconds: 25_000_000);
        $rule = $this->rule(self::RULE_A, 1);
        $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $firstPass, firstMatchOnly: false);
        $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $firstPass, firstMatchOnly: false);

        $this->expectException(CategorizationExecutionLimitExceeded::class);
        $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $firstPass->nextPass(), firstMatchOnly: false);
    }

    public function testTheRunStopsAtItsGlobalRuntimeLimitWithoutChangingThePerRuleBudget(): void
    {
        $run = new RuleMatchingRun(new SteppingElapsedTime(30_000_000), maxRunNanoseconds: 20_000_000);
        $rule = $this->rule(self::RULE_A, 1);

        $this->expectException(CategorizationExecutionLimitExceeded::class);
        $this->resolver->resolve([$rule], $this->categories(), $this->subject(), $run, firstMatchOnly: false);
    }

    private function matchingRun(): RuleMatchingRun
    {
        return new RuleMatchingRun(new SteppingElapsedTime(1));
    }

    /** @return array<string, Category> */
    private function categories(): array
    {
        $at = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $category = static fn (string $id, CategoryType $type, ?\DateTimeImmutable $archivedAt = null): Category => new Category(
            id: $id, workspace: WorkspaceFixture::own(), type: $type, label: 'Catégorie', parentId: null, icon: null,
            color: null, defaultAnalyticAxes: [], budgetIncluded: true, sortOrder: 0, depth: 1, version: 1,
            createdAt: $at, updatedAt: $at, usedAt: null, archivedAt: $archivedAt,
        );

        return [
            self::EXPENSE => $category(self::EXPENSE, CategoryType::EXPENSE),
            self::INCOME => $category(self::INCOME, CategoryType::INCOME),
            self::ARCHIVED_EXPENSE => $category(self::ARCHIVED_EXPENSE, CategoryType::EXPENSE, $at),
        ];
    }

    /** @param array<string, mixed> $conditions */
    private function rule(
        string $id,
        int $priority,
        string $target = self::EXPENSE,
        string $createdAt = '2026-03-01T00:00:00+00:00',
        string $effectiveFrom = '2026-01-01',
        ?array $conditions = null,
    ): CategorizationRule {
        $created = new \DateTimeImmutable($createdAt);
        $conditions ??= self::textConditions('COUNTERPARTY', 'EQUALS', 'carrefour');

        return new CategorizationRule(
            id: $id, workspace: WorkspaceFixture::own(), label: 'Règle', priority: $priority, accountScope: [],
            conditions: RuleConditions::fromDocument($conditions), targetCategoryId: $target, targetAxes: [],
            targetCounterparty: null, effectiveFrom: new \DateTimeImmutable($effectiveFrom), effectiveTo: null,
            active: true, deactivatedReason: null, appliedCount: 0, version: 1, createdAt: $created,
            updatedAt: $created, archivedAt: null,
        );
    }

    /** @return array{text: array{combinator: string, predicates: list<array{source: string, operator: string, value: string, negated: bool}>}} */
    private static function textConditions(string $source, string $operator, string $value, bool $negated = false): array
    {
        return ['text' => ['combinator' => 'AND', 'predicates' => [[
            'source' => $source, 'operator' => $operator, 'value' => $value, 'negated' => $negated,
        ]]]];
    }

    private function subject(
        string $amount = '-42.90',
        string $rawLabel = 'CB CARREFOUR 1234',
        TransactionNature $nature = TransactionNature::EXPENSE,
        TransactionState $state = TransactionState::BOOKED,
    ): CategorizationSubject {
        return new CategorizationSubject(
            accountId: self::ACCOUNT,
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            nature: $nature,
            state: $state,
            bookedOn: new \DateTimeImmutable('2026-03-14'),
            rawLabel: $rawLabel,
            counterparty: 'Carrefour',
            mcc: null,
        );
    }
}
