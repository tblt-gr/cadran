<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Infrastructure\Persistence\DbalCategoryRepository;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\CategorySubtrees;
use App\Module\Transactions\Domain\TransactionDirection;
use App\Module\Transactions\Domain\TransactionFacet;
use App\Module\Transactions\Domain\TransactionFacetScope;
use App\Module\Transactions\Domain\TransactionFilters;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Tests\Support\TransactionFacetFixture as F;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The facet counts of the filter column, against the hand-computed data set of
 * {@see F}. The sections are counted over the same filter clauses as the search, and those
 * counted under the same filters share their scans of the ledger.
 */
final class TransactionFacetPersistenceTest extends KernelTestCase
{
    private const array DEFAULT_SCOPE = [TransactionState::PENDING, TransactionState::BOOKED];
    private const array EVERY_STATE = [TransactionState::PENDING, TransactionState::BOOKED, TransactionState::VOIDED, TransactionState::REJECTED];

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private DbalTransactionRepository $repository;

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
        (new F($connection))->seed();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testEachSectionCountsTheHandComputedFixtureUnderTheDefaultStateScope(): void
    {
        $scope = new TransactionFilters(states: self::DEFAULT_SCOPE);

        self::assertCounts([F::CURRENT => 4, F::SAVINGS => 2], $this->facet(TransactionFacet::ACCOUNT, $scope));
        self::assertCounts(['EXPENSE' => 4, 'INCOME' => 1, 'REFUND' => 1], $this->facet(TransactionFacet::NATURE, $scope));
        self::assertCounts(['MANUAL' => 3, 'IMPORT' => 3], $this->facet(TransactionFacet::SOURCE, $scope));
        self::assertCounts(['ESSENTIAL' => 4, 'VARIABLE' => 2], $this->facet(TransactionFacet::AXIS, $scope));
        self::assertCounts(
            [F::GROCERIES => 3, F::RESTAURANTS => 1, F::TRANSPORT => 1, F::FOOD => 1],
            $this->facet(TransactionFacet::CATEGORY, $scope),
        );
    }

    public function testTheStateSectionCountsEachOfTheFourStates(): void
    {
        self::assertCounts(
            ['PENDING' => 1, 'BOOKED' => 5, 'VOIDED' => 1, 'REJECTED' => 1],
            $this->facet(TransactionFacet::STATE, new TransactionFilters(states: self::EVERY_STATE)),
        );
    }

    public function testRollingUpCountsEachTransactionOncePerAncestorEvenWhenSplitAcrossParentAndChild(): void
    {
        $counts = $this->facet(TransactionFacet::CATEGORY, new TransactionFilters(states: self::DEFAULT_SCOPE), rollUp: true);

        // Food covers t1 (Groceries), t2 (Groceries and Restaurants, once), t5 (Food) and t6 (Groceries).
        self::assertCounts([F::FOOD => 4, F::GROCERIES => 3, F::RESTAURANTS => 1, F::TRANSPORT => 1], $counts);
    }

    public function testASplitTransactionCountsOncePerCategoryAndEachAxisOncePerTransaction(): void
    {
        $this->add('00000000-0000-7000-8000-00000000e009', [
            [F::GROCERIES, '-10.00', [AnalyticAxis::ESSENTIAL]],
            [F::RESTAURANTS, '-10.00', [AnalyticAxis::ESSENTIAL, AnalyticAxis::FIXED]],
        ], '-20.00');
        $scope = new TransactionFilters(states: self::DEFAULT_SCOPE);

        self::assertCounts(
            [F::GROCERIES => 4, F::RESTAURANTS => 2, F::TRANSPORT => 1, F::FOOD => 1],
            $this->facet(TransactionFacet::CATEGORY, $scope),
        );
        self::assertCounts([F::FOOD => 5, F::GROCERIES => 4, F::RESTAURANTS => 2, F::TRANSPORT => 1], $this->facet(TransactionFacet::CATEGORY, $scope, rollUp: true));
        // Two splits carrying ESSENTIAL still make one transaction.
        self::assertCounts(['ESSENTIAL' => 5, 'FIXED' => 1, 'VARIABLE' => 2], $this->facet(TransactionFacet::AXIS, $scope));
    }

