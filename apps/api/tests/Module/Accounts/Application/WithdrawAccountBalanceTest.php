<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountBalanceConflict;
use App\Module\Accounts\Application\AccountBalanceNotFound;
use App\Module\Accounts\Application\AssertAccountBalanceNonNegative;
use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\BalanceMovementReader;
use App\Module\Accounts\Application\InsufficientAccountBalance;
use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Accounts\Application\RecordAccountBalance;
use App\Module\Accounts\Application\RecordAccountBalanceInput;
use App\Module\Accounts\Application\StaleAccountVersion;
use App\Module\Accounts\Application\WithdrawAccountBalance;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\AlwaysInteractive;
use App\Tests\Module\Accounts\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\FixedMovementsReader;
use App\Tests\Module\Accounts\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Application\Double\NoMovementsReader;
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

    private SequenceUuidGenerator $ids;

    protected function setUp(): void
    {
        $this->trail = new CollectingAuditEventRepository();
        $this->snapshots = new InMemoryAccountBalanceSnapshotRepository();
        $this->ids = new SequenceUuidGenerator();
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

    public function testWithdrawingTheAnchorThatAbsorbedAPendingExpenseIsRefusedOnItsDay(): void
    {
        // 10.00 on 09-01, a pending -12.00 booked on 09-10 inside the observed 5.00 of that day.
        $this->record('2026-08-01', '10.00');
        $later = $this->record('2026-08-10', '5.00');
        $events = count($this->trail->events);

        try {
            $this->withdraw(movements: new FixedMovementsReader(['2026-08-10' => '-12.00']))(AccountFixture::ID, $later->id, $later->version);
            self::fail('The withdrawal must be refused.');
        } catch (InsufficientAccountBalance $refusal) {
            self::assertSame('2026-08-10', $refusal->firstNegativeOn->format('Y-m-d'));
            self::assertSame('amount', $refusal->field);
        }

        self::assertTrue($this->snapshots->find(WorkspaceScope::fromString(AccountFixture::WORKSPACE), AccountFixture::ID, $later->id)?->isActive());
        self::assertCount($events, $this->trail->events);
    }

    public function testAWithdrawalLeavingExactlyZeroIsAccepted(): void
    {
        $this->record('2026-08-01', '10.00');
        $later = $this->record('2026-08-10', '5.00');

        $withdrawn = $this->withdraw(movements: new FixedMovementsReader(['2026-08-10' => '-10.00']))(AccountFixture::ID, $later->id, $later->version);

        self::assertFalse($withdrawn->isActive());
    }

    public function testAnUnchangedLegacyShortfallDoesNotBlockTheWithdrawal(): void
    {
        $this->record('2026-08-01', '10.00');
        $later = $this->record('2026-08-10', '-2.00', legacy: true);

        // The legacy anchor and the derived balance both read -2.00 on 09-10: nothing is deepened.
        $withdrawn = $this->withdraw(movements: new FixedMovementsReader(['2026-08-05' => '-12.00']))(AccountFixture::ID, $later->id, $later->version);

        self::assertFalse($withdrawn->isActive());
    }

    public function testTheWithdrawalDayIsJudgedEvenWithoutAMovementThatDay(): void
    {
        $this->record('2026-08-01', '10.00');
        $later = $this->record('2026-08-10', '5.00');

        $this->expectException(InsufficientAccountBalance::class);

        $this->withdraw(movements: new FixedMovementsReader(['2026-08-03' => '-12.00']))(AccountFixture::ID, $later->id, $later->version);
    }

    public function testRebasingUpToTheNextSurvivingAnchorIsAccepted(): void
    {
        $earlier = $this->record('2026-08-01', '10.00');
        $this->record('2026-08-10', '20.00');

        $withdrawn = $this->withdraw(movements: new FixedMovementsReader(['2026-08-05' => '-15.00']))(AccountFixture::ID, $earlier->id, $earlier->version);

        self::assertFalse($withdrawn->isActive());
    }

    public function testWithdrawingTheSoleAnchorLeavesAnUnknownBalanceAndIsAccepted(): void
    {
        $sole = $this->record('2026-08-01', '10.00');

        $withdrawn = $this->withdraw(movements: new FixedMovementsReader(['2026-08-05' => '-50.00']))(AccountFixture::ID, $sole->id, $sole->version);

        self::assertFalse($withdrawn->isActive());
        self::assertSame('account_balance_snapshot.superseded', $this->trail->events[1]->eventType);
    }

    public function testAnAccountOutsideTheGuardIsNotRead(): void
    {
        $recorded = $this->record();

        $withdrawn = $this->withdraw(account: AccountFixture::account(valuationMode: \App\Module\Accounts\Domain\AccountValuationMode::SNAPSHOTS), movements: new FixedMovementsReader(['2026-08-05' => '-9999.00']))(AccountFixture::ID, $recorded->id, $recorded->version);

        self::assertFalse($withdrawn->isActive());
    }

    private function record(string $asOf = '2026-09-03', string $amount = '230.5688', bool $legacy = false): \App\Module\Accounts\Domain\AccountBalanceSnapshot
    {
        $record = new RecordAccountBalance(
            new FixedCallerWorkspace(AccountFixture::WORKSPACE),
            new InMemoryAccountRepository(AccountFixture::account()),
            $this->snapshots,
            new AmountInputParser(InMemoryAssetCatalog::withCodes('EUR')),
            $this->ids,
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock(self::NOW, 'UTC'),
            new AssertPeriodOpen($this->createStub(PeriodClosureRepository::class)),
            new AssertAccountBalanceNonNegative(new InMemoryAccountRepository(AccountFixture::account()), $this->snapshots, new NoMovementsReader(), $legacy ? new class implements \App\Module\Accounts\Application\InteractiveWriteContext {
                public function isInteractive(): bool
                {
                    return false;
                }
            } : new AlwaysInteractive()),
        );

        return $record(AccountFixture::ID, new RecordAccountBalanceInput($asOf, $amount, 'EUR', null, null));
    }

    private function withdraw(?AssertPeriodOpen $assertPeriodOpen = null, ?BalanceMovementReader $movements = null, ?\App\Module\Accounts\Domain\Account $account = null): WithdrawAccountBalance
    {
        $accounts = new InMemoryAccountRepository($account ?? AccountFixture::account());

        return new WithdrawAccountBalance(
            new FixedCallerWorkspace(AccountFixture::WORKSPACE),
            $accounts,
            $this->snapshots,
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock(self::NOW, 'UTC'),
            $assertPeriodOpen ?? new AssertPeriodOpen($this->createStub(PeriodClosureRepository::class)),
            new AssertAccountBalanceNonNegative($accounts, $this->snapshots, $movements ?? new NoMovementsReader(), new AlwaysInteractive()),
        );
    }
}
