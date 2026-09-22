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
}
