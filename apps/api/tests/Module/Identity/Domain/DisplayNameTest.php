<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Domain;

use App\Module\Identity\Domain\DisplayName;
use App\Module\Identity\Domain\InvalidDisplayName;
use PHPUnit\Framework\TestCase;

final class DisplayNameTest extends TestCase
{
    public function testItNormalisesSurroundingWhitespace(): void
    {
        self::assertSame('Marie Dupont', DisplayName::fromString("  Marie Dupont\t")->value);
    }

    public function testItRejectsAnEmptyName(): void
    {
        $this->expectException(InvalidDisplayName::class);

        DisplayName::fromString('');
    }

    public function testItRejectsAWhitespaceOnlyName(): void
    {
        $this->expectException(InvalidDisplayName::class);

        DisplayName::fromString("  \t \n ");
    }

    public function testItAcceptsExactlyTheMaximumLength(): void
    {
        $name = str_repeat('a', DisplayName::MAX_LENGTH);

        self::assertSame($name, DisplayName::fromString($name)->value);
    }

    public function testItRejectsOneCharacterPastTheMaximum(): void
    {
        $this->expectException(InvalidDisplayName::class);

        DisplayName::fromString(str_repeat('a', DisplayName::MAX_LENGTH + 1));
    }

    public function testItCountsUnicodeCodePointsRatherThanBytes(): void
    {
        // 100 accented letters are 200 bytes in UTF-8: a byte-length rule would
        // refuse a name the policy accepts.
        $name = str_repeat('é', DisplayName::MAX_LENGTH);

        self::assertSame($name, DisplayName::fromString($name)->value);
    }

    public function testTheLengthIsMeasuredAfterTrimming(): void
    {
        $name = str_repeat('a', DisplayName::MAX_LENGTH);

        self::assertSame($name, DisplayName::fromString('  '.$name.'  ')->value);
    }
}
