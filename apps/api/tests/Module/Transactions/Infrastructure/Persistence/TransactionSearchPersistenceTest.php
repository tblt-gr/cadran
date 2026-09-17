<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionFilters;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TransactionSearchPersistenceTest extends KernelTestCase
{
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string OWN_CATEGORY = '00000000-0000-7000-8000-0000000000c1';
    private const string OTHER_CATEGORY = '00000000-0000-7000-8000-0000000000c2';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private TransactionRepository $repository;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->repository = new DbalTransactionRepository($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $this->seedAccount(self::OWN_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);
        $this->seedCategory(self::OWN_CATEGORY, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedCategory(self::OTHER_CATEGORY, WorkspaceFixture::OTHER_WORKSPACE);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testExplicitStatesReplaceTheDefaultScopeAndAnEmptyFilterAppliesNoRestriction(): void
    {
        $pending = $this->add('00000000-0000-7000-8000-0000000000f1', '-1.00', state: TransactionState::PENDING);
        $rejected = $this->add('00000000-0000-7000-8000-0000000000f2', '-1.00', state: TransactionState::REJECTED);

        self::assertSame(
            [$rejected],
            array_column($this->repository->search(WorkspaceFixture::own(), new TransactionFilters(states: [TransactionState::REJECTED]), 10, null), 'id'),
        );
        self::assertSame(
            [$rejected, $pending],
            array_column($this->repository->search(WorkspaceFixture::own(), new TransactionFilters(), 10, null), 'id'),
        );
    }

    public function testCategoryFilterMatchesThroughAnExistsSubqueryAndNeverLeaksAnotherWorkspace(): void
    {
        $categorised = $this->add('00000000-0000-7000-8000-0000000000f3', '-10.00', categoryId: self::OWN_CATEGORY);
        $this->add('00000000-0000-7000-8000-0000000000f4', '-10.00');

        self::assertSame(
            [$categorised],
            array_column($this->repository->search(WorkspaceFixture::own(), new TransactionFilters(categoryIds: [self::OWN_CATEGORY]), 10, null), 'id'),
        );
        // A category identifier from another workspace can never appear on this
        // workspace's splits (the FK ties a split's category to its own
        // workspace), so the filter answers an empty page, never a leak.
        self::assertSame(
            [],
            $this->repository->search(WorkspaceFixture::own(), new TransactionFilters(categoryIds: [self::OTHER_CATEGORY]), 10, null),
        );
    }

    public function testAxisFilterMatchesAnyRequestedAxisOnAnySplit(): void
    {
        $essential = $this->add('00000000-0000-7000-8000-0000000000f5', '-10.00', categoryId: self::OWN_CATEGORY, axes: [AnalyticAxis::ESSENTIAL]);
        $this->add('00000000-0000-7000-8000-0000000000f6', '-10.00', categoryId: self::OWN_CATEGORY, axes: [AnalyticAxis::VARIABLE]);

        self::assertSame(
            [$essential],
            array_column($this->repository->search(WorkspaceFixture::own(), new TransactionFilters(axes: [AnalyticAxis::ESSENTIAL]), 10, null), 'id'),
        );
    }

    public function testSignedAmountRangeIsScopedToItsAssetCode(): void
    {
        $bigExpense = $this->add('00000000-0000-7000-8000-0000000000f7', '-250.00');
        $this->add('00000000-0000-7000-8000-0000000000f8', '-50.00');

        $filters = new TransactionFilters(maxAmount: DecimalValue::fromString('-200.00'), assetCode: AssetCode::fromString('EUR'));
        self::assertSame([$bigExpense], array_column($this->repository->search(WorkspaceFixture::own(), $filters, 10, null), 'id'));
    }

    public function testFreeTextSearchTreatsWildcardCharactersAsLiteral(): void
    {
        $literal = $this->add('00000000-0000-7000-8000-0000000000f9', '-1.00', label: 'CB 50% CARREFOUR_PARIS');
        $this->add('00000000-0000-7000-8000-0000000000fa', '-1.00', label: 'CB AUCHAN');

        self::assertSame(
            [$literal],
            array_column($this->repository->search(WorkspaceFixture::own(), new TransactionFilters(q: '50% carrefour_'), 10, null), 'id'),
        );
        self::assertSame(
            [],
            $this->repository->search(WorkspaceFixture::own(), new TransactionFilters(q: '50X carrefourY'), 10, null),
        );
    }

    public function testCategorizationNoneReturnsOnlyUnsplitTransactions(): void
    {
        $uncategorized = $this->add('00000000-0000-7000-8000-0000000000fb', '-1.00');
        $this->add('00000000-0000-7000-8000-0000000000fc', '-1.00', categoryId: self::OWN_CATEGORY);

        self::assertSame(
            [$uncategorized],
            array_column($this->repository->search(WorkspaceFixture::own(), new TransactionFilters(categorizationNone: true), 10, null), 'id'),
        );
    }

    public function testWatermarkIsNullForAWorkspaceHoldingNoTransaction(): void
    {
        self::assertNull($this->repository->watermark(WorkspaceFixture::own()));
    }

    public function testWatermarkTracksTheMostRecentlyWrittenTransaction(): void
    {
        $id = '00000000-0000-7000-8000-0000000000fd';
        $transaction = $this->add($id, '-1.00');
        $watermark = $this->repository->watermark(WorkspaceFixture::own());
        self::assertNotNull($watermark);
        self::assertSame($transaction, $watermark->id);

        $stored = $this->repository->find(WorkspaceFixture::own(), $transaction);
        self::assertNotNull($stored);
        $voided = $stored->void(new \DateTimeImmutable('2026-03-16T00:00:00+00:00'), WorkspaceFixture::OWNER_ID);
        self::assertTrue($this->repository->update($voided, 1));

        $afterUpdate = $this->repository->watermark(WorkspaceFixture::own());
        self::assertNotNull($afterUpdate);
        self::assertFalse($afterUpdate->equals($watermark));
    }

    public function testAccountAndSourceFiltersCombineAsAConjunctionAcrossDisjunctionsInEachFilter(): void
    {
        $match = $this->add('00000000-0000-7000-8000-0000000000fe', '-1.00', source: TransactionSource::IMPORT);
        $this->add('00000000-0000-7000-8000-0000000000ff', '-1.00', source: TransactionSource::MANUAL);

        $filters = new TransactionFilters(accountIds: [self::OWN_ACCOUNT], sources: [TransactionSource::IMPORT, TransactionSource::PROVIDER]);
        self::assertSame([$match], array_column($this->repository->search(WorkspaceFixture::own(), $filters, 10, null), 'id'));
    }

    private function seedAccount(string $id, string $workspace): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => 'Compte '.$id, 'asset_code' => 'EUR',
            'kind' => 'CURRENT', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => false,
            'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01', 'closed_on' => null,
            'version' => 1, 'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    private function seedCategory(string $id, string $workspace): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id, 'workspace_id' => $workspace,
            'type' => 'EXPENSE', 'label' => 'Courses '.$id, 'parent_id' => null, 'icon' => null,
            'color' => null, 'default_analytic_axes' => '[]', 'budget_included' => true,
            'sort_order' => 0, 'depth' => 1, 'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ], [
            'budget_included' => ParameterType::BOOLEAN,
        ]);
    }

    /** @param list<AnalyticAxis> $axes */
    private function add(
        string $id,
        string $amount,
        ?TransactionState $state = null,
        ?string $categoryId = null,
        array $axes = [],
        ?TransactionSource $source = null,
        string $label = 'CB TEST',
    ): string {
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');
        $workspace = WorkspaceFixture::own();
        $value = new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR'));
        $splits = null === $categoryId ? [] : [new TransactionSplit(
            '1'.substr($id, 1), $workspace, $id, $categoryId, $value, $axes, null, $now,
        )];

        $this->repository->add(new Transaction(
            id: $id, workspace: $workspace, accountId: self::OWN_ACCOUNT, amount: $value,
            originalAmount: null, exchangeRate: null, state: $state ?? TransactionState::BOOKED,
            nature: TransactionNature::EXPENSE, source: $source ?? TransactionSource::MANUAL, sourceRef: null,
            bookedOn: new \DateTimeImmutable('2026-03-14'), valueOn: null, authorizedOn: null,
            rawLabel: $label, counterparty: null, note: null, paymentMethod: null, mcc: null,
            maskedCard: null, bankReference: null, splits: $splits, version: 1, createdAt: $now,
            updatedAt: $now, voidedAt: null, lastEditorId: WorkspaceFixture::OWNER_ID,
        ));

        return $id;
    }
}
