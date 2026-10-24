<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountBalanceConflict;
use App\Module\Accounts\Application\AccountBalanceNotFound;
use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\CorrectAccountBalance;
use App\Module\Accounts\Application\CorrectAccountBalanceInput;
use App\Module\Accounts\Application\InvalidAccountBalanceInput;
use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Accounts\Application\RecordAccountBalance;
use App\Module\Accounts\Application\RecordAccountBalanceInput;
use App\Module\Accounts\Application\StaleAccountVersion;
use App\Module\Accounts\Application\WithdrawAccountBalance;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Accounts\Domain\ReconciliationStatus;
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

final class CorrectAccountBalanceTest extends TestCase
{
    private const string NOW = '2026-09-05 09:00:00';

    private CollectingAuditEventRepository $trail;

    private InMemoryAccountBalanceSnapshotRepository $snapshots;

    protected function setUp(): void
    {
        $this->trail = new CollectingAuditEventRepository();
        $this->snapshots = new InMemoryAccountBalanceSnapshotRepository();
    }

    public function testCorrectingAmendsTheSameRowWithoutCreatingAnother(): void
    {
        $recorded = $this->record();
        $corrected = $this->correct()(AccountFixture::ID, $recorded->id, new CorrectAccountBalanceInput('231.10', 'EUR', 'Relevé corrigé', $recorded->version));

        self::assertSame($recorded->id, $corrected->id);
        self::assertSame($recorded->asOf, $corrected->asOf);
        self::assertSame($recorded->version + 1, $corrected->version);
        self::assertTrue($corrected->isActive());
        self::assertSame('231.10', $corrected->amount->value->toString());
        self::assertSame('Relevé corrigé', $corrected->comment);
        $stored = $this->snapshots->find(WorkspaceScope::fromString(AccountFixture::WORKSPACE), AccountFixture::ID, $recorded->id);
        self::assertSame('231.10', $stored?->amount->value->toString());
        self::assertSame('account_balance_snapshot.corrected', $this->trail->events[1]->eventType);
        $serialised = json_encode($this->trail->events[1]->diff, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('231.10', $serialised);
        self::assertStringNotContainsString('230.5688', $serialised);
    }

    public function testACorrectedFigureIsUnreconciledAgain(): void
    {
        $recorded = $this->record();
        $reconciled = $recorded->markReconciled();
        self::assertTrue($this->snapshots->update($reconciled, $recorded->version));

        $corrected = $this->correct()(AccountFixture::ID, $recorded->id, new CorrectAccountBalanceInput('231.10', 'EUR', null, $reconciled->version));

        self::assertSame(ReconciliationStatus::UNRECONCILED, $corrected->reconciliationStatus);
    }

    public function testAStaleVersionIsRefused(): void
    {
        $recorded = $this->record();

        $this->expectException(StaleAccountVersion::class);

        $this->correct()(AccountFixture::ID, $recorded->id, new CorrectAccountBalanceInput('231.10', 'EUR', null, $recorded->version + 2));
    }

    public function testAWithdrawnSnapshotCannotBeCorrected(): void
    {
        $recorded = $this->record();
        $withdrawn = (new WithdrawAccountBalance(
            new FixedCallerWorkspace(AccountFixture::WORKSPACE),
            new InMemoryAccountRepository(AccountFixture::account()),
            $this->snapshots,
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock(self::NOW, 'UTC'),
            new AssertPeriodOpen($this->createStub(PeriodClosureRepository::class)),
        ))(AccountFixture::ID, $recorded->id, $recorded->version);

        $this->expectException(AccountBalanceConflict::class);

        $this->correct()(AccountFixture::ID, $recorded->id, new CorrectAccountBalanceInput('231.10', 'EUR', null, $withdrawn->version));
    }

    public function testAnUnknownSnapshotIsNotFound(): void
    {
        $this->expectException(AccountBalanceNotFound::class);

        $this->correct()(AccountFixture::ID, '00000000-0000-7000-8000-0000000000aa', new CorrectAccountBalanceInput('1.00', 'EUR', null, 1));
    }

    public function testAnotherUnitIsRefused(): void
    {
        $recorded = $this->record();

        $this->expectException(InvalidAccountBalanceInput::class);

        $this->correct()(AccountFixture::ID, $recorded->id, new CorrectAccountBalanceInput('231.10', 'USD', null, $recorded->version));
    }

    public function testASnapshotInAClosedMonthIsRefused(): void
    {
        $recorded = $this->record();
        $closures = $this->createStub(PeriodClosureRepository::class);
        $closures->method('closedAmong')->willReturn(['2026-09']);

        $this->expectException(PeriodClosed::class);

        $this->correct(new AssertPeriodOpen($closures))(AccountFixture::ID, $recorded->id, new CorrectAccountBalanceInput('231.10', 'EUR', null, $recorded->version));
    }

    private function record(): AccountBalanceSnapshot
    {
        $record = new RecordAccountBalance(
            new FixedCallerWorkspace(AccountFixture::WORKSPACE),
            new InMemoryAccountRepository(AccountFixture::account()),
            $this->snapshots,
            new AmountInputParser(InMemoryAssetCatalog::withCodes('EUR', 'USD')),
            new SequenceUuidGenerator(),
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock(self::NOW, 'UTC'),
            new AssertPeriodOpen($this->createStub(PeriodClosureRepository::class)),
        );

        return $record(AccountFixture::ID, new RecordAccountBalanceInput('2026-09-03', '230.5688', 'EUR', null, null));
    }

    private function correct(?AssertPeriodOpen $assertPeriodOpen = null): CorrectAccountBalance
    {
        return new CorrectAccountBalance(
            new FixedCallerWorkspace(AccountFixture::WORKSPACE),
            new InMemoryAccountRepository(AccountFixture::account()),
            $this->snapshots,
            new AmountInputParser(InMemoryAssetCatalog::withCodes('EUR', 'USD')),
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            $assertPeriodOpen ?? new AssertPeriodOpen($this->createStub(PeriodClosureRepository::class)),
        );
    }
}