    public function testFiltersNarrowEverySectionLikeTheSearch(): void
    {
        $outflows = new TransactionFilters(states: self::DEFAULT_SCOPE, direction: TransactionDirection::OUTFLOW);
        self::assertCounts([F::CURRENT => 3, F::SAVINGS => 1], $this->facet(TransactionFacet::ACCOUNT, $outflows));
        self::assertCounts(['EXPENSE' => 4], $this->facet(TransactionFacet::NATURE, $outflows));

        $oneAccount = new TransactionFilters(states: self::DEFAULT_SCOPE, accountIds: [F::CURRENT], direction: TransactionDirection::OUTFLOW);
        self::assertCounts(['EXPENSE' => 3], $this->facet(TransactionFacet::NATURE, $oneAccount));

        $inGroceries = new TransactionFilters(states: self::DEFAULT_SCOPE, categoryIds: [F::GROCERIES]);
        self::assertCounts([F::CURRENT => 2, F::SAVINGS => 1], $this->facet(TransactionFacet::ACCOUNT, $inGroceries));
        self::assertCounts(['EXPENSE' => 2, 'REFUND' => 1], $this->facet(TransactionFacet::NATURE, $inGroceries));
        self::assertCounts(['ESSENTIAL' => 3, 'VARIABLE' => 1], $this->facet(TransactionFacet::AXIS, $inGroceries));

        $variable = new TransactionFilters(states: self::DEFAULT_SCOPE, axes: [AnalyticAxis::VARIABLE]);
        self::assertCounts([F::GROCERIES => 1, F::RESTAURANTS => 1, F::TRANSPORT => 1], $this->facet(TransactionFacet::CATEGORY, $variable));

        $january = new TransactionFilters(states: self::DEFAULT_SCOPE, from: new \DateTimeImmutable('2026-01-10'), to: new \DateTimeImmutable('2026-01-20'));
        self::assertCounts([F::CURRENT => 3], $this->facet(TransactionFacet::ACCOUNT, $january));

        $manual = new TransactionFilters(states: self::DEFAULT_SCOPE, sources: [TransactionSource::MANUAL], natures: [TransactionNature::EXPENSE, TransactionNature::REFUND]);
        self::assertCounts([F::CURRENT => 1, F::SAVINGS => 2], $this->facet(TransactionFacet::ACCOUNT, $manual));
    }

    public function testTheToCategoriseQueueKeepsOnlyUnsplitTransactions(): void
    {
        $queue = new TransactionFilters(states: self::DEFAULT_SCOPE, categorizationNone: true);

        self::assertCounts([F::CURRENT => 1], $this->facet(TransactionFacet::ACCOUNT, $queue));
        self::assertCounts([], $this->facet(TransactionFacet::AXIS, $queue));
        self::assertCounts(
            ['BOOKED' => 1, 'REJECTED' => 1],
            $this->facet(TransactionFacet::STATE, new TransactionFilters(states: [TransactionState::PENDING, TransactionState::BOOKED, TransactionState::REJECTED], categorizationNone: true)),
        );
    }

    public function testVoidedTransactionsAreCountedOnlyWhenTheStateScopeAsksForThem(): void
    {
        self::assertCounts([F::CURRENT => 4, F::SAVINGS => 2], $this->facet(TransactionFacet::ACCOUNT, new TransactionFilters(states: self::DEFAULT_SCOPE)));
        self::assertCounts(
            [F::CURRENT => 5, F::SAVINGS => 2],
            $this->facet(TransactionFacet::ACCOUNT, new TransactionFilters(states: [...self::DEFAULT_SCOPE, TransactionState::VOIDED])),
        );
    }

