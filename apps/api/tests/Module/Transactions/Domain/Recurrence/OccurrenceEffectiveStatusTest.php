<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Recurrence\OccurrenceEffectiveStatus;
use App\Module\Transactions\Domain\Recurrence\OccurrenceStatus;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrence;
use PHPUnit\Framework\TestCase;

final class OccurrenceEffectiveStatusTest extends TestCase
{
    private const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    private const string RECURRENCE = '00000000-0000-7000-8000-0000000000e1';
    private const string OCCURRENCE = '00000000-0000-7000-8000-0000000000f1';
    private const string TRANSACTION = '00000000-0000-7000-8000-000000000901';

    public function testAnUnmatchedPastOccurrenceReadsAsLate(): void
    {
        self::assertSame(
            OccurrenceEffectiveStatus::LATE,
            OccurrenceEffectiveStatus::of(self::expected('2026-03-04'), self::day('2026-03-05')),
        );
    }

    public function testAnUnmatchedOccurrenceExpectedTodayIsNotLateYet(): void
    {
        self::assertSame(
            OccurrenceEffectiveStatus::EXPECTED,
            OccurrenceEffectiveStatus::of(self::expected('2026-03-04'), self::day('2026-03-04')),
        );
    }

    public function testAMatchedOccurrenceStaysReceivedHoweverLateItWas(): void
    {
        $received = new TransactionRecurrenceOccurrence(
            self::OCCURRENCE, WorkspaceScope::fromString(self::WORKSPACE), self::RECURRENCE,
            self::day('2026-03-04'), self::amount('-14.99'), self::amount('0.30'),
            self::TRANSACTION, self::day('2026-03-06'), OccurrenceStatus::RECEIVED,
        );

        self::assertSame(OccurrenceEffectiveStatus::RECEIVED, OccurrenceEffectiveStatus::of($received, self::day('2027-01-01')));
    }

    private static function expected(string $expectedOn): TransactionRecurrenceOccurrence
    {
        return new TransactionRecurrenceOccurrence(
            self::OCCURRENCE, WorkspaceScope::fromString(self::WORKSPACE), self::RECURRENCE,
            self::day($expectedOn), self::amount('-14.99'), self::amount('0.30'), null, null, OccurrenceStatus::EXPECTED,
        );
    }

    private static function amount(string $literal): AssetAmount
    {
        return new AssetAmount(DecimalValue::fromString($literal), AssetCode::fromString('EUR'));
    }

    private static function day(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
