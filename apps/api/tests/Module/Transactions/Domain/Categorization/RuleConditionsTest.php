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
            'text' => [
                'combinator' => 'AND',
                'predicates' => [
                    ['source' => 'RAW_LABEL', 'operator' => 'CONTAINS', 'value' => 'CARREFOUR', 'negated' => false],
                    ['source' => 'COUNTERPARTY', 'operator' => 'EQUALS', 'value' => 'Carrefour', 'negated' => true],
                ],
            ],
            'mcc' => '5411',
            'amount' => ['min' => '-250.00', 'max' => '-5.00', 'assetCode' => 'EUR'],
            'direction' => 'OUT',
        ];

        self::assertSame($document, RuleConditions::fromDocument($document)->toDocument());
    }

    public function testAMissingMemberReadsAsAbsent(): void
    {
        self::assertSame(
            ['text' => null, 'mcc' => null, 'amount' => null, 'direction' => 'IN'],
            RuleConditions::fromDocument(['direction' => 'IN'])->toDocument(),
        );
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function malformedDocuments(): iterable
    {
        yield 'no member at all' => [[]];
        yield 'only null members' => [['text' => null, 'mcc' => null]];
        yield 'unknown member' => [['colour' => 'red']];
        yield 'text group is a list' => [['text' => []]];
        yield 'text group has an unknown member' => [['text' => ['combinator' => 'AND', 'predicates' => [], 'mode' => 'STRICT']]];
        yield 'unknown combinator' => [['text' => ['combinator' => 'XOR', 'predicates' => [['source' => 'RAW_LABEL', 'operator' => 'EQUALS', 'value' => 'a', 'negated' => false]]]]];
        yield 'predicates is not a list' => [['text' => ['combinator' => 'AND', 'predicates' => ['source' => 'RAW_LABEL']]]];
        yield 'empty predicates' => [['text' => ['combinator' => 'AND', 'predicates' => []]]];
        yield 'more than twenty predicates' => [['text' => ['combinator' => 'AND', 'predicates' => array_fill(0, 21, ['source' => 'RAW_LABEL', 'operator' => 'EQUALS', 'value' => 'a', 'negated' => false])]]];
        yield 'unknown source' => [['text' => ['combinator' => 'AND', 'predicates' => [['source' => 'NOTE', 'operator' => 'EQUALS', 'value' => 'a', 'negated' => false]]]]];
        yield 'unknown operator' => [['text' => ['combinator' => 'AND', 'predicates' => [['source' => 'RAW_LABEL', 'operator' => 'STARTS_WITH', 'value' => 'a', 'negated' => false]]]]];
        yield 'extra key in a text predicate' => [['text' => ['combinator' => 'AND', 'predicates' => [['source' => 'RAW_LABEL', 'operator' => 'EQUALS', 'value' => 'a', 'negated' => false, 'flags' => 'i']]]]];
        yield 'negated is not boolean' => [['text' => ['combinator' => 'AND', 'predicates' => [['source' => 'RAW_LABEL', 'operator' => 'EQUALS', 'value' => 'a', 'negated' => 'false']]]]];
        yield 'blank text value' => [['text' => ['combinator' => 'AND', 'predicates' => [['source' => 'RAW_LABEL', 'operator' => 'EQUALS', 'value' => '   ', 'negated' => false]]]]];
        yield 'text value beyond 120 characters' => [['text' => ['combinator' => 'AND', 'predicates' => [['source' => 'RAW_LABEL', 'operator' => 'CONTAINS', 'value' => str_repeat('a', 121), 'negated' => false]]]]];
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
            RuleConditions::fromDocument($this->textGroup([
                ['source' => 'NORMALIZED_LABEL', 'operator' => 'REGEX', 'value' => '(a+)+$', 'negated' => false],
            ]));
            self::fail('An unsafe pattern must be refused.');
        } catch (UnsafeRulePattern $exception) {
            self::assertSame(RegexSafetyPolicy::NESTED_QUANTIFIER, $exception->reason);
            self::assertSame('NORMALIZED_LABEL', $exception->field);
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

        self::assertTrue(RuleConditions::fromDocument($this->textGroup([['source' => 'RAW_LABEL', 'operator' => 'CONTAINS', 'value' => ' carrefour ', 'negated' => false]]))->matches($subject, self::regex(...)));
        self::assertTrue(RuleConditions::fromDocument($this->textGroup([['source' => 'COUNTERPARTY', 'operator' => 'EQUALS', 'value' => 'CARREFOUR', 'negated' => false]]))->matches($subject, self::regex(...)));
        self::assertFalse(RuleConditions::fromDocument($this->textGroup([['source' => 'COUNTERPARTY', 'operator' => 'EQUALS', 'value' => 'Carrefour Market', 'negated' => false]]))->matches($subject, self::regex(...)));
        self::assertTrue(RuleConditions::fromDocument($this->textGroup([['source' => 'NORMALIZED_LABEL', 'operator' => 'EQUALS', 'value' => 'cb carrefour 1234', 'negated' => false]]))->matches($subject, self::regex(...)));
        self::assertTrue(RuleConditions::fromDocument($this->textGroup([['source' => 'RAW_LABEL', 'operator' => 'REGEX', 'value' => '^cb\s+carrefour', 'negated' => false]]))->matches($subject, self::regex(...)));
    }

    public function testNegationInvertsAFalsePredicateIncludingAnAbsentCounterparty(): void
    {
        $positive = RuleConditions::fromDocument($this->textGroup([['source' => 'COUNTERPARTY', 'operator' => 'CONTAINS', 'value' => 'a', 'negated' => false]]));
        $negated = RuleConditions::fromDocument($this->textGroup([['source' => 'COUNTERPARTY', 'operator' => 'CONTAINS', 'value' => 'a', 'negated' => true]]));

        self::assertFalse($positive->matches($this->subject(counterparty: null), self::regex(...)));
        self::assertTrue($negated->matches($this->subject(counterparty: null), self::regex(...)));
    }

    public function testRepeatedSourcesSupportAndOrAndPerPredicateNegation(): void
    {
        $predicates = [
            ['source' => 'COUNTERPARTY', 'operator' => 'CONTAINS', 'value' => 'Carrefour', 'negated' => false],
            ['source' => 'COUNTERPARTY', 'operator' => 'CONTAINS', 'value' => 'Market', 'negated' => true],
        ];

        self::assertTrue(RuleConditions::fromDocument($this->textGroup($predicates))->matches($this->subject(counterparty: 'Carrefour City'), self::regex(...)));
        self::assertFalse(RuleConditions::fromDocument($this->textGroup($predicates))->matches($this->subject(counterparty: 'Carrefour Market'), self::regex(...)));
        self::assertTrue(RuleConditions::fromDocument($this->textGroup($predicates, 'OR'))->matches($this->subject(counterparty: 'Other Shop'), self::regex(...)));
    }

    public function testEveryPresentMemberMustMatch(): void
    {
        $conditions = RuleConditions::fromDocument([
            ...$this->textGroup([['source' => 'RAW_LABEL', 'operator' => 'CONTAINS', 'value' => 'CARREFOUR', 'negated' => false]]),
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
        $conditions = RuleConditions::fromDocument($this->textGroup([['source' => 'RAW_LABEL', 'operator' => 'REGEX', 'value' => 'carrefour', 'negated' => false]]));
        $conditions->matches($this->subject(), static function (string $pattern, string $subject) use (&$seen): bool {
            $seen[] = [$pattern, $subject];

            return true;
        });

        self::assertSame([['carrefour', 'CB CARREFOUR 1234']], $seen);
    }

    public function testAndShortCircuitsBeforeALaterRegexButOrPropagatesARegexBudgetException(): void
    {
        $predicates = [
            ['source' => 'RAW_LABEL', 'operator' => 'EQUALS', 'value' => 'never', 'negated' => false],
            ['source' => 'RAW_LABEL', 'operator' => 'REGEX', 'value' => 'carrefour', 'negated' => true],
        ];
        $regex = static function (): never {
            throw new \RuntimeException('budget');
        };

        self::assertFalse(RuleConditions::fromDocument($this->textGroup($predicates))->matches($this->subject(), $regex));

        $this->expectExceptionMessage('budget');
        RuleConditions::fromDocument($this->textGroup($predicates, 'OR'))->matches($this->subject(), $regex);
    }

    /**
     * @param list<array{source: string, operator: string, value: string, negated: bool}> $predicates
     *
     * @return array{text: array{combinator: string, predicates: list<array{source: string, operator: string, value: string, negated: bool}>}}
     */
    private function textGroup(array $predicates, string $combinator = 'AND'): array
    {
        return ['text' => ['combinator' => $combinator, 'predicates' => $predicates]];
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
