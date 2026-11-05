<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Accounts\Infrastructure\Persistence\DbalAccountRepository;
use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Infrastructure\Persistence\DbalCategoryRepository;
use App\Module\Transactions\Application\CountTransactions;
use App\Module\Transactions\Application\ListTransactionsQuery;
use App\Module\Transactions\Application\ReadTransactionFacets;
use App\Module\Transactions\Application\ResolveTransactionFilters;
use App\Module\Transactions\Domain\TransactionFacet;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Support\TransactionFacetFixture as F;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The property that makes the facet counts trustworthy: the number beside an option is the
 * number the list returns with that option checked alone in its section. It is checked against
 * {@see CountTransactions}, the count of the very same filter resolution as the list, for a
 * spread of filters, for every option of every section and for the options nothing holds.
 */
final class TransactionFacetParityTest extends KernelTestCase
{
    private const string ORGANIC = '00000000-0000-7000-8000-00000000c005';

    private WorkspaceFixture $fixture;
    private ReadTransactionFacets $facets;
    private CountTransactions $count;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $this->seedWiderData(new F($connection));

        $caller = new FixedCallerWorkspace(WorkspaceFixture::OWN_WORKSPACE);
        $transactions = new DbalTransactionRepository($connection);
        $categories = new DbalCategoryRepository($connection);
        $resolve = new ResolveTransactionFilters($categories);
        $this->facets = new ReadTransactionFacets($caller, $transactions, $resolve, new DbalAccountRepository($connection), $categories);
        $this->count = new CountTransactions($caller, $transactions, $resolve);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    /** @return iterable<string, array{ListTransactionsQuery}> */
    public static function filterSpread(): iterable
    {
        yield 'no filter' => [new ListTransactionsQuery()];
        yield 'voided shown' => [new ListTransactionsQuery(includeVoided: true)];
        yield 'outflows' => [new ListTransactionsQuery(direction: 'OUTFLOW')];
        yield 'inflows in a period' => [new ListTransactionsQuery(from: '2026-01-10', to: '2026-02-16', direction: 'INFLOW')];
        yield 'a single day period' => [new ListTransactionsQuery(from: '2026-01-10', to: '2026-01-10')];
        yield 'amount bounds' => [new ListTransactionsQuery(minAmount: '10', maxAmount: '100', assetCode: 'EUR')];
        yield 'an amount below a cent bound' => [new ListTransactionsQuery(minAmount: '0', maxAmount: '0.01', assetCode: 'EUR')];
        yield 'a text query' => [new ListTransactionsQuery(q: 'cb e00')];
        yield 'an accented text query' => [new ListTransactionsQuery(q: 'CAFÉ')];
        yield 'a query read as an amount' => [new ListTransactionsQuery(q: '50')];
        yield 'to categorise' => [new ListTransactionsQuery(categorizationNone: true)];
        yield 'to categorise, voided shown' => [new ListTransactionsQuery(categorizationNone: true, includeVoided: true)];
        yield 'one account' => [new ListTransactionsQuery(accountIds: [F::CURRENT])];
        yield 'two accounts and a category' => [new ListTransactionsQuery(accountIds: [F::CURRENT, F::SAVINGS], categoryIds: [F::GROCERIES])];
        yield 'a parent category with subcategories' => [new ListTransactionsQuery(categoryIds: [F::FOOD], includeDescendants: true)];
        yield 'a parent category alone' => [new ListTransactionsQuery(categoryIds: [F::FOOD])];
        yield 'subcategories without a category' => [new ListTransactionsQuery(includeDescendants: true)];
        yield 'natures and sources' => [new ListTransactionsQuery(natures: ['EXPENSE', 'REFUND'], sources: ['MANUAL'])];
        yield 'an axis under a parent category' => [new ListTransactionsQuery(axes: ['ESSENTIAL'], categoryIds: [F::FOOD], includeDescendants: true, direction: 'OUTFLOW')];
        yield 'several axes match any member' => [new ListTransactionsQuery(axes: ['ESSENTIAL', 'DISCRETIONARY'])];
        yield 'an axis repeated in the request' => [new ListTransactionsQuery(axes: ['ESSENTIAL', 'ESSENTIAL'])];
        yield 'an explicit state' => [new ListTransactionsQuery(states: ['REJECTED'])];
        yield 'two explicit states' => [new ListTransactionsQuery(states: ['PENDING', 'VOIDED'])];
        yield 'voided inside the queue' => [new ListTransactionsQuery(states: ['VOIDED'], categorizationNone: true)];
        yield 'everything at once' => [new ListTransactionsQuery(
            from: '2026-01-01', to: '2026-02-28', accountIds: [F::CURRENT], natures: ['EXPENSE'], categoryIds: [F::FOOD],
            includeDescendants: true, axes: ['ESSENTIAL', 'FIXED'], sources: ['MANUAL', 'IMPORT', 'PROVIDER'], includeVoided: true, direction: 'OUTFLOW',
        )];
        yield 'a foreign category id' => [new ListTransactionsQuery(categoryIds: [F::FOREIGN_CATEGORY], includeDescendants: true)];
    }

