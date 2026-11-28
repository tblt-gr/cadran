<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application\Categorization;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditEventRepository;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Transactions\Application\Categorization\ApplyCategorizationRules;
use App\Module\Transactions\Application\Categorization\BuildCategorizationRun;
use App\Module\Transactions\Application\Categorization\CategorizationExecutionBudget;
use App\Module\Transactions\Application\Categorization\CategorizationResolver;
use App\Module\Transactions\Application\Categorization\CategorizationWriteLock;
use App\Module\Transactions\Application\Categorization\DeactivateTrippedRules;
use App\Module\Transactions\Application\Categorization\ElapsedTime;
use App\Module\Transactions\Application\Categorization\PreviewCategorizationRules;
use App\Module\Transactions\Application\Categorization\PreviewStale;
use App\Module\Transactions\Domain\Categorization\CategorizationPreview;
use App\Module\Transactions\Domain\Categorization\CategorizationPreviewRepository;
use App\Module\Transactions\Domain\Categorization\CategorizationRule;
use App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository;
use App\Module\Transactions\Domain\Categorization\RuleConditions;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Module\Transactions\Application\Double\SteppingElapsedTime;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PreviewCategorizationLockingTest extends TestCase
{
    private const string RULE = '00000000-0000-7000-8000-0000000000e1';
    private const string CATEGORY = '00000000-0000-7000-8000-0000000000c1';

    private int $acquisitions = 0;
    private int $categoryReads = 0;
    private int $categoryLocks = 0;
    private int $ruleWrites = 0;
    private ?CategorizationPreview $preview = null;

    public function testThePreviewTakesNoWorkspaceOrCategoryLockWhileApplyKeepsBoth(): void
    {
        $this->previewRules()(null, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'));

        self::assertSame(0, $this->acquisitions);
        self::assertSame(0, $this->categoryLocks);
        self::assertSame(1, $this->categoryReads);
        self::assertNotNull($this->preview);

        $this->applyRules()($this->preview->token);

        self::assertSame(1, $this->acquisitions);
        self::assertSame(1, $this->categoryLocks);
    }

    public function testARuleEditedDuringThePreviewMakesItStaleInsteadOfBeingDeactivated(): void
    {
        // Every timed evaluation costs 60 ms, beyond the 50 ms per-rule budget, so the rule trips.
        $preview = $this->previewRules(operator: 'REGEX', elapsed: new SteppingElapsedTime(60_000_000), lockedVersion: 2, withTransaction: true);

        try {
            $preview(null, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'));
            self::fail('A preview racing a rule edit must be stale.');
        } catch (PreviewStale) {
        }

        self::assertSame(0, $this->ruleWrites);
        self::assertNull($this->preview);
    }

    private function previewRules(string $operator = 'CONTAINS', ?ElapsedTime $elapsed = null, int $lockedVersion = 1, bool $withTransaction = false): PreviewCategorizationRules
    {
        $rules = $this->ruleRepository($operator, $lockedVersion);

        return new PreviewCategorizationRules(
            $this->caller(), $this->transactions($withTransaction), $rules, $this->previews(), $this->buildRun($rules, $elapsed),
            $this->ids(), $this->boundary(), new MockClock('2026-04-01T00:00:00+00:00'),
        );
    }

    private function applyRules(): ApplyCategorizationRules
    {
        $rules = $this->ruleRepository('CONTAINS', 1);
        $lock = self::createStub(CategorizationWriteLock::class);
        $lock->method('acquire')->willReturnCallback(function (): void {
            ++$this->acquisitions;
        });

        return new ApplyCategorizationRules(
            $this->caller(), $this->previews(), $this->transactions(false), $rules, $this->buildRun($rules, null), $this->ids(),
            $this->boundary(), $lock, new RecordAuditEvent(self::createStub(AuditEventRepository::class), $this->ids()),
            new MockClock('2026-04-01T00:00:00+00:00'), new AssertPeriodOpen(self::createStub(PeriodClosureRepository::class)),
        );
    }

    private function buildRun(CategorizationRuleRepository $rules, ?ElapsedTime $elapsed): BuildCategorizationRun
    {
        $categories = self::createStub(CategoryRepository::class);
        $categories->method('find')->willReturnCallback(function (): Category {
            ++$this->categoryReads;

            return self::category();
        });
        $categories->method('findForUpdate')->willReturnCallback(function (): Category {
            ++$this->categoryLocks;

            return self::category();
        });
        $clock = $elapsed ?? self::createStub(ElapsedTime::class);

        return new BuildCategorizationRun(
            $rules, $categories, new CategorizationResolver(), $clock, new CategorizationExecutionBudget(),
            new DeactivateTrippedRules($rules, new RecordAuditEvent(self::createStub(AuditEventRepository::class), $this->ids())),
        );
    }

    private static function category(): Category
    {
        $at = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Category(
            id: self::CATEGORY, workspace: WorkspaceFixture::own(), type: CategoryType::EXPENSE,
            label: 'Catégorie', parentId: null, icon: null, color: null, defaultAnalyticAxes: [], budgetIncluded: true,
            sortOrder: 0, depth: 1, version: 1, createdAt: $at, updatedAt: $at,
        );
    }

    private function ruleRepository(string $operator, int $lockedVersion): CategorizationRuleRepository
    {
        $repository = self::createStub(CategorizationRuleRepository::class);
        $repository->method('activeInOrder')->willReturn([$this->rule($operator, 1)]);
        $repository->method('findForUpdate')->willReturn($this->rule($operator, $lockedVersion));
        $repository->method('update')->willReturnCallback(function (): bool {
            ++$this->ruleWrites;

            return true;
        });

        return $repository;
    }

    private function rule(string $operator, int $version): CategorizationRule
    {
        $created = new \DateTimeImmutable('2026-03-01T00:00:00+00:00');

        return new CategorizationRule(
            id: self::RULE, workspace: WorkspaceFixture::own(), label: 'Règle', priority: 1, accountScope: [],
            conditions: RuleConditions::fromDocument(['text' => ['combinator' => 'AND', 'predicates' => [[
                'source' => 'RAW_LABEL', 'operator' => $operator, 'value' => 'carrefour', 'negated' => false,
            ]]]]),
            targetCategoryId: self::CATEGORY, targetAxes: [], targetCounterparty: null,
            effectiveFrom: new \DateTimeImmutable('2026-01-01'), effectiveTo: null, active: true,
            deactivatedReason: null, appliedCount: 0, version: $version, createdAt: $created, updatedAt: $created, archivedAt: null,
        );
    }

    private function transactions(bool $withTransaction): TransactionRepository
    {
        $now = new \DateTimeImmutable('2026-03-14T09:00:00+00:00');
        $transaction = new Transaction(
            id: '00000000-0000-7000-8000-000000000101', workspace: WorkspaceFixture::own(),
            accountId: '00000000-0000-7000-8000-0000000000d1',
            amount: new AssetAmount(DecimalValue::fromString('-42.90'), AssetCode::fromString('EUR')),
            originalAmount: null, exchangeRate: null, state: TransactionState::BOOKED, nature: TransactionNature::EXPENSE,
            source: TransactionSource::MANUAL, sourceRef: null, bookedOn: new \DateTimeImmutable('2026-03-14'),
            valueOn: null, authorizedOn: null, rawLabel: 'CB CARREFOUR', counterparty: null, note: null, paymentMethod: null,
            mcc: null, maskedCard: null, bankReference: null, splits: [], version: 1, createdAt: $now, updatedAt: $now,
            voidedAt: null, lastEditorId: WorkspaceFixture::OWNER_ID,
        );
        $repository = self::createStub(TransactionRepository::class);
        $repository->method('listForCategorization')->willReturn($withTransaction ? [$transaction] : []);

        return $repository;
    }

    private function previews(): CategorizationPreviewRepository
    {
        $repository = self::createStub(CategorizationPreviewRepository::class);
        $repository->method('add')->willReturnCallback(function (CategorizationPreview $preview): void {
            $this->preview = $preview;
        });
        $repository->method('findLiveForUpdate')->willReturnCallback(fn (): ?CategorizationPreview => $this->preview);

        return $repository;
    }

    private function caller(): CallerWorkspaceContext
    {
        $caller = self::createStub(CallerWorkspaceContext::class);
        $caller->method('resolveContext')->willReturn(new WorkspaceContext(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID));

        return $caller;
    }

    private function boundary(): TransactionBoundary
    {
        return new class implements TransactionBoundary {
            public function transactional(\Closure $callback): mixed
            {
                return $callback();
            }
        };
    }

    private function ids(): UuidGenerator
    {
        $ids = self::createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('00000000-0000-7000-8000-0000000000aa');

        return $ids;
    }
}
