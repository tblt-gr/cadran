<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * The complete bracket scale a resolved rate is read from.
 *
 * Every rate resolves to a scale, including the single-rate products the
 * catalogue ships today: a Livret A at 1.7 % answers with one bracket running
 * from zero without limit. Resolving the simple case into the general shape is
 * what lets a tiered product arrive later as data, without a consumer having to
 * learn a second way of reading a rate — and without any screen having to guess
 * whether "1.7 %" applies to the whole balance or to a slice of it.
 *
 * The brackets tile the amounts they cover: the first starts at zero, each one
 * starts where the previous ended, and the last runs without limit. No amount
 * is therefore uncovered, and none is covered twice.
 */
final readonly class RateScale
{
    /** @var non-empty-list<RateBracket> */
    public array $brackets;

    /**
     * @param list<RateBracket> $brackets
     */
    public function __construct(
        array $brackets,
        public RateApplication $application,
    ) {
        if ([] === $brackets) {
            throw new InvalidCatalogEntry('A rate scale carries at least one bracket.');
        }

        $zero = DecimalValue::fromString('0');
        if (0 !== $brackets[0]->lowerBound->compareTo($zero)) {
            throw new InvalidCatalogEntry('A rate scale starts at zero.');
        }

        $last = array_key_last($brackets);
        foreach ($brackets as $position => $bracket) {
            if ($position === $last) {
                break;
            }

            $next = $brackets[$position + 1];
            if (null === $bracket->upperBound || 0 !== $next->lowerBound->compareTo($bracket->upperBound)) {
                throw new InvalidCatalogEntry('Each rate bracket starts where the previous one ends.');
            }
        }

        if (!$brackets[$last]->coversWithoutLimit()) {
            throw new InvalidCatalogEntry('The last rate bracket runs without an upper limit.');
        }

        $this->brackets = $brackets;
    }

    /**
     * The scale a single published rate resolves to: one bracket, from zero,
     * without limit. One bracket covers every amount, so both application
     * modes agree on it and the marginal reading is the plain one.
     */
    public static function singleRate(DecimalValue $percentage): self
    {
        return new self(
            [new RateBracket(DecimalValue::fromString('0'), null, $percentage)],
            RateApplication::MARGINAL,
        );
    }

    public function isTiered(): bool
    {
        return count($this->brackets) > 1;
    }

    /**
     * Reads the scale against one balance. A Livret Bleu above the Livret A
     * figure is the reason the last bracket has no cap: the excess keeps
     * earning, at the rate of the slice it fell into (or of the reached
     * bracket, when the scale is flat).
     */
    public function apply(DecimalValue $balance): AppliedRate
    {
        if ($balance->isNegative()) {
            $first = $this->brackets[0];

            return new AppliedRate(
                interest: null,
                effectivePercentage: null,
                reachedPercentage: $first->percentage,
                reachedLowerBound: $first->lowerBound,
                rateShiftsAboveFirstBracket: false,
                unsettledReason: AppliedRate::UNSETTLED_NEGATIVE_BALANCE,
            );
        }

        $reached = $this->reached($balance);
        $interest = $this->interestOn($balance, $reached);

        if (ExactDecimal::isZero($balance)) {
            return new AppliedRate(
                interest: $interest,
                effectivePercentage: null,
                reachedPercentage: $reached->percentage,
                reachedLowerBound: $reached->lowerBound,
                rateShiftsAboveFirstBracket: false,
                unsettledReason: AppliedRate::UNSETTLED_ZERO_BALANCE,
            );
        }

        $effective = ExactDecimal::timesHundred(ExactDecimal::divide($interest, $balance));

        return new AppliedRate(
            interest: $interest,
            effectivePercentage: $effective,
            reachedPercentage: $reached->percentage,
            reachedLowerBound: $reached->lowerBound,
            rateShiftsAboveFirstBracket: 0 !== $reached->lowerBound->compareTo(DecimalValue::fromString('0')),
        );
    }

    private function reached(DecimalValue $balance): RateBracket
    {
        foreach ($this->brackets as $bracket) {
            if ($this->covers($bracket, $balance)) {
                return $bracket;
            }
        }

        return $this->brackets[array_key_last($this->brackets)];
    }

    private function interestOn(DecimalValue $balance, RateBracket $reached): DecimalValue
    {
        $hundred = DecimalValue::fromString('100');

        if (RateApplication::FLAT_BY_BRACKET === $this->application) {
            return ExactDecimal::divide(
                ExactDecimal::multiply($balance, $reached->percentage),
                $hundred,
            );
        }

        $interest = DecimalValue::fromString('0');
        foreach ($this->brackets as $bracket) {
            $slice = $this->slice($balance, $bracket);
            if (null === $slice) {
                continue;
            }

            $interest = ExactDecimal::add(
                $interest,
                ExactDecimal::divide(ExactDecimal::multiply($slice, $bracket->percentage), $hundred),
            );
        }

        return $interest;
    }

    private function slice(DecimalValue $balance, RateBracket $bracket): ?DecimalValue
    {
        if ($balance->compareTo($bracket->lowerBound) <= 0) {
            return null;
        }

        $end = $bracket->upperBound;
        $capped = null === $end || $balance->compareTo($end) < 0 ? $balance : $end;

        return ExactDecimal::subtract($capped, $bracket->lowerBound);
    }

    private function covers(RateBracket $bracket, DecimalValue $amount): bool
    {
        if ($amount->compareTo($bracket->lowerBound) < 0) {
            return false;
        }

        return null === $bracket->upperBound || $amount->compareTo($bracket->upperBound) < 0;
    }
}