    public function testAmountBoundsAndSearchAreHonouredEvenAtTheLimitsOfTheStorageScale(): void
    {
        $huge = str_repeat('9', 26).'.'.str_repeat('9', 24);
        $this->add('00000000-0000-7000-8000-00000000e00a', [[F::TRANSPORT, '-'.$huge, [AnalyticAxis::FIXED]]], '-'.$huge);
        $bounded = new TransactionFilters(
            states: self::DEFAULT_SCOPE, minAmount: DecimalValue::fromString('99999999999999999999999999'),
            maxAmount: DecimalValue::fromString($huge), assetCode: AssetCode::fromString('EUR'),
        );

        self::assertCounts([F::CURRENT => 1], $this->facet(TransactionFacet::ACCOUNT, $bounded));
        self::assertCounts(['FIXED' => 1], $this->facet(TransactionFacet::AXIS, $bounded));
        self::assertCounts([F::TRANSPORT => 1], $this->facet(TransactionFacet::CATEGORY, $bounded));

        $byAmountText = new TransactionFilters(states: self::DEFAULT_SCOPE, q: '1000', qAmount: DecimalValue::fromString('1000'));
        self::assertCounts([F::CURRENT => 1], $this->facet(TransactionFacet::ACCOUNT, $byAmountText));
    }

    public function testAWorkspaceHoldingNothingCountsNothingInEverySection(): void
    {
        $empty = WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a9');

        $scopes = array_map(static fn (TransactionFacet $facet): TransactionFacetScope => new TransactionFacetScope($facet, new TransactionFilters(states: self::EVERY_STATE)), TransactionFacet::cases());

        self::assertSame([], array_filter($this->repository->facetCounts($empty, $scopes)));
    }

    public function testNothingOfAnotherWorkspaceReachesAnyCountInEitherDirection(): void
    {
        $scope = new TransactionFilters(states: self::EVERY_STATE);

        $foreign = [
            TransactionFacet::ACCOUNT->value => [F::FOREIGN_ACCOUNT => 3],
            TransactionFacet::CATEGORY->value => [F::FOREIGN_CATEGORY => 2],
            TransactionFacet::NATURE->value => ['EXPENSE' => 2, 'INCOME' => 1],
            TransactionFacet::STATE->value => ['PENDING' => 1, 'BOOKED' => 2],
            TransactionFacet::SOURCE->value => ['MANUAL' => 1, 'IMPORT' => 2],
            TransactionFacet::AXIS->value => ['ESSENTIAL' => 2, 'VARIABLE' => 1],
        ];
        $otherCounts = $this->repository->facetCounts(
            WorkspaceFixture::other(),
            array_map(static fn (TransactionFacet $facet): TransactionFacetScope => new TransactionFacetScope($facet, $scope), TransactionFacet::cases()),
        );
        foreach (TransactionFacet::cases() as $facet) {
            self::assertCounts($foreign[$facet->value], $otherCounts[$facet->value] ?? [], 'the other workspace, '.$facet->value);
        }

        // Naming the other workspace's identifiers can never make them match here.
        $named = new TransactionFilters(states: self::EVERY_STATE, accountIds: [F::FOREIGN_ACCOUNT], categoryIds: [F::FOREIGN_CATEGORY]);
        foreach (TransactionFacet::cases() as $facet) {
            self::assertSame([], $this->facet($facet, $named), $facet->value);
        }
        self::assertArrayNotHasKey(F::FOREIGN_ACCOUNT, $this->facet(TransactionFacet::ACCOUNT, $scope));
        self::assertArrayNotHasKey(F::FOREIGN_CATEGORY, $this->facet(TransactionFacet::CATEGORY, $scope));
        self::assertArrayNotHasKey(F::FOREIGN_CATEGORY, $this->facet(TransactionFacet::CATEGORY, $scope, rollUp: true));
    }

    public function testCountsAreIntegersKeyedByString(): void
    {
        foreach (TransactionFacet::cases() as $facet) {
            $counts = $this->facet($facet, new TransactionFilters(states: self::EVERY_STATE));

            // A numeric string would turn into an integer key, and a count read as text would be a string.
            self::assertSame(array_map(strval(...), array_keys($counts)), array_keys($counts), $facet->value);
            self::assertSame(array_map(intval(...), $counts), $counts, $facet->value);
            self::assertSame($counts, array_filter($counts, static fn (int $count): bool => $count > 0), $facet->value);
        }
    }

