<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Categorization;

use App\Module\Transactions\Domain\Categorization\RegexSafetyPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegexSafetyPolicyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function refusedPatterns(): iterable
    {
        yield 'a 400-character pattern' => [str_repeat('a', 400), RegexSafetyPolicy::TOO_LONG];
        yield 'one character over the bound' => [str_repeat('é', 121), RegexSafetyPolicy::TOO_LONG];
        yield 'negative lookahead' => ['^(?!TEST)', RegexSafetyPolicy::LOOKAROUND];
        yield 'positive lookahead' => ['a(?=b)', RegexSafetyPolicy::LOOKAROUND];
        yield 'positive lookbehind' => ['(?<=a)b', RegexSafetyPolicy::LOOKAROUND];
        yield 'negative lookbehind' => ['(?<!a)b', RegexSafetyPolicy::LOOKAROUND];
        yield 'numbered backreference' => ['(a)\1+', RegexSafetyPolicy::BACKREFERENCE];
        yield 'named backreference' => ['(?P<x>a)(?P=x)', RegexSafetyPolicy::BACKREFERENCE];
        yield 'relative backreference' => ['(a)\g{-1}', RegexSafetyPolicy::BACKREFERENCE];
        yield 'nested quantifier' => ['(a+)+$', RegexSafetyPolicy::NESTED_QUANTIFIER];
        yield 'nested quantifier two levels deep' => ['((a)*b)+', RegexSafetyPolicy::NESTED_QUANTIFIER];
        yield 'nested counted quantifier' => ['(a{2,})*', RegexSafetyPolicy::NESTED_QUANTIFIER];
        yield 'nested quantifier behind a non-capturing group' => ['(?:\s*x)+', RegexSafetyPolicy::NESTED_QUANTIFIER];
        yield 'atomic group' => ['(?>a+)b', RegexSafetyPolicy::POSSESSIVE];
        yield 'possessive star' => ['a*+b', RegexSafetyPolicy::POSSESSIVE];
        yield 'possessive plus' => ['a++b', RegexSafetyPolicy::POSSESSIVE];
        yield 'inline modifier after the start' => ['a(?i)b', RegexSafetyPolicy::INLINE_MODIFIER];
        yield 'scoped inline modifier' => ['(?x:a b)', RegexSafetyPolicy::INLINE_MODIFIER];
        yield 'unbalanced group' => ['(ab', RegexSafetyPolicy::INVALID];
        yield 'unterminated class' => ['[ab', RegexSafetyPolicy::INVALID];
        yield 'empty pattern' => ['', RegexSafetyPolicy::INVALID];
    }

    #[DataProvider('refusedPatterns')]
    public function testAnUnsafePatternIsRefusedWithItsReason(string $pattern, string $reason): void
    {
        self::assertSame($reason, RegexSafetyPolicy::violation($pattern));
    }

    public function testTheSpecifiedModifierExampleIsRefused(): void
    {
        self::assertNotNull(RegexSafetyPolicy::violation('(?i)(x+)+'));
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedPatterns(): iterable
    {
        yield 'anchored literal' => ['^CB CARREFOUR'];
        yield 'alternation' => ['carrefour|auchan'];
        yield 'leading case modifier' => ['(?i)^amazon'];
        yield 'counted digits' => ['\d{4}'];
        yield 'quantified group without inner quantifier' => ['(?:ab)+'];
        yield 'quantifier after a capture' => ['[a-z]+\s(\d+)'];
        yield 'slash inside the pattern' => ['a/b'];
        yield 'escaped slash inside the pattern' => ['a\/b'];
        yield 'escaped parentheses around a quantifier' => ['\(a+\)+'];
        yield 'parentheses inside classes' => ['[(]a+[)]+'];
        yield 'escaped backslash followed by a digit' => ['\\\\1'];
        yield 'lazy quantifier' => ['a.*?b'];
        yield 'literal brace' => ['a{b'];
        yield 'exactly 120 characters' => [str_repeat('é', 120)];
    }

    #[DataProvider('acceptedPatterns')]
    public function testASafePatternIsAccepted(string $pattern): void
    {
        self::assertNull(RegexSafetyPolicy::violation($pattern));
    }

    public function testTheCompiledFormMatchesCaseInsensitivelyWithAnEmbeddedSlash(): void
    {
        self::assertSame(1, preg_match(RegexSafetyPolicy::compile('cb/carrefour'), 'CB/CARREFOUR 12'));
        self::assertSame(1, preg_match(RegexSafetyPolicy::compile('a\/b'), 'A/B'));
    }
}
