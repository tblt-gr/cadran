<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\RuleValue;
use App\Module\Catalog\Domain\RuleValueType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuleValueTest extends TestCase
{
    public function testATextValueIsATokenTheInterfaceTranslates(): void
    {
        $value = RuleValue::text('FORTNIGHTLY');

        self::assertSame(RuleValueType::TEXT, $value->type);
        self::assertSame('FORTNIGHTLY', $value->text);
    }

    #[DataProvider('refusedTokens')]
    public function testASentenceCannotBeStoredAsARuleValue(string $token): void
    {
        // Regulatory wording belongs to the source and to the translation
        // catalogue, not to a database column no locale can reach.
        $this->expectException(InvalidCatalogEntry::class);

        RuleValue::text($token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTokens(): iterable
    {
        yield 'a french sentence' => ['Intérêts calculés par quinzaine'];
        yield 'lowercase' => ['fortnightly'];
        yield 'empty' => [''];
        yield 'overlong' => [str_repeat('A', 65)];
    }
}
