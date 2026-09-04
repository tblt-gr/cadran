<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

use App\Module\Foundation\Domain\DecimalValue;

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
}
