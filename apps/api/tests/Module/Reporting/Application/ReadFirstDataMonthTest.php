<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Application\ReadFirstValuationMonth;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Reporting\Application\ReadFirstDataMonth;
use App\Module\Transactions\Application\ReadFirstTransactionMonth;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class ReadFirstDataMonthTest extends TestCase
{
    public function testAValuationOlderThanAnyTransactionStartsTheReports(): void
    {
        self::assertSame('2025-06', $this->reader('2025-11-03', '2025-06-30')(WorkspaceFixture::own()));
    }

    public function testAWorkspaceWithOnlyValuationsStillHasADataMonth(): void
    {
        self::assertSame('2025-06', $this->reader(null, '2025-06-30')(WorkspaceFixture::own()));
    }

    public function testATransactionOlderThanAnyValuationWins(): void
    {
        self::assertSame('2025-02', $this->reader('2025-02-10', '2025-06-30')(WorkspaceFixture::own()));
    }

    public function testAnEmptyWorkspaceHasNoDataMonth(): void
    {
        self::assertNull($this->reader(null, null)(WorkspaceFixture::own()));
    }

    private function reader(?string $firstTransaction, ?string $firstValuation): ReadFirstDataMonth
    {
        $transactions = $this->createStub(TransactionRepository::class);
        $transactions->method('firstLiveBookedOn')->willReturn(null === $firstTransaction ? null : new \DateTimeImmutable($firstTransaction));
        $snapshots = $this->createStub(AccountBalanceSnapshotRepository::class);
        $snapshots->method('firstActiveValuedOn')->willReturn(null === $firstValuation ? null : new \DateTimeImmutable($firstValuation));

        return new ReadFirstDataMonth(new ReadFirstTransactionMonth($transactions), new ReadFirstValuationMonth($snapshots));
    }
}
