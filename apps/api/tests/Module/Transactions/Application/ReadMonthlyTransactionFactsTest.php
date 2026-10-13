<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\MonthlyTransactionScopeTooLarge;
use App\Module\Transactions\Application\ReadMonthlyTransactionFacts;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionFilters;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionState;
use PHPUnit\Framework\TestCase;

final class ReadMonthlyTransactionFactsTest extends TestCase
{
    public function testAMonthMayHoldUpToTwoThousandTransactionsAndTheReadAsksForOneMore(): void
    {
        self::assertSame(2_000, ReadMonthlyTransactionFacts::MAX_TRANSACTIONS);

        $limit = null;
        $filters = null;
        $repository = $this->createMock(TransactionRepository::class);
        $repository->expects(self::once())->method('search')->willReturnCallback(
            static function (WorkspaceScope $workspace, TransactionFilters $received, int $requested) use (&$limit, &$filters): array {
                $limit = $requested;
                $filters = $received;

                return [];
            },
        );

        $facts = (new ReadMonthlyTransactionFacts($repository))(WorkspaceScope::fromString('00000000-0000-7000-8000-000000000001'), CalendarMonth::fromString('2026-09'));

        self::assertSame(2_001, $limit);
        self::assertSame([], $facts->booked);
        self::assertSame(0, $facts->pendingCount);
        self::assertInstanceOf(TransactionFilters::class, $filters);
        self::assertSame('2026-09-01', $filters->from?->format('Y-m-d'));
        self::assertSame('2026-09-30', $filters->to?->format('Y-m-d'));
        self::assertSame([TransactionState::BOOKED, TransactionState::PENDING], $filters->states);
    }

    public function testAMonthAboveTheBoundIsRefusedRatherThanTruncated(): void
    {
        // The repository was asked for MAX + 1 rows and returned that many: the month holds more than it may read.
        $oneTooMany = array_fill(0, ReadMonthlyTransactionFacts::MAX_TRANSACTIONS + 1, (new \ReflectionClass(Transaction::class))->newInstanceWithoutConstructor());
        $repository = $this->createStub(TransactionRepository::class);
        $repository->method('search')->willReturn($oneTooMany);

        $this->expectException(MonthlyTransactionScopeTooLarge::class);
        $this->expectExceptionMessage('at most 2000 transactions');
        (new ReadMonthlyTransactionFacts($repository))(WorkspaceScope::fromString('00000000-0000-7000-8000-000000000001'), CalendarMonth::fromString('2026-09'));
    }
}
