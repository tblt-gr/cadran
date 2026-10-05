<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTerms;
use App\Module\Portfolio\Domain\LifeInsurance\InvalidLifeInsurance;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeeTermsTest extends TestCase
{
    private const string ID = '00000000-0000-7000-8000-0000000000f1';

    public function testTheVersionApplicableOnADateIsTheLatestOneOnOrBeforeIt(): void
    {
        $first = self::terms('2026-01-01', entry: '1');
        $second = self::terms('2026-06-01', entry: '0.8');

        self::assertNull(FeeTerms::applicableOn([$second, $first], new \DateTimeImmutable('2025-12-31')));
        self::assertSame($first, FeeTerms::applicableOn([$second, $first], new \DateTimeImmutable('2026-05-31')));
        self::assertSame($second, FeeTerms::applicableOn([$first, $second], new \DateTimeImmutable('2026-06-01')));
        self::assertSame($second, FeeTerms::applicableOn([$first, $second], new \DateTimeImmutable('2030-01-01')));
        self::assertNull(FeeTerms::applicableOn([], new \DateTimeImmutable('2026-06-01')));
    }

    #[DataProvider('validRates')]
    public function testItAcceptsRatesInsideTheBounds(string $rate): void
    {
        self::assertSame($rate, self::terms('2026-01-01', entry: $rate)->entryFeeRate?->toString());
    }

    /** @return iterable<string, array{string}> */
    public static function validRates(): iterable
    {
        yield 'zero' => ['0'];
        yield 'demo entry' => ['1'];
        yield 'four decimals' => ['0.8512'];
        yield 'upper bound' => ['100'];
        yield 'upper bound with zeros' => ['100.0000'];
    }

    #[DataProvider('invalidRates')]
    public function testItRefusesARateOutsideTheBounds(string $rate): void
    {
        try {
            self::terms('2026-01-01', entry: $rate);
            self::fail('The rate must be refused.');
        } catch (InvalidLifeInsurance $exception) {
            self::assertSame('invalid_fee_rate', $exception->problemCode);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRates(): iterable
    {
        yield 'just above one hundred' => ['100.0001'];
        yield 'negative' => ['-0.1'];
        yield 'five decimals' => ['0.12345'];
        yield 'huge' => ['99999999999999999999999999'];
    }

    public function testTheFixedSwitchFeeCannotBeNegative(): void
    {
        $this->expectException(InvalidLifeInsurance::class);
        self::terms('2026-01-01', fixed: '-1');
    }

    public function testEveryFigureMayBeUnknown(): void
    {
        $terms = self::terms('2026-01-01');

        self::assertNull($terms->entryFeeRate);
        self::assertNull($terms->switchFeeFixed);
    }

    public function testASourceNoteIsBoundedAndFreeOfControlCharacters(): void
    {
        foreach ([str_repeat('a', 201), "bad\x00note", '   '] as $note) {
            try {
                self::terms('2026-01-01', note: $note);
                self::fail('The note must be refused.');
            } catch (InvalidLifeInsurance $exception) {
                self::assertSame('invalid_contract_field', $exception->problemCode);
            }
        }
        self::assertSame('Conditions 2026', self::terms('2026-01-01', note: ' Conditions 2026 ')->sourceNote);
    }

    private static function terms(string $validFrom, ?string $entry = null, ?string $note = null, ?string $fixed = null): FeeTerms
    {
        return new FeeTerms(
            id: self::ID,
            workspace: WorkspaceScope::fromString(WorkspaceFixture::OWN_WORKSPACE),
            contractId: '00000000-0000-7000-8000-0000000000c1',
            validFrom: new \DateTimeImmutable($validFrom),
            entryFeeRate: null === $entry ? null : DecimalValue::fromString($entry),
            euroFundManagementFeeRate: null,
            unitLinkedManagementFeeRate: null,
            switchFeeRate: null,
            switchFeeFixed: null === $fixed ? null : DecimalValue::fromString($fixed),
            sourceNote: $note,
            recordedAt: new \DateTimeImmutable('2026-03-01'),
            recordedBy: '00000000-0000-7000-8000-0000000000a1',
        );
    }
}
