<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Domain;

use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\SourceReference;
use PHPUnit\Framework\TestCase;

final class SourceReferenceTest extends TestCase
{
    public function testItRendersTheDocumentedFormat(): void
    {
        $reference = new SourceReference(2025, 3, Block::VAR, 'food', 4);

        self::assertSame('wb:2025:03:VAR:food:4', $reference->toString());
    }

    public function testAReferenceOfExactlyTheMaximumLengthIsLeftAlone(): void
    {
        // 'wb:2025:03:VAR:' is 15 characters and ':4' two more: 111 characters of label key make 128.
        $labelKey = str_repeat('a', 111);

        $reference = (new SourceReference(2025, 3, Block::VAR, $labelKey, 4))->toString();

        self::assertSame(128, strlen($reference));
        self::assertSame('wb:2025:03:VAR:'.$labelKey.':4', $reference);
    }

    public function testALongLabelKeyIsTruncatedWithItsHashSoTheReferenceFitsAndStaysUnique(): void
    {
        $labelKey = str_repeat('long-label-', 30); // about 330 characters
        $reference = new SourceReference(2025, 3, Block::VAR, $labelKey, 12);

        $text = $reference->toString();

        self::assertSame(128, strlen($text));
        self::assertSame($text, $reference->toString(), 'deterministic');
        self::assertStringStartsWith('wb:2025:03:VAR:long-label-', $text);
        self::assertStringEndsWith('-'.substr(hash('sha256', $labelKey), 0, 8).':12', $text);

        // Two labels that share their first 200 characters stay distinct.
        $other = (new SourceReference(2025, 3, Block::VAR, $labelKey.'x', 12))->toString();
        self::assertNotSame($text, $other);
        self::assertLessThanOrEqual(128, strlen($other));
    }

    public function testAPortfolioColumnLetterIsAValidLabelKey(): void
    {
        self::assertSame('wb:2024:11:CTO:D:1', (new SourceReference(2024, 11, Block::CTO, 'D', 1))->toString());
    }

    public function testAnOrdinalBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SourceReference(2025, 3, Block::VAR, 'food', 0);
    }

    public function testAMonthOutOfRangeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SourceReference(2025, 13, Block::VAR, 'food', 1);
    }

    public function testALabelKeyThatCouldBreakTheSeparatorIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SourceReference(2025, 3, Block::VAR, 'fo:od', 1);
    }
}