    #[DataProvider('filterSpread')]
    public function testEveryOptionCountsWhatTheListReturnsWithThatOptionCheckedAlone(ListTransactionsQuery $query): void
    {
        $facets = ($this->facets)($query);

        $universe = [
            TransactionFacet::ACCOUNT->value => [F::CURRENT, F::SAVINGS, F::FOREIGN_ACCOUNT],
            TransactionFacet::CATEGORY->value => [F::FOOD, F::GROCERIES, F::RESTAURANTS, self::ORGANIC, F::TRANSPORT, F::FOREIGN_CATEGORY],
            TransactionFacet::NATURE->value => array_map(static fn (TransactionNature $nature): string => $nature->value, TransactionNature::cases()),
            TransactionFacet::STATE->value => array_map(static fn (TransactionState $state): string => $state->value, TransactionState::cases()),
            TransactionFacet::SOURCE->value => array_map(static fn (TransactionSource $source): string => $source->value, TransactionSource::cases()),
            TransactionFacet::AXIS->value => array_map(static fn (AnalyticAxis $axis): string => $axis->value, AnalyticAxis::cases()),
        ];

        foreach (TransactionFacet::cases() as $facet) {
            $counts = $facets->of($facet);
            self::assertSame([], array_diff(array_keys($counts), $universe[$facet->value]), 'a value no option stands for in '.$facet->value);
            foreach ($universe[$facet->value] as $value) {
                self::assertSame(
                    $this->oracle($query, $facet, $value),
                    $counts[$value] ?? 0,
                    sprintf('%s %s', $facet->value, $value),
                );
            }
        }
    }

    public function testTheOracleItselfFindsSomethingForMostOptions(): void
    {
        // A parity check over empty answers only would pass vacuously.
        $facets = ($this->facets)(new ListTransactionsQuery());

        self::assertGreaterThan(8, array_sum(array_map(static fn (TransactionFacet $facet): int => count($facets->of($facet)), TransactionFacet::cases())));
        self::assertSame(
            $this->oracle(new ListTransactionsQuery(), TransactionFacet::CATEGORY, F::GROCERIES),
            $facets->of(TransactionFacet::CATEGORY)[F::GROCERIES],
        );
    }

    /**
     * The count the list gives once the option is checked alone in its section, written out
     * independently of the use case: which parameters a section drops is the contract.
     */
    private function oracle(ListTransactionsQuery $query, TransactionFacet $facet, string $value): int
    {
        $a = $query->accountIds;
        $s = $query->states;
        $n = $query->natures;
        $c = $query->categoryIds;
        $x = $query->axes;
        $o = $query->sources;
        $q = $query->categorizationNone;

        return ($this->count)(match ($facet) {
            TransactionFacet::ACCOUNT => self::copy($query, [$value], $s, $n, $c, $x, $o, $q),
            TransactionFacet::CATEGORY => self::copy($query, $a, $s, $n, [$value], $x, $o, false),
            TransactionFacet::NATURE => self::copy($query, $a, $s, [$value], $c, $x, $o, $q),
            TransactionFacet::STATE => self::copy($query, $a, [$value], $n, $c, $x, $o, $q),
            TransactionFacet::SOURCE => self::copy($query, $a, $s, $n, $c, $x, [$value], $q),
            TransactionFacet::AXIS => self::copy($query, $a, $s, $n, $c, [$value], $o, false),
        });
    }

