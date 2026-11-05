<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionDirection;
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

    public function testAbsoluteAmountRangeMatchesEitherSignAndIsScopedToItsAssetCode(): void
    {
        $expense = $this->add('00000000-0000-7000-8000-0000000000f7', '-42.10');
        $refund = $this->add('00000000-0000-7000-8000-0000000000f8', '18.40');
        $this->add('00000000-0000-7000-8000-0000000000f6', '-60.00');
        $this->add('00000000-0000-7000-8000-0000000000f5', '9.99');

        $filters = new TransactionFilters(minAmount: DecimalValue::fromString('10'), maxAmount: DecimalValue::fromString('50'), assetCode: AssetCode::fromString('EUR'));
        self::assertEqualsCanonicalizing([$expense, $refund], array_column($this->repository->search(WorkspaceFixture::own(), $filters, 10, null), 'id'));

        $otherAsset = new TransactionFilters(minAmount: DecimalValue::fromString('10'), assetCode: AssetCode::fromString('USD'));
        self::assertSame([], $this->repository->search(WorkspaceFixture::own(), $otherAsset, 10, null));
    }

    public function testDirectionKeepsOnlyOutflowsOrInflows(): void
    {
        $expense = $this->add('00000000-0000-7000-8000-0000000000f7', '-42.10');
        $refund = $this->add('00000000-0000-7000-8000-0000000000f8', '18.40');

        self::assertSame([$expense], array_column($this->repository->search(WorkspaceFixture::own(), new TransactionFilters(direction: TransactionDirection::OUTFLOW), 10, null), 'id'));
        self::assertSame([$refund], array_column($this->repository->search(WorkspaceFixture::own(), new TransactionFilters(direction: TransactionDirection::INFLOW), 10, null), 'id'));
        self::assertEqualsCanonicalizing(
            [$expense, $refund],
            array_column($this->repository->search(WorkspaceFixture::own(), new TransactionFilters(), 10, null), 'id'),
        );

        $bounded = new TransactionFilters(
            minAmount: DecimalValue::fromString('10'), maxAmount: DecimalValue::fromString('50'),
            assetCode: AssetCode::fromString('EUR'), direction: TransactionDirection::OUTFLOW,
        );
        self::assertSame([$expense], array_column($this->repository->search(WorkspaceFixture::own(), $bounded, 10, null), 'id'));
    }

    public function testAnAmountSearchMatchesTheAbsoluteValueOrTheLabelAndTheCountEqualsThePage(): void
    {
        $expense = $this->add('00000000-0000-7000-8000-0000000000f7', '-10.50');
        $income = $this->add('00000000-0000-7000-8000-0000000000f8', '10.5000');
        $labelled = $this->add('00000000-0000-7000-8000-0000000000f6', '-3.00', label: 'CB 10,50 PARIS');
        $this->add('00000000-0000-7000-8000-0000000000f5', '-10.51');
        $this->add('00000000-0000-7000-8000-0000000000f4', '-110.50');

        $filters = new TransactionFilters(q: '10.50', qAmount: DecimalValue::fromString('10.50'));
        $page = array_column($this->repository->search(WorkspaceFixture::own(), $filters, 10, null), 'id');
        self::assertEqualsCanonicalizing([$expense, $income], $page);

        $labelFilters = new TransactionFilters(q: '10,50', qAmount: DecimalValue::fromString('10.50'));
        $labelPage = array_column($this->repository->search(WorkspaceFixture::own(), $labelFilters, 10, null), 'id');
        self::assertEqualsCanonicalizing([$expense, $income, $labelled], $labelPage);
        self::assertSame(3, $this->repository->countMatching(WorkspaceFixture::own(), $labelFilters));
        self::assertSame(2, $this->repository->countMatching(WorkspaceFixture::own(), $filters));
    }

    public function testAHugeAmountSearchNeverMatchesAnotherWorkspace(): void
    {
        $this->add('00000000-0000-7000-8000-0000000000f7', '-10.50');
        $filters = new TransactionFilters(q: '10.50', qAmount: DecimalValue::fromString('10.50'));

        self::assertSame([], $this->repository->search(WorkspaceFixture::other(), $filters, 10, null));
        self::assertSame(0, $this->repository->countMatching(WorkspaceFixture::other(), $filters));

        $huge = str_repeat('9', 26).'.'.str_repeat('9', 24);
        $big = $this->add('00000000-0000-7000-8000-0000000000f8', '-'.$huge);
        $hugeFilters = new TransactionFilters(q: $huge, qAmount: DecimalValue::fromString($huge));
        self::assertSame([$big], array_column($this->repository->search(WorkspaceFixture::own(), $hugeFilters, 10, null), 'id'));
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

    public function testFreeTextSearchMatchesEitherTheSourceLabelOrTheUserLabel(): void
    {
        $relabelled = $this->add(
            '00000000-0000-7000-8000-000000000101', '-1.00', source: TransactionSource::IMPORT,
            label: 'CB CARREFOUR 1234', userLabel: 'Courses du samedi',
        );
        $this->add('00000000-0000-7000-8000-000000000102', '-1.00', label: 'CB AUCHAN');

        foreach (['carrefour 1234', 'COURSES DU', 'du_samedi'] as $query) {
            $found = array_column($this->repository->search(WorkspaceFixture::own(), new TransactionFilters(q: $query), 10, null), 'id');
            self::assertSame('du_samedi' === $query ? [] : [$relabelled], $found, $query);
        }
    }

    public function testTheUserLabelRoundTripsAndClearingItRestoresTheSourceLabelOnly(): void
    {
        $id = $this->add('00000000-0000-7000-8000-000000000103', '-1.00', source: TransactionSource::PROVIDER, label: 'PRLV SEPA 8841');
        $stored = $this->repository->find(WorkspaceFixture::own(), $id);
        self::assertNotNull($stored);
        self::assertNull($stored->userLabel);

        self::assertTrue($this->repository->update($stored->withUserLabel('Loyer'), 1));
        $relabelled = $this->repository->find(WorkspaceFixture::own(), $id);
        self::assertNotNull($relabelled);
        self::assertSame('Loyer', $relabelled->userLabel);
        self::assertSame('PRLV SEPA 8841', $relabelled->rawLabel);

        self::assertTrue($this->repository->update($relabelled->withUserLabel(null), 1));
        $cleared = $this->repository->find(WorkspaceFixture::own(), $id);
        self::assertNotNull($cleared);
        self::assertNull($cleared->userLabel);
        self::assertSame('PRLV SEPA 8841', $cleared->displayLabel());
    }

    public function testASearchOnAUserLabelNeverLeaksAnotherWorkspace(): void
    {
        $this->add('00000000-0000-7000-8000-000000000104', '-1.00', userLabel: 'Secret perso');

        self::assertSame([], $this->repository->search(WorkspaceFixture::other(), new TransactionFilters(q: 'secret'), 10, null));
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
        ?string $userLabel = null,
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
            nature: str_starts_with($amount, '-') ? TransactionNature::EXPENSE : TransactionNature::INCOME, source: $source ?? TransactionSource::MANUAL, sourceRef: null,
            bookedOn: new \DateTimeImmutable('2026-03-14'), valueOn: null, authorizedOn: null,
            rawLabel: $label, counterparty: null, note: null, paymentMethod: null, mcc: null,
            maskedCard: null, bankReference: null, splits: $splits, version: 1, createdAt: $now,
            updatedAt: $now, voidedAt: null, lastEditorId: WorkspaceFixture::OWNER_ID, userLabel: $userLabel,
        ));

        return $id;
    }
}
