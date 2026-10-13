<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Transactions\Application\ReadTransactionSummaries;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class ReadTransactionSummariesTest extends TestCase
{
    public function testItRejectsMoreThanTheMonthlySourceBoundBeforeReadingPersistence(): void
    {
        $transactions = $this->createMock(TransactionRepository::class);
        $transactions->expects(self::never())->method('findMany');
        $read = new ReadTransactionSummaries($transactions);
        $ids = array_map(
            static fn (int $index): string => sprintf('00000000-0000-7000-8000-%012d', $index),
            range(0, ReadTransactionSummaries::MAX_TRANSACTIONS),
        );

        $this->expectException(\InvalidArgumentException::class);
        $read(WorkspaceFixture::own(), $ids);
    }

    public function testItAcceptsAWholeBusiestMonthOfSourceIdentifiers(): void
    {
        // The identifiers of a month's KPI sources can be as many as the month's transactions (2000).
        self::assertSame(2_000, ReadTransactionSummaries::MAX_TRANSACTIONS);
        $ids = array_map(
            static fn (int $index): string => sprintf('00000000-0000-7000-8000-%012d', $index),
            range(1, 2_000),
        );
        $transactions = $this->createMock(TransactionRepository::class);
        $transactions->expects(self::once())->method('findMany')->with(WorkspaceFixture::own(), $ids)->willReturn([]);

        self::assertSame([], (new ReadTransactionSummaries($transactions))(WorkspaceFixture::own(), $ids));
    }
}
