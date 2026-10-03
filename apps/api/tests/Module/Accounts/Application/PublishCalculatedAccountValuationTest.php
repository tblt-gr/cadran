<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\PublishCalculatedAccountValuation;
use App\Module\Accounts\Application\WithdrawCalculatedAccountValuation;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** The net-worth figure a portfolio valuation publishes: one active CALCULATED snapshot per account and day. */
final class PublishCalculatedAccountValuationTest extends TestCase
{
    private const string NOW = '2026-09-05 09:00:00';

    private CollectingAuditEventRepository $trail;
    private InMemoryAccountBalanceSnapshotRepository $snapshots;

    protected function setUp(): void
    {
        $this->trail = new CollectingAuditEventRepository();
        $this->snapshots = new InMemoryAccountBalanceSnapshotRepository();
    }

    public function testItWritesAReconciledCalculatedSnapshotOfTheTotal(): void
    {
        ($this->publish())($this->workspace(), AccountFixture::ID, self::day('2026-08-31'), self::amount('3664.701'));

        $active = $this->snapshots->findActive($this->workspace(), AccountFixture::ID, self::day('2026-08-31'), BalanceSnapshotSource::CALCULATED);
        self::assertNotNull($active);
        self::assertSame('3664.701', $active->amount->value->toString());
        self::assertSame(ReconciliationStatus::RECONCILED, $active->reconciliationStatus);
        self::assertSame(['account_balance_snapshot.recorded'], array_map(static fn ($event): string => $event->eventType, $this->trail->events));
    }

    public function testASecondPublicationSupersedesTheFirstLeavingOneActiveSnapshot(): void
    {
        $publish = $this->publish();
        $publish($this->workspace(), AccountFixture::ID, self::day('2026-08-31'), self::amount('3664.701'));
        $publish($this->workspace(), AccountFixture::ID, self::day('2026-08-31'), self::amount('3700.00'));

        $history = $this->snapshots->findForAccount($this->workspace(), AccountFixture::ID)->snapshots;
        self::assertCount(2, $history);
        self::assertCount(1, array_filter($history, static fn ($snapshot): bool => $snapshot->isActive()));
        self::assertSame('3700.00', $this->snapshots->findActive($this->workspace(), AccountFixture::ID, self::day('2026-08-31'), BalanceSnapshotSource::CALCULATED)?->amount->value->toString());
        self::assertSame(
            ['account_balance_snapshot.recorded', 'account_balance_snapshot.superseded', 'account_balance_snapshot.recorded'],
            array_map(static fn ($event): string => $event->eventType, $this->trail->events),
        );
    }

    public function testAManualSnapshotOfTheSameDayIsLeftAlone(): void
    {
        ($this->publish())($this->workspace(), AccountFixture::ID, self::day('2026-08-31'), self::amount('1.00'));

        self::assertNull($this->snapshots->findActive($this->workspace(), AccountFixture::ID, self::day('2026-08-31'), BalanceSnapshotSource::MANUAL));
    }

    public function testWithdrawingSupersedesTheActiveSnapshotWithoutReplacement(): void
    {
        ($this->publish())($this->workspace(), AccountFixture::ID, self::day('2026-08-31'), self::amount('3664.701'));

        $withdrawn = ($this->withdraw())($this->workspace(), AccountFixture::ID, self::day('2026-08-31'));

        self::assertTrue($withdrawn);
        self::assertNull($this->snapshots->findActive($this->workspace(), AccountFixture::ID, self::day('2026-08-31'), BalanceSnapshotSource::CALCULATED));
        self::assertSame('account_balance_snapshot.superseded', $this->trail->events[1]->eventType);
    }

    public function testWithdrawingWhenNothingIsPublishedIsANoOp(): void
    {
        self::assertFalse(($this->withdraw())($this->workspace(), AccountFixture::ID, self::day('2026-08-31')));
        self::assertSame([], $this->trail->events);
    }

    public function testAValuationDatedTomorrowInUtcBecauseOfTheWorkspaceTimezoneIsPublishedAndWithdrawn(): void
    {
        $date = self::day('2026-09-06');

        $publish = $this->publish();
        $publish($this->workspace(), AccountFixture::ID, $date, self::amount('10.00'));
        $publish($this->workspace(), AccountFixture::ID, $date, self::amount('11.00'));

        self::assertSame('11.00', $this->snapshots->findActive($this->workspace(), AccountFixture::ID, $date, BalanceSnapshotSource::CALCULATED)?->amount->value->toString());
        self::assertTrue(($this->withdraw())($this->workspace(), AccountFixture::ID, $date));
        self::assertNull($this->snapshots->findActive($this->workspace(), AccountFixture::ID, $date, BalanceSnapshotSource::CALCULATED));
    }

    public function testAForeignAccountIsNotFound(): void
    {
        $this->expectException(AccountNotFound::class);

        ($this->publish())(WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a2'), AccountFixture::ID, self::day('2026-08-31'), self::amount('1'));
    }

    private function publish(): PublishCalculatedAccountValuation
    {
        return new PublishCalculatedAccountValuation(
            new FixedCallerWorkspace(AccountFixture::WORKSPACE),
            new InMemoryAccountRepository(AccountFixture::account()),
            $this->snapshots,
            new SequenceUuidGenerator(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock(self::NOW, 'UTC'),
        );
    }

    private function withdraw(): WithdrawCalculatedAccountValuation
    {
        return new WithdrawCalculatedAccountValuation(
            new FixedCallerWorkspace(AccountFixture::WORKSPACE),
            $this->snapshots,
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock('2026-09-05 10:00:00', 'UTC'),
        );
    }

    private function workspace(): WorkspaceScope
    {
        return WorkspaceScope::fromString(AccountFixture::WORKSPACE);
    }

    private static function day(string $literal): \DateTimeImmutable
    {
        return new \DateTimeImmutable($literal, new \DateTimeZone('UTC'));
    }

    private static function amount(string $literal): AssetAmount
    {
        return new AssetAmount(DecimalValue::fromString($literal), AssetCode::fromString('EUR'));
    }
}
