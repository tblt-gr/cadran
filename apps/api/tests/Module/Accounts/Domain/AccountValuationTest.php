<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshots;
use App\Module\Accounts\Domain\AccountValuation;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Accounts\Domain\ValuationQuality;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\TestCase;

/**
 * The latest valid snapshot on or before a requested date, with age and
 * quality. A missing valuation stays null: a zero would invent a figure the
 * holder never recorded.
 *
 * Example checked by hand, EUR, requested 2026-09-05:
 *
 * | asOf       | source    | value      | recordedAt          |
 * | 2026-09-01 | MANUAL    | 22950.00   | 2026-09-01T10:00:00 |
 * | 2026-09-03 | IMPORT    | 230.5688   | 2026-09-03T08:00:00 |
 * | 2026-09-03 | MANUAL    | 231.10     | 2026-09-03T18:00:00 |
 * | 2026-09-07 | MANUAL    | 240.00     | 2026-09-07T09:00:00 |
 *
 * Latest asOf ≤ 2026-09-05 is 2026-09-03. On that day MANUAL was recorded
 * later than IMPORT, so the answer is 231.10, age 2 days, quality STALE.
 */
final class AccountValuationTest extends TestCase
{
    public function testAMissingValuationIsNullNeverZero(): void
    {
        $valuation = AccountValuation::of(new AccountBalanceSnapshots([]), $this->day('2026-09-05'));

        self::assertNull($valuation->amount);
        self::assertSame(ValuationQuality::MISSING, $valuation->quality);
        self::assertNull($valuation->ageDays);
        self::assertNull($valuation->source);
    }

    public function testTheLatestAsOfOnOrBeforeTheRequestedDateWins(): void
    {
        $valuation = AccountValuation::of($this->example(), $this->day('2026-09-05'));

        self::assertSame('231.10', $valuation->amount?->value->toString());
        self::assertSame(BalanceSnapshotSource::MANUAL, $valuation->source);
        self::assertSame(2, $valuation->ageDays);
        self::assertSame(ValuationQuality::STALE, $valuation->quality);
        self::assertSame('2026-09-03', $valuation->asOf->format('Y-m-d'));
    }

    public function testASnapshotOnTheRequestedDateIsCurrent(): void
    {
        $valuation = AccountValuation::of($this->example(), $this->day('2026-09-03'));

        self::assertSame('231.10', $valuation->amount?->value->toString());
        self::assertSame(0, $valuation->ageDays);
        self::assertSame(ValuationQuality::CURRENT, $valuation->quality);
    }

    public function testALaterSnapshotIsIgnoredUntilItsDate(): void
    {
        $valuation = AccountValuation::of($this->example(), $this->day('2026-09-07'));

        self::assertSame('240.00', $valuation->amount?->value->toString());
        self::assertSame(0, $valuation->ageDays);
        self::assertSame(ValuationQuality::CURRENT, $valuation->quality);
    }

    public function testASupersededSnapshotDoesNotAnswer(): void
    {
        $first = $this->snapshot('00000000-0000-7000-8000-0000000000b1', '2026-09-03', '22950.00', BalanceSnapshotSource::MANUAL, '2026-09-03T10:00:00+00:00');
        $second = $this->snapshot('00000000-0000-7000-8000-0000000000b2', '2026-09-03', '231.10', BalanceSnapshotSource::MANUAL, '2026-09-03T18:00:00+00:00');
        $snapshots = new AccountBalanceSnapshots([$first->supersededBy($second), $second]);

        $valuation = AccountValuation::of($snapshots, $this->day('2026-09-03'));

        self::assertSame('231.10', $valuation->amount?->value->toString());
    }

    public function testZeroIsACurrentValueNotAMissingOne(): void
    {
        $snapshots = new AccountBalanceSnapshots([
            $this->snapshot('00000000-0000-7000-8000-0000000000b1', '2026-09-05', '0', BalanceSnapshotSource::MANUAL, '2026-09-05T10:00:00+00:00'),
        ]);

        $valuation = AccountValuation::of($snapshots, $this->day('2026-09-05'));

        self::assertSame('0', $valuation->amount?->value->toString());
        self::assertSame(ValuationQuality::CURRENT, $valuation->quality);
    }

    private function example(): AccountBalanceSnapshots
    {
        return new AccountBalanceSnapshots([
            $this->snapshot('00000000-0000-7000-8000-0000000000b1', '2026-09-01', '22950.00', BalanceSnapshotSource::MANUAL, '2026-09-01T10:00:00+00:00'),
            $this->snapshot('00000000-0000-7000-8000-0000000000b2', '2026-09-03', '230.5688', BalanceSnapshotSource::IMPORT, '2026-09-03T08:00:00+00:00'),
            $this->snapshot('00000000-0000-7000-8000-0000000000b3', '2026-09-03', '231.10', BalanceSnapshotSource::MANUAL, '2026-09-03T18:00:00+00:00'),
            $this->snapshot('00000000-0000-7000-8000-0000000000b4', '2026-09-07', '240.00', BalanceSnapshotSource::MANUAL, '2026-09-07T09:00:00+00:00'),
        ]);
    }

    private function snapshot(
        string $id,
        string $asOf,
        string $amount,
        BalanceSnapshotSource $source,
        string $recordedAt,
    ): AccountBalanceSnapshot {
        return new AccountBalanceSnapshot(
            id: $id,
            workspace: WorkspaceScope::fromString(AccountFixture::WORKSPACE),
            accountId: AccountFixture::ID,
            asOf: $this->day($asOf),
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            source: $source,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable($recordedAt),
            recordedBy: '00000000-0000-7000-8000-000000000001',
        );
    }

    private function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }
}
