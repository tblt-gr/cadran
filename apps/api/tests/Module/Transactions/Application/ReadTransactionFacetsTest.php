<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\LiquidityLevel;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\ListTransactionsQuery;
use App\Module\Transactions\Application\ReadTransactionFacets;
use App\Module\Transactions\Application\ResolveTransactionFilters;
use App\Module\Transactions\Application\TransactionFacets;
use App\Module\Transactions\Application\TransactionFacetScopeTooLarge;
use App\Module\Transactions\Application\TransactionFilterAccountNotFound;
use App\Module\Transactions\Domain\CategorySubtrees;
use App\Module\Transactions\Domain\TransactionFacet;
use App\Module\Transactions\Domain\TransactionFacetScope;
use App\Module\Transactions\Domain\TransactionFilters;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Categories\Application\Double\InMemoryCategoryRepository;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

/**
 * Which filters each section of the filter column is counted under: all of them but its own.
 */
final class ReadTransactionFacetsTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-00000000a001';
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-00000000a0b1';
    private const string FOOD = '00000000-0000-7000-8000-00000000c001';
    private const string GROCERIES = '00000000-0000-7000-8000-00000000c002';
    private const string ORGANIC = '00000000-0000-7000-8000-00000000c003';
    private const string TRANSPORT = '00000000-0000-7000-8000-00000000c004';

    /** @var array<string, TransactionFilters> */
    private array $filtersByFacet = [];
    private ?CategorySubtrees $subtrees = null;
    private int $calls = 0;

    public function testEachSectionIsCountedUnderEveryFilterButItsOwn(): void
    {
        $query = new ListTransactionsQuery(
            from: '2026-01-05', to: '2026-01-25', accountIds: [self::ACCOUNT], states: ['BOOKED'], natures: ['EXPENSE'],
            categoryIds: [self::TRANSPORT], axes: ['VARIABLE'], minAmount: '10', maxAmount: '50', assetCode: 'EUR',
            sources: ['IMPORT'], categorizationNone: false, q: 'cafe', includeVoided: false, direction: 'OUTFLOW',
        );

        $facets = $this->read($query);

        $accounts = $this->filters(TransactionFacet::ACCOUNT);
        self::assertSame([], $accounts->accountIds);
        self::assertSame([TransactionNature::EXPENSE], $accounts->natures);
        self::assertSame([self::TRANSPORT], $accounts->categoryIds);
        self::assertSame([AnalyticAxis::VARIABLE], $accounts->axes);
        self::assertSame([TransactionSource::IMPORT], $accounts->sources);
        self::assertSame([TransactionState::BOOKED], $accounts->states);
        self::assertSame('2026-01-05', $accounts->from?->format('Y-m-d'));
        self::assertSame('2026-01-25', $accounts->to?->format('Y-m-d'));
        self::assertSame('10', $accounts->minAmount?->toString());
        self::assertSame('50', $accounts->maxAmount?->toString());
        self::assertSame('cafe', $accounts->q);
        self::assertSame('OUTFLOW', $accounts->direction?->value);

        self::assertSame([], $this->filters(TransactionFacet::CATEGORY)->categoryIds);
        self::assertSame([AnalyticAxis::VARIABLE], $this->filters(TransactionFacet::CATEGORY)->axes);
        self::assertSame([], $this->filters(TransactionFacet::NATURE)->natures);
        self::assertSame([self::ACCOUNT], $this->filters(TransactionFacet::NATURE)->accountIds);
        self::assertSame([], $this->filters(TransactionFacet::SOURCE)->sources);
        self::assertSame([TransactionNature::EXPENSE], $this->filters(TransactionFacet::SOURCE)->natures);
        self::assertSame([], $this->filters(TransactionFacet::AXIS)->axes);
        self::assertSame([self::TRANSPORT], $this->filters(TransactionFacet::AXIS)->categoryIds);

        foreach (TransactionFacet::cases() as $facet) {
            self::assertSame([], $facets->of($facet), $facet->value);
        }
    }

    public function testTheSectionsAreNamedAfterTheFiltersTheyDrive(): void
    {
        self::assertSame(
            ['accountId', 'categoryId', 'nature', 'state', 'source', 'axis'],
            array_map(static fn (TransactionFacet $facet): string => $facet->value, TransactionFacet::cases()),
        );
    }

    public function testTheStateSectionCountsEachOfTheFourStatesWhateverTheStateFilterSays(): void
    {
        $this->read(new ListTransactionsQuery(states: ['BOOKED']));

        self::assertSame(
            [TransactionState::PENDING, TransactionState::BOOKED, TransactionState::VOIDED, TransactionState::REJECTED],
            $this->filters(TransactionFacet::STATE)->states,
        );
        self::assertSame([TransactionState::BOOKED], $this->filters(TransactionFacet::ACCOUNT)->states);
    }

    public function testEveryOtherSectionKeepsTheListsDefaultStateScope(): void
    {
        $this->read(new ListTransactionsQuery());
        foreach ([TransactionFacet::ACCOUNT, TransactionFacet::CATEGORY, TransactionFacet::NATURE, TransactionFacet::SOURCE, TransactionFacet::AXIS] as $facet) {
            self::assertSame([TransactionState::PENDING, TransactionState::BOOKED], $this->filters($facet)->states, $facet->value);
        }

        $this->filtersByFacet = [];
        $this->read(new ListTransactionsQuery(includeVoided: true));
        self::assertSame([TransactionState::PENDING, TransactionState::BOOKED, TransactionState::VOIDED], $this->filters(TransactionFacet::ACCOUNT)->states);
    }

    public function testTheToCategoriseQueueNeverCountsVoidedAndOnlyBindsTheSectionsItDoesNotClear(): void
    {
        $this->read(new ListTransactionsQuery(categorizationNone: true));

        self::assertSame(
            [TransactionState::PENDING, TransactionState::BOOKED, TransactionState::REJECTED],
            $this->filters(TransactionFacet::STATE)->states,
        );
        foreach ([TransactionFacet::ACCOUNT, TransactionFacet::NATURE, TransactionFacet::SOURCE, TransactionFacet::STATE] as $facet) {
            self::assertTrue($this->filters($facet)->categorizationNone, $facet->value);
        }
        // Checking a category or an axis leaves the queue in the interface, so they ignore it.
        self::assertFalse($this->filters(TransactionFacet::CATEGORY)->categorizationNone);
        self::assertFalse($this->filters(TransactionFacet::AXIS)->categorizationNone);
    }

    public function testASectionWhoseStateScopeIsEmptyIsAnEmptyMapAndIsNotQueried(): void
    {
        // VOIDED asked for inside the to-categorise queue: a voided movement is never in the
        // queue, so the sections that keep the queue and the state filter match nothing.
        $facets = $this->read(
            new ListTransactionsQuery(states: ['VOIDED'], categorizationNone: true),
            ['accountId' => [self::ACCOUNT => 9], 'state' => ['REJECTED' => 1], 'axis' => ['ESSENTIAL' => 2]],
        );

        self::assertEqualsCanonicalizing(['categoryId', 'state', 'axis'], array_keys($this->filtersByFacet));
        self::assertSame([], $facets->of(TransactionFacet::ACCOUNT));
        self::assertSame([], $facets->of(TransactionFacet::NATURE));
        self::assertSame([], $facets->of(TransactionFacet::SOURCE));
        self::assertSame(['REJECTED' => 1], $facets->of(TransactionFacet::STATE));
        self::assertSame(['ESSENTIAL' => 2], $facets->of(TransactionFacet::AXIS));
    }

    public function testTheCountsOfEachSectionAreReturnedUnderTheirOwnFacet(): void
    {
        $facets = $this->read(new ListTransactionsQuery(), [
            'accountId' => [self::ACCOUNT => 4],
            'categoryId' => [self::FOOD => 2],
            'nature' => ['EXPENSE' => 3],
            'state' => ['BOOKED' => 5],
            'source' => ['MANUAL' => 1],
            'axis' => ['ESSENTIAL' => 6],
        ]);

        self::assertSame([self::ACCOUNT => 4], $facets->of(TransactionFacet::ACCOUNT));
        self::assertSame([self::FOOD => 2], $facets->of(TransactionFacet::CATEGORY));
        self::assertSame(['EXPENSE' => 3], $facets->of(TransactionFacet::NATURE));
        self::assertSame(['BOOKED' => 5], $facets->of(TransactionFacet::STATE));
        self::assertSame(['MANUAL' => 1], $facets->of(TransactionFacet::SOURCE));
        self::assertSame(['ESSENTIAL' => 6], $facets->of(TransactionFacet::AXIS));
    }

    public function testAnAccountOfNoneOfTheCallersWorkspacesIsRefusedBeforeAnythingIsCounted(): void
    {
        foreach ([self::FOREIGN_ACCOUNT, '00000000-0000-7000-8000-00000000aaaa'] as $unreachable) {
            try {
                $this->read(new ListTransactionsQuery(accountIds: [self::ACCOUNT, $unreachable]));
                self::fail('The account '.$unreachable.' belongs to no account of this workspace.');
            } catch (TransactionFilterAccountNotFound) {
                self::assertSame([], $this->filtersByFacet);
            }
        }
    }

    public function testAMalformedFilterIsInvalidInputRatherThanAnEmptyCount(): void
    {
        $this->expectException(InvalidTransactionInput::class);

        $this->read(new ListTransactionsQuery(from: '2026-02-01', to: '2026-01-01'));
    }

    public function testWithSubcategoriesTheTreeIsReadOnceAndOnlyTheCategorySectionRollsUp(): void
    {
        $categories = $this->createMock(CategoryRepository::class);
        $categories->expects(self::once())->method('list')->willReturn($this->forest());
        $categories->expects(self::never())->method('descendants');

        $this->read(new ListTransactionsQuery(categoryIds: [self::GROCERIES], includeDescendants: true), categories: $categories);

        foreach ([TransactionFacet::ACCOUNT, TransactionFacet::NATURE, TransactionFacet::STATE, TransactionFacet::SOURCE, TransactionFacet::AXIS] as $facet) {
            self::assertSame([self::GROCERIES, self::ORGANIC], $this->filters($facet)->categoryIds, $facet->value);
        }
        self::assertSame([], $this->filters(TransactionFacet::CATEGORY)->categoryIds);
        self::assertInstanceOf(CategorySubtrees::class, $this->subtrees);
        self::assertSame([self::FOOD, self::GROCERIES, self::ORGANIC, self::TRANSPORT], array_values(array_unique($this->subtrees->pairs()[0])));
        self::assertSame([self::GROCERIES, self::ORGANIC], $this->subtrees->expand([self::GROCERIES]));
    }

    public function testEverySectionIsAskedOfTheRepositoryInOneCall(): void
    {
        // So that it can serve the sections counted under the same filters with shared statements.
        $this->read(new ListTransactionsQuery());

        self::assertSame(1, $this->calls);
        self::assertCount(count(TransactionFacet::cases()), $this->filtersByFacet);
    }

    public function testWithoutSubcategoriesCategoriesAreCountedDirectAndTheTreeIsNeverRead(): void
    {
        $categories = $this->createMock(CategoryRepository::class);
        $categories->expects(self::never())->method('list');
        $categories->expects(self::never())->method('descendants');

        $this->read(new ListTransactionsQuery(categoryIds: [self::FOOD]), categories: $categories);

        self::assertNull($this->subtrees);
        self::assertSame([self::FOOD], $this->filters(TransactionFacet::ACCOUNT)->categoryIds);
    }

    public function testATreeBeyondTheBoundIsRefusedInsteadOfBuildingAnUnboundedRollUp(): void
    {
        $categories = $this->createMock(CategoryRepository::class);
        $categories->expects(self::once())->method('list')
            ->with(self::anything(), true, ReadTransactionFacets::MAX_CATEGORIES + 1, 0)
            ->willReturn(array_fill(0, ReadTransactionFacets::MAX_CATEGORIES + 1, $this->forest()[0]));

        $this->expectException(TransactionFacetScopeTooLarge::class);

        $this->read(new ListTransactionsQuery(includeDescendants: true), categories: $categories);
    }

    /**
     * @param array<string, array<string, int>> $countsByFacet
     */
    private function read(ListTransactionsQuery $query, array $countsByFacet = [], ?CategoryRepository $categories = null): TransactionFacets
    {
        $transactions = $this->createStub(TransactionRepository::class);
        $transactions->method('facetCounts')->willReturnCallback(
            function (WorkspaceScope $workspace, array $scopes, ?CategorySubtrees $subtrees = null) use ($countsByFacet): array {
                self::assertSame(WorkspaceFixture::OWN_WORKSPACE, $workspace->id);
                ++$this->calls;
                $this->subtrees = $subtrees;
                $counts = [];
                foreach ($scopes as $scope) {
                    self::assertInstanceOf(TransactionFacetScope::class, $scope);
                    $this->filtersByFacet[$scope->facet->value] = $scope->filters;
                    $counts[$scope->facet->value] = $countsByFacet[$scope->facet->value] ?? [];
                }

                return $counts;
            },
        );
        $categories ??= new InMemoryCategoryRepository();
        $use = new ReadTransactionFacets(
            new FixedCallerWorkspace(WorkspaceFixture::OWN_WORKSPACE),
            $transactions,
            new ResolveTransactionFilters($categories),
            new InMemoryAccountRepository($this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE), $this->account(self::FOREIGN_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE)),
            $categories,
        );

        return $use($query);
    }

    private function filters(TransactionFacet $facet): TransactionFilters
    {
        return $this->filtersByFacet[$facet->value] ?? self::fail('The section '.$facet->value.' was never counted.');
    }

    /** @return list<Category> */
    private function forest(): array
    {
        $at = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $category = static fn (string $id, ?string $parentId): Category => new Category(
            $id, WorkspaceFixture::own(), CategoryType::EXPENSE, 'Category '.substr($id, -4), $parentId, null, null, [], true, 0, null === $parentId ? 1 : 2, 1, $at, $at,
        );

        return [$category(self::FOOD, null), $category(self::GROCERIES, self::FOOD), $category(self::ORGANIC, self::GROCERIES), $category(self::TRANSPORT, null)];
    }

    private function account(string $id, string $workspace): Account
    {
        $at = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Account(
            id: $id, workspace: WorkspaceScope::fromString($workspace), label: 'Compte', assetCode: AssetCode::fromString('EUR'),
            kind: AccountKind::CURRENT, productCode: null, productModelId: null, institution: null, maskedIdentifier: null,
            valuationMode: AccountValuationMode::TRANSACTIONS, liquidityLevel: LiquidityLevel::IMMEDIATE,
            includeInNetWorth: true, includeInEmergencyFund: false, openedOn: $at, closedOn: null,
            version: 1, createdAt: $at, updatedAt: $at,
        );
    }
}
