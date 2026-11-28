<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Transactions\Application\IdempotencyRequest;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentExecutionResult;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Domain\IdempotencyKey;
use App\Module\Transactions\Domain\IdempotencyKeyRepository;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class IdempotentExecutionTest extends TestCase
{
    /** A write that touched no month, such as a recurrence confirmation, stays replayable after a closing. */
    public function testAReplayAfterAClosingReturnsTheStoredResponseAndWritesNothing(): void
    {
        $result = $this->replay([]);

        self::assertSame(['id' => 'stored'], $result->body);
        self::assertSame(201, $result->status);
        self::assertTrue($result->replayed);
    }

    public function testAReplayOfADatedWriteIsRefusedOnceItsMonthIsClosed(): void
    {
        $this->expectException(PeriodClosed::class);

        $this->replay(['2026-03-14']);
    }

    /** @param list<string> $periodDays */
    private function replay(array $periodDays): IdempotentExecutionResult
    {
        $keys = $this->createMock(IdempotencyKeyRepository::class);
        $keys->method('begin')->willReturn(new IdempotencyKey('key-id', 'print', 'COMPLETED', 201, ['id' => 'stored'], false, $periodDays));
        $keys->expects(self::never())->method('complete');
        $closures = $this->createStub(PeriodClosureRepository::class);
        $closures->method('closedAmong')->willReturn([CalendarMonth::containing(new \DateTimeImmutable('2026-03-14'))]);
        $closures->method('hasActive')->willReturn(true);
        $caller = $this->createStub(CallerWorkspaceContext::class);
        $caller->method('resolveContext')->willReturn(new WorkspaceContext(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID));
        $boundary = new class implements TransactionBoundary {
            public function transactional(\Closure $callback): mixed
            {
                return $callback();
            }
        };

        return (new IdempotentExecution($caller, $keys, $boundary, new MockClock(), new AssertPeriodOpen($closures)))->execute(
            'recurrence.create',
            new IdempotencyRequest('replayed-key-0000001', 'print'),
            static fn (): IdempotentResponse => self::fail('A replay never runs the operation again.'),
        );
    }
}
