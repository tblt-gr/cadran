<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\ListTransactionsQuery;
use App\Module\Transactions\Application\ResolveTransactionFilters;
use App\Module\Transactions\Domain\TransactionDirection;
use App\Tests\Module\Categories\Application\Double\InMemoryCategoryRepository;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResolveTransactionFiltersTest extends TestCase
{
    private ResolveTransactionFilters $resolve;

    protected function setUp(): void
    {
        $this->resolve = new ResolveTransactionFilters(new InMemoryCategoryRepository());
    }

    public function testAbsoluteBoundsAreKeptAsGivenWithTheirAsset(): void
    {
        $filters = ($this->resolve)(WorkspaceFixture::own(), new ListTransactionsQuery(minAmount: '10', maxAmount: '50.00', assetCode: 'EUR'));

        self::assertNotNull($filters);
        self::assertSame('10', $filters->minAmount?->toString());
        self::assertSame('50.00', $filters->maxAmount?->toString());
    }

    public function testAZeroBoundIsAccepted(): void
    {
        $filters = ($this->resolve)(WorkspaceFixture::own(), new ListTransactionsQuery(minAmount: '0', assetCode: 'EUR'));

        self::assertSame('0', $filters?->minAmount?->toString());
    }

    #[DataProvider('invalidBounds')]
    public function testANegativeOrInvertedBoundIsRefused(?string $min, ?string $max): void
    {
        $this->expectException(InvalidTransactionInput::class);

        ($this->resolve)(WorkspaceFixture::own(), new ListTransactionsQuery(minAmount: $min, maxAmount: $max, assetCode: 'EUR'));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function invalidBounds(): iterable
    {
        yield 'negative min' => ['-10', null];
        yield 'negative max' => [null, '-0.01'];
        yield 'min above max' => ['50.01', '50'];
        yield 'huge min above huge max' => ['99999999999999999999999999.5', '99999999999999999999999999.4'];
    }

    public function testMinEqualToMaxIsAccepted(): void
    {
        self::assertNotNull(($this->resolve)(WorkspaceFixture::own(), new ListTransactionsQuery(minAmount: '10', maxAmount: '10.00', assetCode: 'EUR')));
    }

    public function testDirectionIsResolvedToItsEnum(): void
    {
        $filters = ($this->resolve)(WorkspaceFixture::own(), new ListTransactionsQuery(direction: 'OUTFLOW'));

        self::assertSame(TransactionDirection::OUTFLOW, $filters?->direction);
        self::assertNull(($this->resolve)(WorkspaceFixture::own(), new ListTransactionsQuery())?->direction);
    }

    #[DataProvider('amountSearches')]
    public function testASearchThatReadsAsAnAmountIsAlsoSearchedAsAnUnsignedAmount(string $q, string $expected): void
    {
        $filters = ($this->resolve)(WorkspaceFixture::own(), new ListTransactionsQuery(q: $q));

        self::assertNotNull($filters);
        self::assertSame($expected, $filters->qAmount?->toString());
        self::assertSame($q, $filters->q);
    }

    /** @return iterable<string, array{string, string}> */
    public static function amountSearches(): iterable
    {
        yield 'integer' => ['10', '10'];
        yield 'comma decimal with euro' => ['10,50 €', '10.50'];
        yield 'dot decimal' => ['10.5', '10.5'];
        yield 'leading minus ignored' => ['-10', '10'];
        yield 'leading plus ignored' => ['+10,5', '10.5'];
        yield 'spaces and no-break spaces' => ["1\u{00A0}234 ,50\u{00A0}€", '1234.50'];
        yield 'zero' => ['0', '0'];
        yield 'leading zeros dropped' => ['007,5', '7.5'];
        yield 'twenty-six integer digits' => [str_repeat('9', 26), str_repeat('9', 26)];
        yield 'twenty-four fraction digits' => ['1,'.str_repeat('1', 24), '1.'.str_repeat('1', 24)];
    }

    #[DataProvider('textSearches')]
    public function testASearchThatIsNotAnAmountStaysAPlainTextSearch(string $q): void
    {
        $filters = ($this->resolve)(WorkspaceFixture::own(), new ListTransactionsQuery(q: $q));

        self::assertNotNull($filters);
        self::assertNull($filters->qAmount);
        self::assertSame($q, $filters->q);
    }

    /** @return iterable<string, array{string}> */
    public static function textSearches(): iterable
    {
        yield 'letters' => ['abc'];
        yield 'amount with letters' => ['10 EUR'];
        yield 'two commas' => ['1,2,3'];
        yield 'comma and dot' => ['1.234,50'];
        yield 'two signs' => ['--10'];
        yield 'trailing dot' => ['10.'];
        yield 'twenty-seven integer digits' => [str_repeat('9', 27)];
        yield 'twenty-five fraction digits' => ['1,'.str_repeat('1', 25)];
        yield 'two euro signs' => ['10 € €'];
        yield 'only a sign' => ['-'];
        yield 'blank' => [' '];
    }

    public function testNoQueryMeansNoAmountSearch(): void
    {
        self::assertNull(($this->resolve)(WorkspaceFixture::own(), new ListTransactionsQuery())?->qAmount);
    }

    private const string INCOME_ROOT = '00000000-0000-7000-8000-00000000c001';
    private const string INCOME_ARCHIVED = '00000000-0000-7000-8000-00000000c002';
    private const string EXPENSE_FOOD = '00000000-0000-7000-8000-00000000c003';
    private const string EXPENSE_RENT = '00000000-0000-7000-8000-00000000c004';
    private const string FOREIGN_EXPENSE = '00000000-0000-7000-8000-00000000c005';

    private function resolverWithTypedCategories(bool $withExpenses = true): ResolveTransactionFilters
    {
        $at = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $make = static fn (string $id, WorkspaceScope $workspace, CategoryType $type, ?\DateTimeImmutable $archivedAt = null): Category => new Category(
            $id, $workspace, $type, 'Category '.substr($id, -4), null, null, null, [], true, 0, 1, 1, $at, $at, null, $archivedAt,
        );
        $categories = new InMemoryCategoryRepository();
        $categories->add($make(self::INCOME_ROOT, WorkspaceFixture::own(), CategoryType::INCOME));
        $categories->add($make(self::INCOME_ARCHIVED, WorkspaceFixture::own(), CategoryType::INCOME, $at));
        if ($withExpenses) {
            $categories->add($make(self::EXPENSE_FOOD, WorkspaceFixture::own(), CategoryType::EXPENSE));
            $categories->add($make(self::EXPENSE_RENT, WorkspaceFixture::own(), CategoryType::EXPENSE));
        }
        $categories->add($make(self::FOREIGN_EXPENSE, WorkspaceFixture::other(), CategoryType::EXPENSE));

        return new ResolveTransactionFilters($categories);
    }

    public function testACategoryTypeResolvesToEveryCategoryOfThatTypeArchivedOnesIncluded(): void
    {
        $filters = ($this->resolverWithTypedCategories())(WorkspaceFixture::own(), new ListTransactionsQuery(categoryType: 'INCOME'));

        self::assertNotNull($filters);
        self::assertEqualsCanonicalizing([self::INCOME_ROOT, self::INCOME_ARCHIVED], $filters->categoryIds);
    }

    public function testACategoryTypeNeverResolvesToACategoryOfAnotherWorkspace(): void
    {
        $filters = ($this->resolverWithTypedCategories())(WorkspaceFixture::own(), new ListTransactionsQuery(categoryType: 'EXPENSE'));

        self::assertNotNull($filters);
        self::assertEqualsCanonicalizing([self::EXPENSE_FOOD, self::EXPENSE_RENT], $filters->categoryIds);
        self::assertNotContains(self::FOREIGN_EXPENSE, $filters->categoryIds);
    }

    public function testACategoryTypeIsIntersectedWithAnExplicitCategory(): void
    {
        $filters = ($this->resolverWithTypedCategories())(
            WorkspaceFixture::own(),
            new ListTransactionsQuery(categoryIds: [self::EXPENSE_FOOD, self::INCOME_ROOT], categoryType: 'EXPENSE'),
        );

        self::assertSame([self::EXPENSE_FOOD], $filters?->categoryIds);
    }

    public function testADisjointCategoryAndTypeMatchNothingRatherThanEverything(): void
    {
        $resolve = $this->resolverWithTypedCategories();

        self::assertNull($resolve(WorkspaceFixture::own(), new ListTransactionsQuery(categoryIds: [self::INCOME_ROOT], categoryType: 'EXPENSE')));
        self::assertNull($resolve(WorkspaceFixture::own(), new ListTransactionsQuery(categoryIds: [self::FOREIGN_EXPENSE], categoryType: 'EXPENSE')));
    }

    public function testATypeWithoutAnyCategoryMatchesNothingRatherThanEverything(): void
    {
        self::assertNull(($this->resolverWithTypedCategories(false))(WorkspaceFixture::own(), new ListTransactionsQuery(categoryType: 'EXPENSE')));
    }

    public function testAnUnknownCategoryTypeIsRefused(): void
    {
        $this->expectException(InvalidTransactionInput::class);

        ($this->resolverWithTypedCategories())(WorkspaceFixture::own(), new ListTransactionsQuery(categoryType: 'SAVINGS'));
    }

    public function testWithoutACategoryTypeNoCategoryFilterIsAdded(): void
    {
        self::assertSame([], ($this->resolverWithTypedCategories())(WorkspaceFixture::own(), new ListTransactionsQuery())?->categoryIds);
    }
}
