<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Domain;

use App\Module\Import\Domain\LabelKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LabelKeyTest extends TestCase
{
    #[DataProvider('labels')]
    public function testALabelIsLowercasedUnaccentedAndHyphenated(string $label, string $key): void
    {
        self::assertSame($key, LabelKey::normalize($label));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function labels(): iterable
    {
        yield 'the spec example with accent and colon' => ['Dépenses Fixes :', 'depenses-fixes'];
        yield 'the same without accent' => ['depenses fixes', 'depenses-fixes'];
        yield 'a month name with an accent' => ['Août', 'aout'];
        yield 'a decomposed accent' => ["Fe\u{0301}vrier", 'fevrier'];
        yield 'an underscore is a separator' => ['CTO_TR', 'cto-tr'];
        yield 'a header keeps its hyphens as one separator' => ['Solde N-1', 'solde-n-1'];
        yield 'runs of separators collapse' => ['  Savings   --  A  ', 'savings-a'];
        yield 'digits are kept' => ['Livret 2', 'livret-2'];
        yield 'a compatibility form is folded' => ["\u{FB01}n", 'fin'];
        yield 'whitespace only' => ["  \t ", ''];
        yield 'punctuation only' => ['---', ''];
        yield 'empty' => ['', ''];
    }
}
