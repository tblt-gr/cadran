<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Domain;

use App\Module\Foundation\Domain\BoundedText;
use PHPUnit\Framework\TestCase;

final class BoundedTextTest extends TestCase
{
    public function testItBoundsATrimmedLine(): void
    {
        self::assertFalse(BoundedText::isValid(' a', 1, 80));
        self::assertFalse(BoundedText::isValid('', 1, 80));
        self::assertTrue(BoundedText::isValid('a', 1, 80));
        self::assertTrue(BoundedText::isValid(str_repeat('é', 80), 1, 80));
        self::assertFalse(BoundedText::isValid(str_repeat('é', 81), 1, 80));
        self::assertFalse(BoundedText::isValid('ab', 3, 80));
    }

    public function testItFlagsControlAndFormatCharacters(): void
    {
        self::assertTrue(BoundedText::hasControlCharacters("a\u{200B}b"));
        self::assertTrue(BoundedText::hasControlCharacters("a\tb"));
        self::assertFalse(BoundedText::hasControlCharacters('Compte courant'));
    }
}
