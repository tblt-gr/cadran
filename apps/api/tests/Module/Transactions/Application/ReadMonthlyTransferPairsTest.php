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

        self::assertCount(2_000, $pairs);
        self::assertTrue($workspace->equals($reader->workspace));
        self::assertSame('2026-09-01', $reader->from->format('Y-m-d'));
        self::assertSame('2026-09-30', $reader->to->format('Y-m-d'));
        self::assertSame(2_001, $reader->limit);
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

    public function testSeveralMonthsAreReadOnceAndBucketedByEitherLegWithAnOversizedMonthRefused(): void
    {
        $workspace = WorkspaceScope::fromString('00000000-0000-7000-8000-000000000001');
        $straddling = self::pair('t1', '2026-01-31', '2026-02-01');
        $crowded = array_map(static fn (int $index): MonthlyTransferPairFact => self::pair('c'.$index, '2026-03-10', '2026-03-10'), range(0, ReadMonthlyTransactionFacts::MAX_TRANSACTIONS));
        $reader = new class([$straddling, ...$crowded]) implements MonthlyTransferPairReader {
            public int $reads = 0;

            /** @param list<MonthlyTransferPairFact> $pairs */
            public function __construct(private readonly array $pairs)
            {
            }

            public function read(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
            {
                ++$this->reads;

                return $this->pairs;
            }
        };

        $buckets = (new ReadMonthlyTransferPairs($reader))->byMonth($workspace, [CalendarMonth::fromString('2026-01'), CalendarMonth::fromString('2026-02'), CalendarMonth::fromString('2026-03'), CalendarMonth::fromString('2026-04')]);

        self::assertSame(1, $reader->reads);
        self::assertSame([$straddling], $buckets['2026-01']);
        self::assertSame([$straddling], $buckets['2026-02']);
        self::assertNull($buckets['2026-03']);
        self::assertSame([], $buckets['2026-04']);
    }

    private static function pair(string $id, string $source, string $target): MonthlyTransferPairFact
    {
        return new MonthlyTransferPairFact($id, null, null, null, null, null, null, null, null, null, null, $source, $target, false);
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
