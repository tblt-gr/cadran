<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use App\Module\Foundation\UI\Http\QueryParameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryParametersTest extends TestCase
{
    public function testItAcceptsAnAbsentOrBoundedUnsignedInteger(): void
    {
        self::assertTrue(QueryParameters::isUnsignedIntegerOrEmpty(''));
        self::assertTrue(QueryParameters::isUnsignedIntegerOrEmpty('0001'));
        self::assertNull(QueryParameters::integerOrNull(''));
        self::assertSame(1, QueryParameters::integerOrNull('0001'));
        self::assertFalse(QueryParameters::isUnsignedIntegerOrEmpty('1000', 3));
    }

    #[DataProvider('refused')]
    public function testItRefusesAnythingElse(string $value): void
    {
        self::assertFalse(QueryParameters::isUnsignedIntegerOrEmpty($value));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'five digits' => ['12345'];
        yield 'signed' => ['-1'];
        yield 'padded' => [' 1'];
        yield 'trailing newline' => ["1\n"];
    }

    public function testAFlagIsTrueFalseOrAbsent(): void
    {
        self::assertTrue(QueryParameters::isFlagOrEmpty(''));
        self::assertTrue(QueryParameters::isFlagOrEmpty('true'));
        self::assertTrue(QueryParameters::isFlagOrEmpty('false'));
        self::assertFalse(QueryParameters::isFlagOrEmpty('1'));
    }
}
