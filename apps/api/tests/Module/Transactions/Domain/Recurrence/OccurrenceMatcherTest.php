<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Recurrence\OccurrenceMatcher;
use App\Module\Transactions\Domain\Recurrence\OccurrenceStatus;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrence;
use PHPUnit\Framework\TestCase;

final class OccurrenceMatcherTest extends TestCase
{
    private const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    private const string RECURRENCE = '00000000-0000-7000-8000-0000000000e1';

    public function testTheNearestUnmatchedOccurrenceWins(): void
    {
        $far = self::occurrence('01', '2026-03-04');
        $near = self::occurrence('02', '2026-03-09');

        $selected = OccurrenceMatcher::select([$far, $near], self::amount('-14.99'), self::day('2026-03-10'));

        self::assertNotNull($selected);
        self::assertSame($near->id, $selected->id);
    }

    public function testATieTakesTheEarlierExpectedDate(): void
    {
        $later = self::occurrence('02', '2026-03-12');
        $earlier = self::occurrence('01', '2026-03-08');

        $selected = OccurrenceMatcher::select([$later, $earlier], self::amount('-14.99'), self::day('2026-03-10'));

        self::assertNotNull($selected);
        self::assertSame($earlier->id, $selected->id);
    }

    public function testAnOccurrenceMoreThanSevenDaysAwayIsNotEligible(): void
    {
        $occurrence = self::occurrence('01', '2026-03-04');

        self::assertNull(OccurrenceMatcher::select([$occurrence], self::amount('-14.99'), self::day('2026-03-12')));
        self::assertNotNull(OccurrenceMatcher::select([$occurrence], self::amount('-14.99'), self::day('2026-03-11')));
    }

    public function testAnAmountOutsideTheToleranceIsNotEligible(): void
    {
        $occurrence = self::occurrence('01', '2026-03-04');

        self::assertNotNull(OccurrenceMatcher::select([$occurrence], self::amount('-15.29'), self::day('2026-03-04')));
        self::assertNull(OccurrenceMatcher::select([$occurrence], self::amount('-15.30'), self::day('2026-03-04')));
        self::assertNotNull(OccurrenceMatcher::select([$occurrence], self::amount('-14.69'), self::day('2026-03-04')));
        self::assertNull(OccurrenceMatcher::select([$occurrence], self::amount('-14.68'), self::day('2026-03-04')));
    }

    public function testAnotherDenominationNeverMatches(): void
    {
        $occurrence = self::occurrence('01', '2026-03-04');

        self::assertNull(OccurrenceMatcher::select([$occurrence], new AssetAmount(DecimalValue::fromString('-14.99'), AssetCode::fromString('USD')), self::day('2026-03-04')));
    }

    public function testASettledInstalmentStillFitsTheMovementThatSettledIt(): void
    {
        $received = new TransactionRecurrenceOccurrence(
            '00000000-0000-7000-8000-0000000000f1', WorkspaceScope::fromString(self::WORKSPACE), self::RECURRENCE,
            self::day('2026-03-04'), self::amount('-14.99'), self::amount('0.30'),
            '00000000-0000-7000-8000-000000000901', self::day('2026-03-04'), OccurrenceStatus::RECEIVED,
        );

        self::assertTrue(OccurrenceMatcher::fits($received, self::amount('-14.99'), self::day('2026-03-06')));
        self::assertFalse(OccurrenceMatcher::fits($received, self::amount('-19.99'), self::day('2026-03-06')));
        self::assertFalse(OccurrenceMatcher::accepts($received, self::amount('-14.99'), self::day('2026-03-06')));
    }

    public function testAnAlreadyReceivedOccurrenceIsNeverSelected(): void
    {
        $received = new TransactionRecurrenceOccurrence(
            '00000000-0000-7000-8000-0000000000f1', WorkspaceScope::fromString(self::WORKSPACE), self::RECURRENCE,
            self::day('2026-03-04'), self::amount('-14.99'), self::amount('0.30'),
            '00000000-0000-7000-8000-000000000901', self::day('2026-03-04'), OccurrenceStatus::RECEIVED,
        );

        self::assertNull(OccurrenceMatcher::select([$received], self::amount('-14.99'), self::day('2026-03-04')));
    }

    private static function occurrence(string $suffix, string $expectedOn): TransactionRecurrenceOccurrence
    {
        return new TransactionRecurrenceOccurrence(
            '00000000-0000-7000-8000-0000000000f'.$suffix[1], WorkspaceScope::fromString(self::WORKSPACE), self::RECURRENCE,
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
