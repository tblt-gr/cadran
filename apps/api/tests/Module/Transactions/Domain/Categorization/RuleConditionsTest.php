<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Categorization;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\Categorization\CategorizationSubject;
use App\Module\Transactions\Domain\Categorization\InvalidCategorizationRule;
use App\Module\Transactions\Domain\Categorization\RegexSafetyPolicy;
use App\Module\Transactions\Domain\Categorization\RuleConditions;
use App\Module\Transactions\Domain\Categorization\UnsafeRulePattern;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuleConditionsTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';

    public function testTheDocumentRoundTripsWithEveryMember(): void
    {
        $document = [
            'rawLabel' => ['operator' => 'CONTAINS', 'value' => 'CARREFOUR'],
            'normalizedLabel' => null,
            'counterparty' => ['operator' => 'EQUALS', 'value' => 'Carrefour'],
            'mcc' => '5411',
            'amount' => ['min' => '-250.00', 'max' => '-5.00', 'assetCode' => 'EUR'],
            'direction' => 'OUT',
        ];

        self::assertSame($document, RuleConditions::fromDocument($document)->toDocument());
    }

    public function testAMissingMemberReadsAsAbsent(): void
    {
        self::assertSame(
            ['rawLabel' => null, 'normalizedLabel' => null, 'counterparty' => null, 'mcc' => null, 'amount' => null, 'direction' => 'IN'],
            RuleConditions::fromDocument(['direction' => 'IN'])->toDocument(),
        );
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function malformedDocuments(): iterable
    {
        yield 'no member at all' => [[]];
        yield 'only null members' => [['rawLabel' => null, 'mcc' => null]];
        yield 'unknown member' => [['colour' => 'red']];
        yield 'unknown operator' => [['rawLabel' => ['operator' => 'STARTS_WITH', 'value' => 'a']]];
        yield 'extra key in a text condition' => [['rawLabel' => ['operator' => 'EQUALS', 'value' => 'a', 'flags' => 'i']]];
        yield 'blank text value' => [['rawLabel' => ['operator' => 'EQUALS', 'value' => '   ']]];
        yield 'text value beyond 120 characters' => [['rawLabel' => ['operator' => 'CONTAINS', 'value' => str_repeat('a', 121)]]];
        yield 'mcc not four digits' => [['mcc' => '541']];
        yield 'unknown direction' => [['direction' => 'SIDEWAYS']];
        yield 'amount without bound' => [['amount' => ['min' => null, 'max' => null, 'assetCode' => 'EUR']]];
        yield 'amount min above max' => [['amount' => ['min' => '-5.00', 'max' => '-250.00', 'assetCode' => 'EUR']]];
        yield 'amount as float' => [['amount' => ['min' => -5.0, 'max' => null, 'assetCode' => 'EUR']]];
        yield 'amount malformed decimal' => [['amount' => ['min' => '1e3', 'max' => null, 'assetCode' => 'EUR']]];
        yield 'amount beyond precision' => [['amount' => ['min' => '0.0000000000000000000000001', 'max' => null, 'assetCode' => 'EUR']]];
        yield 'amount missing asset' => [['amount' => ['min' => '1.00', 'max' => null]]];
    }

    /** @param array<mixed> $document */
    #[DataProvider('malformedDocuments')]
    public function testAMalformedDocumentIsRefused(array $document): void
    {
        $this->expectException(InvalidCategorizationRule::class);
        RuleConditions::fromDocument($document);
    }

    public function testAnUnsafeRegularExpressionIsRefusedWithItsReason(): void
    {
        try {
            RuleConditions::fromDocument(['rawLabel' => ['operator' => 'REGEX', 'value' => '(a+)+$']]);
            self::fail('An unsafe pattern must be refused.');
        } catch (UnsafeRulePattern $exception) {
            self::assertSame(RegexSafetyPolicy::NESTED_QUANTIFIER, $exception->reason);
            self::assertSame('rawLabel', $exception->field);
        }
    }

    /**
     * Hand-computed signed range: [-250.00, -5.00] inclusive on both ends, in EUR.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function amounts(): iterable
    {
        yield 'inside the range' => ['-42.90', 'EUR', true];
        yield 'on the lower bound' => ['-250.00', 'EUR', true];
        yield 'on the upper bound at another scale' => ['-5', 'EUR', true];
        yield 'one cent above the upper bound' => ['-4.99', 'EUR', false];
        yield 'one cent below the lower bound' => ['-250.01', 'EUR', false];
        yield 'the opposite sign' => ['42.90', 'EUR', false];
        yield 'another asset' => ['-42.90', 'USD', false];
    }

    #[DataProvider('amounts')]
    public function testTheSignedAmountRangeIsInclusiveAndAssetBound(string $amount, string $asset, bool $expected): void
    {
        $conditions = RuleConditions::fromDocument(['amount' => ['min' => '-250.00', 'max' => '-5.00', 'assetCode' => 'EUR']]);

        self::assertSame($expected, $conditions->matches($this->subject(amount: $amount, asset: $asset), self::regex(...)));
    }

    public function testAVeryLargeDecimalBoundComparesExactly(): void
    {
        $bound = '-99999999999999999999999999.999999999999999999999999';
        $conditions = RuleConditions::fromDocument(['amount' => ['min' => $bound, 'max' => null, 'assetCode' => 'EUR']]);

        self::assertTrue($conditions->matches($this->subject(amount: $bound), self::regex(...)));
        self::assertFalse(RuleConditions::fromDocument(
            ['amount' => ['min' => '-99999999999999999999999999.999999999999999999999998', 'max' => null, 'assetCode' => 'EUR']],
        )->matches($this->subject(amount: $bound), self::regex(...)));
    }

    public function testAOneSidedRangeLeavesTheOtherSideOpen(): void
    {
        $conditions = RuleConditions::fromDocument(['amount' => ['min' => null, 'max' => '0.00', 'assetCode' => 'EUR']]);

        self::assertTrue($conditions->matches($this->subject(amount: '-99999999999999999999999999'), self::regex(...)));
        self::assertFalse($conditions->matches($this->subject(amount: '0.000000000000000000000001'), self::regex(...)));
    }

    public function testTextComparisonsAreCaseInsensitiveAndTrimmed(): void
    {
        $subject = $this->subject(rawLabel: 'CB CARREFOUR 1234', counterparty: 'Carrefour');

        self::assertTrue(RuleConditions::fromDocument(['rawLabel' => ['operator' => 'CONTAINS', 'value' => ' carrefour ']])->matches($subject, self::regex(...)));
        self::assertTrue(RuleConditions::fromDocument(['counterparty' => ['operator' => 'EQUALS', 'value' => 'CARREFOUR']])->matches($subject, self::regex(...)));
        self::assertFalse(RuleConditions::fromDocument(['counterparty' => ['operator' => 'EQUALS', 'value' => 'Carrefour Market']])->matches($subject, self::regex(...)));
        self::assertTrue(RuleConditions::fromDocument(['normalizedLabel' => ['operator' => 'EQUALS', 'value' => 'cb carrefour 1234']])->matches($subject, self::regex(...)));
        self::assertTrue(RuleConditions::fromDocument(['rawLabel' => ['operator' => 'REGEX', 'value' => '^cb\s+carrefour']])->matches($subject, self::regex(...)));
    }

    public function testATextConditionOnAnAbsentCounterpartyDoesNotMatch(): void
    {
        $conditions = RuleConditions::fromDocument(['counterparty' => ['operator' => 'CONTAINS', 'value' => 'a']]);

        self::assertFalse($conditions->matches($this->subject(counterparty: null), self::regex(...)));
    }

    public function testEveryPresentMemberMustMatch(): void
    {
        $conditions = RuleConditions::fromDocument([
            'rawLabel' => ['operator' => 'CONTAINS', 'value' => 'CARREFOUR'],
            'mcc' => '5411',
            'direction' => 'OUT',
        ]);

        self::assertTrue($conditions->matches($this->subject(mcc: '5411'), self::regex(...)));
        self::assertFalse($conditions->matches($this->subject(mcc: '5812'), self::regex(...)));
        self::assertFalse($conditions->matches($this->subject(mcc: null), self::regex(...)));
        self::assertFalse($conditions->matches($this->subject(amount: '42.90', mcc: '5411'), self::regex(...)));
    }

    public function testTheDirectionReadsTheSign(): void
    {
        $in = RuleConditions::fromDocument(['direction' => 'IN']);

        self::assertTrue($in->matches($this->subject(amount: '0.01'), self::regex(...)));
        self::assertFalse($in->matches($this->subject(amount: '-0.01'), self::regex(...)));
    }

    public function testTheRegularExpressionIsEvaluatedThroughTheInjectedMatcher(): void
    {
        $seen = [];
        $conditions = RuleConditions::fromDocument(['rawLabel' => ['operator' => 'REGEX', 'value' => 'carrefour']]);
        $conditions->matches($this->subject(), static function (string $pattern, string $subject) use (&$seen): bool {
            $seen[] = [$pattern, $subject];

            return true;
        });

        self::assertSame([['carrefour', 'CB CARREFOUR 1234']], $seen);
    }

    private static function regex(string $pattern, string $subject): bool
    {
        return 1 === preg_match(RegexSafetyPolicy::compile($pattern), $subject);
    }

    private function subject(
        string $amount = '-42.90',
        string $asset = 'EUR',
        string $rawLabel = 'CB CARREFOUR 1234',
        ?string $counterparty = 'Carrefour',
        ?string $mcc = '5411',
    ): CategorizationSubject {
        return new CategorizationSubject(
            accountId: self::ACCOUNT,
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString($asset)),
            nature: str_starts_with($amount, '-') ? TransactionNature::EXPENSE : TransactionNature::INCOME,
            state: TransactionState::BOOKED,
            bookedOn: new \DateTimeImmutable('2026-03-14'),
            rawLabel: $rawLabel,
            counterparty: $counterparty,
            mcc: $mcc,
        );
    }
}
