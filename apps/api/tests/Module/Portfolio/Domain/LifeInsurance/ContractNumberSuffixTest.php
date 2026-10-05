<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Portfolio\Domain\LifeInsurance\ContractNumberSuffix;
use App\Module\Portfolio\Domain\LifeInsurance\InvalidLifeInsurance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContractNumberSuffixTest extends TestCase
{
    public function testItKeepsOnlyTheLastFourCharactersOfTheNumber(): void
    {
        $suffix = ContractNumberSuffix::fromNumber('AV-00012345');

        self::assertSame('2345', $suffix->value);
        self::assertSame('•••• 2345', $suffix->masked());
    }

    public function testSpacesAndHyphensAreIgnoredBeforeTruncation(): void
    {
        self::assertSame('49A1', ContractNumberSuffix::fromNumber('AV 00-12 34 9A-1')->value);
        self::assertSame('2345', ContractNumberSuffix::fromNumber('AV 0001 2345')->value);
    }

    public function testTheMaskedFormNeverContainsMoreThanTheSuffix(): void
    {
        $masked = ContractNumberSuffix::fromNumber('AV-00012345')->masked();

        self::assertStringNotContainsString('00012345', $masked);
        self::assertStringNotContainsString('AV', $masked);
    }

    #[DataProvider('invalidNumbers')]
    public function testItRefusesAMalformedNumberWithoutEchoingIt(string $number): void
    {
        try {
            ContractNumberSuffix::fromNumber($number);
            self::fail('A malformed number must be refused.');
        } catch (InvalidLifeInsurance $exception) {
            self::assertSame('invalid_contract_field', $exception->problemCode);
            '' === $number || self::assertStringNotContainsString($number, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNumbers(): iterable
    {
        yield 'too short' => ['AB1'];
        yield 'too long' => [str_repeat('A', 35)];
        yield 'forbidden character' => ['AV_0001/2345'];
        yield 'empty' => [''];
        yield 'only separators' => ['----    '];
        yield 'non ASCII' => ['AV-0001234É'];
    }

    public function testThirtyFourCharactersAreAccepted(): void
    {
        self::assertSame('ZZZZ', ContractNumberSuffix::fromNumber(str_repeat('A', 30).'ZZZZ')->value);
    }

    public function testAStoredSuffixMustBeFourCharacters(): void
    {
        $this->expectException(InvalidLifeInsurance::class);
        ContractNumberSuffix::fromStored('12345');
    }
}
