<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

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
}
