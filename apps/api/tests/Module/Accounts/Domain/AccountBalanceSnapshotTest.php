<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\ConflictingAccountBalanceSnapshot;
use App\Module\Accounts\Domain\InvalidAccountBalanceSnapshot;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A dated observed balance: the figure as submitted, its source, and whether
 * it still answers. Two active snapshots of the same account, day and source
 * would leave "the manual balance on 3 September" with two answers.
 */
final class AccountBalanceSnapshotTest extends TestCase
{
    private const string ID = '00000000-0000-7000-8000-0000000000b1';
    private const string NEXT_ID = '00000000-0000-7000-8000-0000000000b2';
    private const string NOW = '2026-09-03T10:00:00+00:00';

    public function testItKeepsTheSourceLiteralAndNamesItsProvenance(): void
    {
        $snapshot = $this->snapshot();

        self::assertSame('230.5688', $snapshot->amount->value->toString());
        self::assertSame('EUR', $snapshot->amount->asset->toString());
        self::assertSame(BalanceSnapshotSource::MANUAL, $snapshot->source);
        self::assertSame(ReconciliationStatus::UNRECONCILED, $snapshot->reconciliationStatus);
        self::assertTrue($snapshot->isActive());
        self::assertSame('2026-09-03', $snapshot->asOf->format('Y-m-d'));
    }

    public function testAZeroBalanceIsARecordedFigureNotAMissingOne(): void
    {
        $snapshot = $this->snapshot(amount: '0');

        self::assertSame('0', $snapshot->amount->value->toString());
        self::assertTrue($snapshot->isActive());
    }

    public function testAnOverdraftMayBeNegative(): void
    {
        $snapshot = $this->snapshot(amount: '-50.20');

        self::assertSame('-50.20', $snapshot->amount->value->toString());
        self::assertTrue($snapshot->amount->value->isNegative());
    }

    #[DataProvider('invalidComments')]
    public function testItRejectsAnUnboundedOrExecutableComment(string $comment): void
    {
        $this->expectException(InvalidAccountBalanceSnapshot::class);

        $this->snapshot(comment: $comment);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidComments(): iterable
    {
        yield 'empty' => [''];
        yield 'untrimmed' => [' note '];
        yield 'control character' => ["note\n"];
        yield 'too long' => [str_repeat('a', AccountBalanceSnapshot::MAX_COMMENT_LENGTH + 1)];
    }

    public function testASnapshotDatedAfterItWasRecordedIsRefused(): void
    {
        $this->expectException(InvalidAccountBalanceSnapshot::class);
        $this->expectExceptionMessage('future');

        $this->snapshot(asOf: '2026-09-04', recordedAt: self::NOW);
    }

    public function testSupersedingKeepsThePreviousRowAndStopsItAnswering(): void
    {
        $current = $this->snapshot();
        $replacement = $this->snapshot(id: self::NEXT_ID, amount: '240.00', recordedAt: '2026-09-03T11:00:00+00:00');

        $superseded = $current->supersededBy($replacement);

        self::assertFalse($superseded->isActive());
        self::assertSame('2026-09-03T11:00:00+00:00', $superseded->supersededAt?->format('c'));
        self::assertTrue($replacement->isActive());
        self::assertSame('230.5688', $superseded->amount->value->toString());
    }

    public function testTwoActiveSnapshotsOfTheSameAccountDateAndSourceConflict(): void
    {
        $this->expectException(ConflictingAccountBalanceSnapshot::class);

        $this->snapshot()->assertCompatibleWith($this->snapshot(id: self::NEXT_ID, amount: '240.00'));
    }

    public function testADifferentSourceOnTheSameDayDoesNotConflict(): void
    {
        $manual = $this->snapshot();
        $imported = $this->snapshot(id: self::NEXT_ID, source: BalanceSnapshotSource::IMPORT);

        $manual->assertCompatibleWith($imported);
        $this->addToAssertionCount(1);
    }

    public function testASupersededSnapshotDoesNotConflictWithALaterActiveOne(): void
    {
        $previous = $this->snapshot()->supersededBy(
            $this->snapshot(id: self::NEXT_ID, amount: '240.00', recordedAt: '2026-09-03T11:00:00+00:00'),
        );

        $previous->assertCompatibleWith($this->snapshot(id: self::NEXT_ID, amount: '240.00'));
        $this->addToAssertionCount(1);
    }

    public function testMarkingItReconciledKeepsTheFigureAndBumpsTheVersion(): void
    {
        $snapshot = $this->snapshot(amount: '1165.00');

        $reconciled = $snapshot->markReconciled();

        self::assertSame(ReconciliationStatus::RECONCILED, $reconciled->reconciliationStatus);
        self::assertSame(2, $reconciled->version);
        self::assertSame('1165.00', $reconciled->amount->value->toString());
        self::assertSame($snapshot->id, $reconciled->id);
        self::assertSame(ReconciliationStatus::UNRECONCILED, $snapshot->reconciliationStatus);
    }

    public function testAReconciledSnapshotCannotBeReconciledTwice(): void
    {
        $this->expectException(InvalidAccountBalanceSnapshot::class);

        $this->snapshot()->markReconciled()->markReconciled();
    }

    public function testASupersededSnapshotCannotBeReconciled(): void
    {
        $original = $this->snapshot();
        $replacement = $this->snapshot(id: self::NEXT_ID, amount: '10', recordedAt: '2026-09-03T11:00:00+00:00');

        $this->expectException(InvalidAccountBalanceSnapshot::class);

        $original->supersededBy($replacement)->markReconciled();
    }

    private function snapshot(
        string $id = self::ID,
        string $amount = '230.5688',
        string $asOf = '2026-09-03',
        string $recordedAt = self::NOW,
        BalanceSnapshotSource $source = BalanceSnapshotSource::MANUAL,
        ?string $comment = null,
    ): AccountBalanceSnapshot {
        return new AccountBalanceSnapshot(
            id: $id,
            workspace: WorkspaceScope::fromString(AccountFixture::WORKSPACE),
            accountId: AccountFixture::ID,
            asOf: new \DateTimeImmutable($asOf, new \DateTimeZone('UTC')),
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            source: $source,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: $comment,
            version: 1,
            recordedAt: new \DateTimeImmutable($recordedAt),
            recordedBy: '00000000-0000-7000-8000-000000000001',
        );
    }
}
