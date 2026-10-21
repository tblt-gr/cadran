<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountBalanceConflict;
use App\Module\Accounts\Application\AccountBalanceNotFound;
use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Accounts\Application\RecordAccountBalance;
use App\Module\Accounts\Application\RecordAccountBalanceInput;
use App\Module\Accounts\Application\StaleAccountVersion;
use App\Module\Accounts\Application\WithdrawAccountBalance;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class WithdrawAccountBalanceTest extends TestCase
{
    private const string NOW = '2026-09-05 09:00:00';

    private CollectingAuditEventRepository $trail;

    private InMemoryAccountBalanceSnapshotRepository $snapshots;

    protected function setUp(): void
    {
        $this->trail = new CollectingAuditEventRepository();
        $this->snapshots = new InMemoryAccountBalanceSnapshotRepository();
    }

    public function testWithdrawingStopsTheSnapshotFromAnsweringAndKeepsTheRow(): void
    {
        $recorded = $this->record();
        $withdrawn = $this->withdraw()(AccountFixture::ID, $recorded->id, $recorded->version);

        self::assertFalse($withdrawn->isActive());
        self::assertSame($recorded->id, $withdrawn->id);
        self::assertSame($recorded->version + 1, $withdrawn->version);
        $stored = $this->snapshots->find(
            WorkspaceScope::fromString(AccountFixture::WORKSPACE),
            AccountFixture::ID,
            $recorded->id,
        );
        self::assertFalse($stored?->isActive());
        self::assertSame('account_balance_snapshot.superseded', $this->trail->events[1]->eventType);
        $serialised = json_encode($this->trail->events[1]->diff, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('230.5688', $serialised);
    }

    public function testASecondWithdrawalIsRefused(): void
    {
        $recorded = $this->record();
        $withdraw = $this->withdraw();
        $withdraw(AccountFixture::ID, $recorded->id, $recorded->version);

        $this->expectException(AccountBalanceConflict::class);

        $withdraw(AccountFixture::ID, $recorded->id, $recorded->version + 1);
    }

    public function testAStaleVersionIsRefused(): void
    {
        $recorded = $this->record();

        $this->expectException(StaleAccountVersion::class);

        $this->withdraw()(AccountFixture::ID, $recorded->id, $recorded->version + 3);
    }

    public function testAnUnknownSnapshotIsNotFound(): void
    {
        $this->expectException(AccountBalanceNotFound::class);

        $this->withdraw()(AccountFixture::ID, '00000000-0000-7000-8000-0000000000aa', 1);
    }

    public function testASnapshotInAClosedMonthIsRefused(): void
    {
        $recorded = $this->record();
        $closures = $this->createStub(PeriodClosureRepository::class);
        $closures->method('closedAmong')->willReturn(['2026-09']);

        $this->expectException(PeriodClosed::class);

        $this->withdraw(new AssertPeriodOpen($closures))(AccountFixture::ID, $recorded->id, $recorded->version);
    }

    private function record(): \App\Module\Accounts\Domain\AccountBalanceSnapshot
    {
        $record = new RecordAccountBalance(
            new FixedCallerWorkspace(AccountFixture::WORKSPACE),
            new InMemoryAccountRepository(AccountFixture::account()),
            $this->snapshots,
            new AmountInputParser(InMemoryAssetCatalog::withCodes('EUR')),
            new SequenceUuidGenerator(),
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock(self::NOW, 'UTC'),
            new AssertPeriodOpen($this->createStub(PeriodClosureRepository::class)),
        );

        return $record(AccountFixture::ID, new RecordAccountBalanceInput('2026-09-03', '230.5688', 'EUR', null, null));
    }

    private function withdraw(?AssertPeriodOpen $assertPeriodOpen = null): WithdrawAccountBalance
    {
        return new WithdrawAccountBalance(
            new FixedCallerWorkspace(AccountFixture::WORKSPACE),
            new InMemoryAccountRepository(AccountFixture::account()),
            $this->snapshots,
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock(self::NOW, 'UTC'),
            $assertPeriodOpen ?? new AssertPeriodOpen($this->createStub(PeriodClosureRepository::class)),
        );
    }
}
