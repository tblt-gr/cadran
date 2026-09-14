<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\Recurrence\RecurrenceConfidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurrenceConfidenceTest extends TestCase
{
    /** @return iterable<string, array{int, string, ?RecurrenceConfidence}> */
    public static function observations(): iterable
    {
        yield 'six tight occurrences are high' => [6, '2', RecurrenceConfidence::HIGH];
        yield 'ten tight occurrences are high' => [10, '0', RecurrenceConfidence::HIGH];
        yield 'six occurrences spread beyond two days fall back to medium' => [6, '3', RecurrenceConfidence::MEDIUM];
        yield 'four occurrences within three days are medium' => [4, '3', RecurrenceConfidence::MEDIUM];
        yield 'five occurrences within three days are medium' => [5, '3', RecurrenceConfidence::MEDIUM];
        yield 'five tight occurrences stay medium below six' => [5, '1', RecurrenceConfidence::MEDIUM];
        yield 'three occurrences within four days are low' => [3, '4', RecurrenceConfidence::LOW];
        yield 'three tight occurrences stay low below four' => [3, '0', RecurrenceConfidence::LOW];
        yield 'a half-day deviation is compared exactly' => [6, '2.5', RecurrenceConfidence::MEDIUM];
        yield 'four occurrences spread beyond three days are not classified' => [4, '4', null];
        yield 'five occurrences spread beyond three days are not classified' => [5, '3.5', null];
    }

    #[DataProvider('observations')]
    public function testConfidenceReadsTheQualitativeTable(int $occurrences, string $maxDeviationDays, ?RecurrenceConfidence $expected): void
    {
        self::assertSame($expected, RecurrenceConfidence::classify($occurrences, DecimalValue::fromString($maxDeviationDays)));
    }

    public function testAnUnclassifiableSpreadIsNeverANumber(): void
    {
        self::assertNull(RecurrenceConfidence::classify(4, DecimalValue::fromString('4')));
    }
}
