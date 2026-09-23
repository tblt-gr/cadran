<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\MonthlyTransactionScopeTooLarge;
use App\Module\Transactions\Application\MonthlyTransferPairFact;
use App\Module\Transactions\Application\MonthlyTransferPairReader;
use App\Module\Transactions\Application\ReadMonthlyTransactionFacts;
use App\Module\Transactions\Application\ReadMonthlyTransferPairs;
use PHPUnit\Framework\TestCase;

final class ReadMonthlyTransferPairsTest extends TestCase
{
    public function testItPassesTheWorkspaceMonthAndBoundToTheReadPort(): void
    {
        $workspace = WorkspaceScope::fromString('00000000-0000-7000-8000-000000000001');
        $reader = new RecordingMonthlyTransferPairReader(ReadMonthlyTransactionFacts::MAX_TRANSACTIONS);
        $pairs = (new ReadMonthlyTransferPairs($reader))($workspace, CalendarMonth::fromString('2026-09'));

        self::assertCount(500, $pairs);
        self::assertTrue($workspace->equals($reader->workspace));
        self::assertSame('2026-09-01', $reader->from->format('Y-m-d'));
        self::assertSame('2026-09-30', $reader->to->format('Y-m-d'));
        self::assertSame(501, $reader->limit);
    }

    public function testItRefusesMoreThanTheProjectionTransactionLimit(): void
    {
        $read = new ReadMonthlyTransferPairs(
            new RecordingMonthlyTransferPairReader(ReadMonthlyTransactionFacts::MAX_TRANSACTIONS + 1),
        );

        $this->expectException(MonthlyTransactionScopeTooLarge::class);
        $read(
            WorkspaceScope::fromString('00000000-0000-7000-8000-000000000001'),
            CalendarMonth::fromString('2026-09'),
        );
    }
}

final class RecordingMonthlyTransferPairReader implements MonthlyTransferPairReader
{
    public WorkspaceScope $workspace;
    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;
    public int $limit;

    public function __construct(private readonly int $count)
    {
    }

    public function read(
        WorkspaceScope $workspace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
    ): array {
        $this->workspace = $workspace;
        $this->from = $from;
        $this->to = $to;
        $this->limit = $limit;

        return array_fill(0, $this->count, new MonthlyTransferPairFact(
            'transfer',
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            false,
        ));
    }
}