    public function testAnAxisListedTwiceInOneSplitStillCountsItsTransactionOnce(): void
    {
        $id = '00000000-0000-7000-8000-00000000e00b';
        $this->add($id, [[F::TRANSPORT, '-20.00', [AnalyticAxis::ESSENTIAL]]], '-20.00');
        // The domain refuses it and the table's CHECK (containment) does not: a row written around the domain.
        $this->connection->executeStatement(
            "UPDATE transaction_splits SET analytic_axes = '[\"ESSENTIAL\", \"ESSENTIAL\"]'::jsonb WHERE transaction_id = :id",
            ['id' => $id],
        );
        $scope = new TransactionFilters(states: self::DEFAULT_SCOPE);

        $counted = $this->facet(TransactionFacet::AXIS, $scope);

        // Four transactions of the fixture carry ESSENTIAL; this one makes five, as the list's own filter counts it.
        self::assertSame(5, $counted['ESSENTIAL']);
        self::assertSame(
            $this->repository->countMatching(WorkspaceFixture::own(), new TransactionFilters(states: self::DEFAULT_SCOPE, axes: [AnalyticAxis::ESSENTIAL])),
            $counted['ESSENTIAL'],
        );
    }

    public function testSectionsCountedUnderTheSameFiltersGiveTheSameCountsAsWhenCountedApartAndShareTheirStatements(): void
    {
        $filters = new TransactionFilters(states: self::DEFAULT_SCOPE, direction: TransactionDirection::OUTFLOW);
        $scopes = array_map(static fn (TransactionFacet $facet): TransactionFacetScope => new TransactionFacetScope($facet, $filters), TransactionFacet::cases());
        $statements = new class extends AbstractLogger {
            public int $executed = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if (str_starts_with((string) $message, 'Executing')) {
                    ++$this->executed;
                }
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($statements)]);
        $counting = new DbalTransactionRepository(DriverManager::getConnection($this->connection->getParams(), $configuration));

        $together = $counting->facetCounts(WorkspaceFixture::own(), $scopes);

        foreach (TransactionFacet::cases() as $facet) {
            self::assertSame($this->facet($facet, $filters), $together[$facet->value] ?? [], $facet->value);
        }
        // The four columns of the transaction share one statement, and the categories and the axes
        // share the pass over the splits: six sections, three statements (columns, splits, axis duplicates).
        self::assertLessThanOrEqual(3, $statements->executed);

