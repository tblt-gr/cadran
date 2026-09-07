<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountArchived;
use App\Module\Accounts\Application\AccountBalanceConflict;
use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\InvalidAccountBalanceInput;
use App\Module\Accounts\Application\RecordAccountBalance;
use App\Module\Accounts\Application\RecordAccountBalanceInput;
use App\Module\Accounts\Application\StaleAccountVersion;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Application\InvalidAmountInput;
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

/**
 * Recording a manual observed balance: the source literal is kept, the author
 * is the signed-in caller, and a second active snapshot of the same day is
 * refused unless the current version is presented.
 */
final class RecordAccountBalanceTest extends TestCase
{
    private const string NOW = '2026-09-05 09:00:00';

    private CollectingAuditEventRepository $trail;

    protected function setUp(): void
    {
        $this->trail = new CollectingAuditEventRepository();
    }

    public function testAManualSnapshotKeepsTheSourceLiteralAndIsAttributedToTheCaller(): void
    {
        $snapshot = ($this->record())(AccountFixture::ID, $this->input());

        self::assertSame(AccountFixture::ID, $snapshot->accountId);
        self::assertSame('230.5688', $snapshot->amount->value->toString());
        self::assertSame(BalanceSnapshotSource::MANUAL, $snapshot->source);
        self::assertSame('00000000-0000-7000-8000-000000000001', $snapshot->recordedBy);
        self::assertSame('2026-09-03', $snapshot->asOf->format('Y-m-d'));
        self::assertTrue($snapshot->isActive());
    }

