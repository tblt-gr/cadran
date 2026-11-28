<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application\Categorization;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditEvent;
use App\Module\Audit\Domain\AuditEventRepository;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\Categorization\AutoCategorizationBatch;
use App\Module\Transactions\Application\Categorization\AutoCategorizeTransaction;
use App\Module\Transactions\Application\Categorization\CategorizationExecutionBudget;
use App\Module\Transactions\Application\Categorization\CategorizationResolver;
use App\Module\Transactions\Application\Categorization\DeactivateTrippedRules;
use App\Module\Transactions\Application\Categorization\ElapsedTime;
use App\Module\Transactions\Application\Categorization\RuleMatchingRun;
use App\Module\Transactions\Domain\Categorization\CategorizationRule;
use App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository;
use App\Module\Transactions\Domain\Categorization\CategorizationSubject;
use App\Module\Transactions\Domain\Categorization\RuleConditions;
use App\Module\Transactions\Domain\CategorizationOrigin;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Module\Transactions\Application\Double\SteppingElapsedTime;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class AutoCategorizationBatchTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string GROCERIES = '00000000-0000-7000-8000-0000000000c1';
    private const string LEISURE = '00000000-0000-7000-8000-0000000000c2';
    private const string RULE_A = '00000000-0000-7000-8000-0000000000e1';
    private const string RULE_B = '00000000-0000-7000-8000-0000000000e2';
    private const string RULE_C = '00000000-0000-7000-8000-0000000000e3';

    /** @var list<CategorizationRule> */
    private array $rules = [];
    private int $rulesReads = 0;
    /** @var list<string> */
    private array $finds = [];
    /** @var list<string> */
    private array $locks = [];
    /** @var list<array{string, int}> */
    private array $increments = [];
    /** @var list<string> */
    private array $deactivations = [];
    private int $audited = 0;
    private ?\DateTimeImmutable $lockedArchivedAt = null;

    public function testFiftyTransactionsReadTheRulesOnceAndWriteOneCounterPerRule(): void
    {
        $this->rules = [
            $this->rule(self::RULE_C, 30, 'cinema', self::LEISURE),
            $this->rule(self::RULE_A, 10, 'carrefour', self::GROCERIES),
            $this->rule(self::RULE_B, 20, 'market', self::GROCERIES),
        ];
        $labels = ['CB CARREFOUR MARKET', 'CB MARKET', 'CB CINEMA', 'CB UNKNOWN', 'CB CARREFOUR'];
        $batch = $this->batch();

        for ($i = 0; $i < 50; ++$i) {
            $transaction = $this->transaction($i, $labels[$i % 5]);
            $categorized = $batch->categorize($transaction);

            $alone = new CategorizationResolver()->resolve(
                $this->rules, $this->categories(), CategorizationSubject::fromTransaction($transaction),
                new RuleMatchingRun(new SteppingElapsedTime(1)), true,
            )->winner;
            if (null === $alone) {
                self::assertSame($transaction, $categorized);
            } else {
                self::assertCount(1, $categorized->splits);
                $split = $categorized->splits[0];
                self::assertSame($alone->id, $split->ruleId);
                self::assertSame($alone->targetCategoryId, $split->categoryId);
                self::assertSame(CategorizationOrigin::RULE, $split->origin);
                self::assertSame($transaction->amount, $split->amount);
                self::assertSame($transaction->version, $categorized->version);
            }
            $batch->countApplied($categorized);
        }
        $batch->finish();

        self::assertSame(1, $this->rulesReads);
        self::assertSame([self::GROCERIES, self::LEISURE], $this->finds);
        self::assertSame([self::GROCERIES, self::LEISURE], $this->locks);
        self::assertEqualsCanonicalizing([[self::RULE_A, 20], [self::RULE_B, 10], [self::RULE_C, 10]], $this->increments);
        self::assertSame([], $this->deactivations);
    }

    public function testAManualOrUncategorizableTransactionIsUntouched(): void
    {
        $this->rules = [$this->rule(self::RULE_A, 10, 'carrefour', self::GROCERIES)];
        $batch = $this->batch();
        $manual = $this->transaction(1, 'CB CARREFOUR', manualCategory: self::LEISURE);
        $transfer = $this->transaction(2, 'CB CARREFOUR', TransactionNature::TRANSFER);

        self::assertSame($manual, $batch->categorize($manual));
        self::assertSame($transfer, $batch->categorize($transfer));
        $batch->countApplied($manual);
        $batch->finish();

        self::assertSame([], $this->locks);
        self::assertSame([], $this->increments);
    }

    public function testATargetThatNoLongerFitsOnceLockedLeavesTheTransactionUncategorized(): void
    {
        $this->rules = [$this->rule(self::RULE_A, 10, 'carrefour', self::GROCERIES)];
        $this->lockedArchivedAt = new \DateTimeImmutable('2026-03-01T00:00:00+00:00');
        $batch = $this->batch();
        $transaction = $this->transaction(1, 'CB CARREFOUR');

        self::assertSame($transaction, $batch->categorize($transaction));
        self::assertSame($transaction, $batch->categorize($transaction));
        self::assertSame([self::GROCERIES], $this->locks);
    }

    public function testATrippedRuleIsSkippedAfterwardsAndDeactivatedOnceByFinish(): void
    {
        $this->rules = [
            $this->rule(self::RULE_A, 10, '^(a|a)*$', self::GROCERIES, 'REGEX'),
            $this->rule(self::RULE_B, 20, 'a', self::LEISURE),
        ];
        $batch = $this->batch();

        $first = $batch->categorize($this->transaction(1, str_repeat('a', 40).'!'));
        // The tripped pattern would match this label, so only skipping it explains the second winner.
        $second = $batch->categorize($this->transaction(2, 'aaa'));

        self::assertSame(self::RULE_B, $first->splits[0]->ruleId);
        self::assertSame(self::RULE_B, $second->splits[0]->ruleId);
        self::assertSame([], $this->deactivations, 'The deactivation waits for finish().');
        $batch->finish();
        self::assertSame([self::RULE_A], $this->deactivations);
        self::assertSame(1, $this->audited);
    }

    public function testALimitOverrunLeavesTheRestUncategorizedAndStillDeactivatesTrippedRules(): void
    {
        $this->rules = [
            $this->rule(self::RULE_A, 10, '^(a|a)*$', self::GROCERIES, 'REGEX'),
            $this->rule(self::RULE_B, 20, 'a', self::LEISURE),
        ];
        $batch = $this->batch(new CategorizationExecutionBudget(maxRuleEvaluations: 1));
        $overrun = $this->transaction(1, str_repeat('a', 40).'!');
        $rest = $this->transaction(2, 'a');

        self::assertSame($overrun, $batch->categorize($overrun));
        self::assertSame($rest, $batch->categorize($rest));
        $batch->finish();

        self::assertSame([self::RULE_A], $this->deactivations);
    }

    public function testMoreActiveRulesThanTheBoundLeaveEverythingUncategorized(): void
    {
        $this->rules = [
            $this->rule(self::RULE_A, 10, 'carrefour', self::GROCERIES),
            $this->rule(self::RULE_B, 20, 'carrefour', self::GROCERIES),
        ];
        $batch = $this->batch(new CategorizationExecutionBudget(maxActiveRules: 1));
        $transaction = $this->transaction(1, 'CB CARREFOUR');

        self::assertSame($transaction, $batch->categorize($transaction));
        self::assertSame([], $this->finds);
    }

    public function testFinishWithoutACountedTransactionWritesNoCount(): void
    {
        $this->rules = [$this->rule(self::RULE_A, 10, 'carrefour', self::GROCERIES)];
        $batch = $this->batch();

        self::assertCount(1, $batch->categorize($this->transaction(1, 'CB CARREFOUR'))->splits);
        $batch->finish();

        self::assertSame([], $this->increments);
    }

    private function batch(?CategorizationExecutionBudget $budget = null): AutoCategorizationBatch
    {
        $rules = self::createStub(CategorizationRuleRepository::class);
        $rules->method('activeInOrder')->willReturnCallback(function (WorkspaceScope $workspace, int $limit): array {
            ++$this->rulesReads;

            return array_slice($this->rules, 0, $limit);
        });
        $rules->method('findForUpdate')->willReturnCallback(fn (WorkspaceScope $workspace, string $id): ?CategorizationRule => array_find($this->rules, static fn (CategorizationRule $rule): bool => $rule->id === $id));
        $rules->method('update')->willReturnCallback(function (CategorizationRule $rule): bool {
            $this->deactivations[] = $rule->id;

            return true;
        });
        $rules->method('incrementAppliedCount')->willReturnCallback(function (WorkspaceScope $workspace, string $id, int $by): void {
            $this->increments[] = [$id, $by];
        });

        $categories = self::createStub(CategoryRepository::class);
        $categories->method('find')->willReturnCallback(function (WorkspaceScope $workspace, string $id): ?Category {
            $this->finds[] = $id;

            return $this->categories()[$id] ?? null;
        });
        $categories->method('findForUpdate')->willReturnCallback(function (WorkspaceScope $workspace, string $id): ?Category {
            $this->locks[] = $id;

            return $this->categories($this->lockedArchivedAt)[$id] ?? null;
        });

        $events = self::createStub(AuditEventRepository::class);
        $events->method('append')->willReturnCallback(function (AuditEvent $event): void {
            ++$this->audited;
        });
        $ids = self::createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('00000000-0000-7000-8000-0000000000aa');
        $clock = self::createStub(ElapsedTime::class);
        $clock->method('nanoseconds')->willReturn(0);

        $categorize = new AutoCategorizeTransaction(
            $rules, $categories, new CategorizationResolver(), $clock, $budget ?? new CategorizationExecutionBudget(),
            new DeactivateTrippedRules($rules, new RecordAuditEvent($events, $ids)), $ids,
        );

        return $categorize->begin(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID, new \DateTimeImmutable('2026-03-14T09:00:00+00:00'));
    }

    /** @return array<string, Category> */
    private function categories(?\DateTimeImmutable $archivedAt = null): array
    {
        $at = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $categories = [];
        foreach ([self::GROCERIES, self::LEISURE] as $id) {
            $categories[$id] = new Category(
                id: $id, workspace: WorkspaceFixture::own(), type: CategoryType::EXPENSE, label: 'Catégorie', parentId: null,
                icon: null, color: null, defaultAnalyticAxes: [], budgetIncluded: true, sortOrder: 0, depth: 1,
                version: 1, createdAt: $at, updatedAt: $at, usedAt: null, archivedAt: $archivedAt,
            );
        }

        return $categories;
    }

    private function rule(string $id, int $priority, string $value, string $target, string $operator = 'CONTAINS'): CategorizationRule
    {
        $created = new \DateTimeImmutable('2026-03-01T00:00:00+00:00');

        return new CategorizationRule(
            id: $id, workspace: WorkspaceFixture::own(), label: 'Règle', priority: $priority, accountScope: [],
            conditions: RuleConditions::fromDocument(['text' => ['combinator' => 'AND', 'predicates' => [[
                'source' => 'RAW_LABEL', 'operator' => $operator, 'value' => $value, 'negated' => false,
            ]]]]),
            targetCategoryId: $target, targetAxes: [], targetCounterparty: null,
            effectiveFrom: new \DateTimeImmutable('2026-01-01'), effectiveTo: null, active: true,
            deactivatedReason: null, appliedCount: 0, version: 1, createdAt: $created, updatedAt: $created, archivedAt: null,
        );
    }

    private function transaction(int $number, string $label, TransactionNature $nature = TransactionNature::EXPENSE, ?string $manualCategory = null): Transaction
    {
        $id = sprintf('00000000-0000-7000-8000-%012d', $number);
        $now = new \DateTimeImmutable('2026-03-14T09:00:00+00:00');
        $amount = new AssetAmount(DecimalValue::fromString('-42.90'), AssetCode::fromString('EUR'));

        return new Transaction(
            id: $id, workspace: WorkspaceFixture::own(), accountId: self::ACCOUNT, amount: $amount,
            originalAmount: null, exchangeRate: null, state: TransactionState::BOOKED, nature: $nature,
            source: TransactionSource::MANUAL, sourceRef: null, bookedOn: new \DateTimeImmutable('2026-03-14'),
            valueOn: null, authorizedOn: null, rawLabel: $label, counterparty: null, note: null, paymentMethod: null,
            mcc: null, maskedCard: null, bankReference: null,
            splits: null === $manualCategory ? [] : [new TransactionSplit($id, WorkspaceFixture::own(), $id, $manualCategory, $amount, [], null, $now)],
            version: 1, createdAt: $now, updatedAt: $now, voidedAt: null, lastEditorId: WorkspaceFixture::OWNER_ID,
        );
    }
}