        // Selecting an axis separates its own scope from the category scope. The columns and
        // categories can still share one scan, without counting a split transaction twice in a column.
        $statements->executed = 0;
        $withAxis = array_map(static fn (TransactionFacet $facet): TransactionFacetScope => new TransactionFacetScope(
            $facet,
            new TransactionFilters(states: self::DEFAULT_SCOPE, axes: TransactionFacet::AXIS === $facet ? [] : [AnalyticAxis::ESSENTIAL]),
        ), TransactionFacet::cases());
        $together = $counting->facetCounts(WorkspaceFixture::own(), $withAxis);
        foreach ($withAxis as $scope) {
            self::assertSame($this->facet($scope->facet, $scope->filters), $together[$scope->facet->value] ?? [], $scope->facet->value);
        }
        self::assertLessThanOrEqual(3, $statements->executed);
    }

    public function testSectionsCountedUnderDifferentFiltersEachKeepTheirOwn(): void
    {
        $scopes = [
            new TransactionFacetScope(TransactionFacet::ACCOUNT, new TransactionFilters(states: self::DEFAULT_SCOPE, natures: [TransactionNature::EXPENSE])),
            new TransactionFacetScope(TransactionFacet::NATURE, new TransactionFilters(states: self::DEFAULT_SCOPE, accountIds: [F::CURRENT])),
            new TransactionFacetScope(TransactionFacet::SOURCE, new TransactionFilters(states: self::DEFAULT_SCOPE, accountIds: [F::CURRENT])),
            new TransactionFacetScope(TransactionFacet::AXIS, new TransactionFilters(states: self::DEFAULT_SCOPE, categoryIds: [F::GROCERIES])),
            new TransactionFacetScope(TransactionFacet::CATEGORY, new TransactionFilters(states: self::DEFAULT_SCOPE, axes: [AnalyticAxis::VARIABLE])),
        ];

        $counts = $this->repository->facetCounts(WorkspaceFixture::own(), $scopes);

        self::assertSame([], array_diff(array_keys($counts), ['accountId', 'nature', 'source', 'axis', 'categoryId']));
        self::assertCounts([F::CURRENT => 3, F::SAVINGS => 1], $counts['accountId']);
        self::assertCounts(['EXPENSE' => 3, 'INCOME' => 1], $counts['nature']);
        self::assertCounts(['IMPORT' => 3, 'MANUAL' => 1], $counts['source']);
        self::assertCounts(['ESSENTIAL' => 3, 'VARIABLE' => 1], $counts['axis']);
        self::assertCounts([F::GROCERIES => 1, F::RESTAURANTS => 1, F::TRANSPORT => 1], $counts['categoryId']);
    }

    public function testTheFourColumnsAreTalliedByOneStatementWhateverTheirOwnFilters(): void
    {
        $scopes = [
            new TransactionFacetScope(TransactionFacet::ACCOUNT, new TransactionFilters(states: self::DEFAULT_SCOPE, natures: [TransactionNature::EXPENSE])),
            new TransactionFacetScope(TransactionFacet::NATURE, new TransactionFilters(states: self::DEFAULT_SCOPE, accountIds: [F::CURRENT])),
            new TransactionFacetScope(TransactionFacet::STATE, new TransactionFilters(states: self::EVERY_STATE, accountIds: [F::CURRENT], natures: [TransactionNature::EXPENSE])),
            new TransactionFacetScope(TransactionFacet::SOURCE, new TransactionFilters(states: [TransactionState::BOOKED], accountIds: [F::CURRENT])),
        ];
        $statements = new class extends AbstractLogger {
            public int $executed = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if (str_starts_with((string) $message, 'Executing')) {
                    ++$this->executed;
                }
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($statements)]);
        $counting = new DbalTransactionRepository(DriverManager::getConnection($this->connection->getParams(), $configuration));

        $counts = $counting->facetCounts(WorkspaceFixture::own(), $scopes);

        self::assertSame(1, $statements->executed);
        self::assertCounts([F::CURRENT => 3, F::SAVINGS => 1], $counts['accountId']);
        self::assertCounts(['EXPENSE' => 3, 'INCOME' => 1], $counts['nature']);
        // Voided t7 counts here: the state section lists every state, with its own account and nature filters.
        self::assertCounts(['BOOKED' => 2, 'PENDING' => 1, 'VOIDED' => 1], $counts['state']);
        self::assertCounts(['IMPORT' => 2, 'MANUAL' => 1], $counts['source']);
        // Each of these is what the same section answers when counted alone.
        foreach ($scopes as $scope) {
            self::assertSame($this->facet($scope->facet, $scope->filters), $counts[$scope->facet->value], $scope->facet->value);
        }
    }

    public function testNoSectionRequestedCountsNothing(): void
    {
        self::assertSame([], $this->repository->facetCounts(WorkspaceFixture::own(), []));
    }

    /**
     * @param array<string, int> $expected
     * @param array<string, int> $actual
     */
    private static function assertCounts(array $expected, array $actual, string $message = ''): void
    {
        ksort($expected, SORT_STRING);
        self::assertSame($expected, $actual, $message);
    }

    /** @return array<string, int> */
    private function facet(TransactionFacet $facet, TransactionFilters $filters, bool $rollUp = false): array
    {
        $counts = $this->repository->facetCounts(WorkspaceFixture::own(), [new TransactionFacetScope($facet, $filters)], $this->subtrees($rollUp));

        return $counts[$facet->value] ?? [];
    }

    private function subtrees(bool $rollUp): ?CategorySubtrees
    {
        return $rollUp ? CategorySubtrees::of((new DbalCategoryRepository($this->connection))->list(WorkspaceFixture::own(), true, 500, 0)) : null;
    }

    /** @param list<array{string, string, list<AnalyticAxis>}> $splits */
    private function add(string $id, array $splits, string $amount): void
    {
        (new F($this->connection))->transaction(
            WorkspaceFixture::OWN_WORKSPACE, $id, F::CURRENT, $amount, TransactionNature::EXPENSE,
            TransactionSource::MANUAL, TransactionState::BOOKED, '2026-01-22', $splits,
        );
    }
}