    public function testRecordingLeavesAnAuditEventThatNamesNoAmountOrComment(): void
    {
        $snapshot = ($this->record())(AccountFixture::ID, $this->input(comment: 'Bank statement 3 Sept'));

        self::assertCount(1, $this->trail->events);
        $event = $this->trail->events[0];
        self::assertSame('account_balance_snapshot.recorded', $event->eventType);
        self::assertSame($snapshot->id, $event->entityId);

        $serialised = json_encode($event->diff, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('230.5688', $serialised);
        self::assertStringNotContainsString('Bank statement', $serialised);
        self::assertStringContainsString('MANUAL', $serialised);
    }

    public function testRecordingMarksTheAccountAsUsed(): void
    {
        $accounts = new InMemoryAccountRepository(AccountFixture::account());
        ($this->record(accounts: $accounts))(AccountFixture::ID, $this->input());

        $stored = $accounts->find(\App\Module\Foundation\Domain\WorkspaceScope::fromString(AccountFixture::WORKSPACE), AccountFixture::ID);
        self::assertNotNull($stored?->usedAt);
    }

    public function testASecondActiveSnapshotOfTheSameDayRequiresTheCurrentVersion(): void
    {
        $snapshots = new InMemoryAccountBalanceSnapshotRepository();
        $record = $this->record(snapshots: $snapshots);
        $first = $record(AccountFixture::ID, $this->input());

        $this->expectException(AccountBalanceConflict::class);

        $record(AccountFixture::ID, $this->input(amount: '240.00'));
        self::assertSame(1, $first->version);
    }

    public function testAReplacementSupersedesThePreviousActiveSnapshot(): void
    {
        $snapshots = new InMemoryAccountBalanceSnapshotRepository();
        $record = $this->record(snapshots: $snapshots);
        $first = $record(AccountFixture::ID, $this->input());
        $second = $record(AccountFixture::ID, $this->input(amount: '240.00', version: $first->version));

        $history = $snapshots->findForAccount(
            \App\Module\Foundation\Domain\WorkspaceScope::fromString(AccountFixture::WORKSPACE),
            AccountFixture::ID,
        );

        self::assertFalse($history->snapshots[0]->isActive());
        self::assertSame('230.5688', $history->snapshots[0]->amount->value->toString());
        self::assertTrue($second->isActive());
        self::assertSame('240.00', $second->amount->value->toString());
        self::assertCount(3, $this->trail->events);
        self::assertSame('account_balance_snapshot.superseded', $this->trail->events[1]->eventType);
        self::assertSame('account_balance_snapshot.recorded', $this->trail->events[2]->eventType);
    }

    public function testAStaleVersionIsRefused(): void
    {
        $snapshots = new InMemoryAccountBalanceSnapshotRepository();
        $record = $this->record(snapshots: $snapshots);
        $record(AccountFixture::ID, $this->input());

        $this->expectException(StaleAccountVersion::class);

        $record(AccountFixture::ID, $this->input(amount: '240.00', version: 99));
    }

    public function testAFigureDeeperThanTheAssetStorageScaleIsRefused(): void
    {
        $this->expectException(InvalidAmountInput::class);
        $this->expectExceptionMessage('validation rule');

        ($this->record())(AccountFixture::ID, $this->input(amount: '1.123456789'));
    }

    public function testAnAmountInAnotherUnitThanTheAccountIsRefused(): void
    {
        $this->expectException(InvalidAccountBalanceInput::class);
        $this->expectExceptionMessage('account unit');

        ($this->record())(AccountFixture::ID, $this->input(assetCode: 'USD'));
    }

    public function testAForeignAccountIsNotFound(): void
    {
        $this->expectException(AccountNotFound::class);

        ($this->record(caller: '00000000-0000-7000-8000-0000000000a2'))(AccountFixture::ID, $this->input());
    }

    public function testAnArchivedAccountAcceptsNoSnapshot(): void
    {
        $account = AccountFixture::account();
        $archived = $account->archive(new \DateTimeImmutable(self::NOW));

        $this->expectException(AccountArchived::class);

        ($this->record(account: $archived))(AccountFixture::ID, $this->input());
    }

    public function testASnapshotCannotPredateTheAccountOpening(): void
    {
        $this->expectException(InvalidAccountBalanceInput::class);
        $this->expectExceptionMessage('predate');

        ($this->record())(AccountFixture::ID, $this->input(asOf: '2026-01-09'));
    }

    public function testASnapshotCannotPostdateTheAccountClosing(): void
    {
        $account = AccountFixture::account();
        $closed = $account->reconfigure(
            label: $account->label,
            kind: $account->kind,
            productCode: $account->productCode,
            productModelId: $account->productModelId,
            institution: $account->institution,
            maskedIdentifier: $account->maskedIdentifier,
            valuationMode: $account->valuationMode,
            liquidityLevel: $account->liquidityLevel,
            includeInNetWorth: $account->includeInNetWorth,
            includeInEmergencyFund: $account->includeInEmergencyFund,
            openedOn: $account->openedOn,
            closedOn: new \DateTimeImmutable('2026-02-01', new \DateTimeZone('UTC')),
            updatedAt: new \DateTimeImmutable(self::NOW),
        );

        $this->expectException(InvalidAccountBalanceInput::class);
        $this->expectExceptionMessage('postdate');

        ($this->record(account: $closed))(AccountFixture::ID, $this->input(asOf: '2026-02-02'));
    }

    private function input(
        string $amount = '230.5688',
        string $assetCode = 'EUR',
        ?string $comment = null,
        ?int $version = null,
        string $asOf = '2026-09-03',
    ): RecordAccountBalanceInput {
        return new RecordAccountBalanceInput(
            asOf: $asOf,
            amount: $amount,
            amountAssetCode: $assetCode,
            comment: $comment,
            version: $version,
        );
    }

    private function record(
        ?Account $account = null,
        ?InMemoryAccountRepository $accounts = null,
        ?InMemoryAccountBalanceSnapshotRepository $snapshots = null,
        string $caller = AccountFixture::WORKSPACE,
    ): RecordAccountBalance {
        return new RecordAccountBalance(
            new FixedCallerWorkspace($caller),
            $accounts ?? new InMemoryAccountRepository($account ?? AccountFixture::account()),
            $snapshots ?? new InMemoryAccountBalanceSnapshotRepository(),
            new AmountInputParser(InMemoryAssetCatalog::withCodes('EUR', 'USD')),
            new SequenceUuidGenerator(),
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock(self::NOW, 'UTC'),
        );
    }
}