    /**
     * @param list<string> $accountIds
     * @param list<string> $states
     * @param list<string> $natures
     * @param list<string> $categoryIds
     * @param list<string> $axes
     * @param list<string> $sources
     */
    private static function copy(ListTransactionsQuery $query, array $accountIds, array $states, array $natures, array $categoryIds, array $axes, array $sources, bool $queue): ListTransactionsQuery
    {
        return new ListTransactionsQuery(
            from: $query->from, to: $query->to, accountIds: $accountIds, states: $states, natures: $natures,
            categoryIds: $categoryIds, includeDescendants: $query->includeDescendants, axes: $axes,
            minAmount: $query->minAmount, maxAmount: $query->maxAmount, assetCode: $query->assetCode, sources: $sources,
            categorizationNone: $queue, q: $query->q, includeVoided: $query->includeVoided, direction: $query->direction,
        );
    }

    /** More months, a third level of categories, every nature and source, labels and tiny and large amounts. */
    private function seedWiderData(F $fixture): void
    {
        $fixture->seed();
        $own = WorkspaceFixture::OWN_WORKSPACE;
        $fixture->category(self::ORGANIC, $own, 'Organic', F::GROCERIES, 3);

        $fixture->transaction($own, '00000000-0000-7000-8000-00000000e009', F::CURRENT, '-80.00', TransactionNature::EXPENSE, TransactionSource::PROVIDER, TransactionState::BOOKED, '2026-02-03', [
            [self::ORGANIC, '-50.00', [AnalyticAxis::FIXED]],
            [F::GROCERIES, '-30.00', [AnalyticAxis::ESSENTIAL, AnalyticAxis::FIXED]],
        ]);
        $fixture->transaction($own, '00000000-0000-7000-8000-00000000e00a', F::SAVINGS, '-12.50', TransactionNature::EXPENSE, TransactionSource::RECURRENCE, TransactionState::PENDING, '2026-02-10', [
            [F::RESTAURANTS, '-12.50', [AnalyticAxis::DISCRETIONARY]],
        ], 'Café du coin');
        $fixture->transaction($own, '00000000-0000-7000-8000-00000000e00b', F::CURRENT, '2500.00', TransactionNature::INCOME, TransactionSource::MANUAL, TransactionState::BOOKED, '2026-02-15', []);
        $fixture->transaction($own, '00000000-0000-7000-8000-00000000e00c', F::SAVINGS, '-100.00', TransactionNature::TRANSFER, TransactionSource::MANUAL, TransactionState::BOOKED, '2026-02-18', []);
        $fixture->transaction($own, '00000000-0000-7000-8000-00000000e00d', F::CURRENT, '-0.01', TransactionNature::ADJUSTMENT, TransactionSource::IMPORT, TransactionState::BOOKED, '2026-02-20', []);
        $fixture->transaction($own, '00000000-0000-7000-8000-00000000e00e', F::CURRENT, '-50.00', TransactionNature::EXPENSE, TransactionSource::IMPORT, TransactionState::VOIDED, '2026-02-22', [
            [F::FOOD, '-50.00', [AnalyticAxis::PROFESSIONAL]],
        ]);
        // Four splits: several earlier splits carry the same axis, and three of the categories share an ancestor.
        $fixture->transaction($own, '00000000-0000-7000-8000-00000000e00f', F::SAVINGS, '-40.00', TransactionNature::EXPENSE, TransactionSource::MANUAL, TransactionState::BOOKED, '2026-02-24', [
            [F::GROCERIES, '-10.00', [AnalyticAxis::ESSENTIAL]],
            [F::RESTAURANTS, '-10.00', [AnalyticAxis::ESSENTIAL]],
            [F::FOOD, '-10.00', [AnalyticAxis::ESSENTIAL, AnalyticAxis::VARIABLE]],
            [F::TRANSPORT, '-10.00', [AnalyticAxis::VARIABLE]],
        ]);
    }
}
