<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\DeclaredRuleValue;
use App\Module\Accounts\Domain\InvalidDeclaredRule;
use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\RateApplication;
use App\Module\Catalog\Domain\RateBracket;
use App\Module\Catalog\Domain\RateScale;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\RuleValueType;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Application\InvalidAmountInput;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;

/**
 * Turns the strings submitted for one dated rule into the closed types the
 * domain accepts, whether the rule is being recorded on a reusable product
 * model or as an override on a single account.
 *
 * The two submit the same shape and must refuse the same things: an unknown
 * kind, a value that does not match the shape its kind declares, a scale with
 * a gap in it. Parsing them in one place is what keeps the override endpoint
 * from becoming a second, laxer door onto the same figures.
 *
 * Every unknown token is refused rather than defaulted, and refused before any
 * value object is built, so a client is told what it sent wrong instead of
 * being handed the domain's own wording.
 */
final class DeclaredRuleParser
{
    /**
     * A submitted scale is bounded: a client cannot make one request build a
     * thousand brackets the database then has to store and re-read.
     */
    public const int MAX_BRACKETS = 20;

    public static function kind(string $value): RuleKind
    {
        return RuleKind::tryFrom($value) ?? throw new InvalidDeclaredRuleInput('The rule kind must be a supported kind.');
    }

    public static function value(RuleKind $kind, DeclaredRuleInput $input, AmountInputParser $amounts): DeclaredRuleValue
    {
        $valueType = $kind->valueType();
        if (RuleValueType::AMOUNT !== $valueType) {
            if (null !== $input->amount && !is_string($input->amount)) {
                throw new InvalidAmountInput($input->amountPointer, 'amount.not_a_string');
            }
            if (null !== $input->amountAssetCode && !is_string($input->amountAssetCode)) {
                throw new InvalidAmountInput($input->amountAssetCodePointer, 'amount.not_a_string');
            }
        }

        try {
            return match ($valueType) {
                RuleValueType::AMOUNT => DeclaredRuleValue::amount($amounts->fromFields(
                    $input->amount,
                    $input->amountAssetCode,
                    $input->amountPointer,
                    $input->amountAssetCodePointer,
                )),
                RuleValueType::PERCENTAGE => DeclaredRuleValue::rate(self::scale($input)),
                RuleValueType::TEXT => DeclaredRuleValue::text(
                    $input->text ?? throw new InvalidDeclaredRuleInput('This period carries a text value.'),
                ),
            };
        } catch (InvalidDeclaredRule $failure) {
            throw new InvalidDeclaredRuleInput($failure->getMessage(), previous: $failure);
        }
    }

    public static function period(DeclaredRuleInput $input): EffectivePeriod
    {
        $validFrom = self::businessDay($input->validFrom, 'start date');
        // A null end means "in force with no known end", never an expiry.
        $validTo = null === $input->validTo ? null : self::businessDay($input->validTo, 'end date');

        try {
            return new EffectivePeriod($validFrom, $validTo);
        } catch (InvalidCatalogEntry $failure) {
            throw new InvalidDeclaredRuleInput($failure->getMessage(), previous: $failure);
        }
    }

    public static function businessDay(string $value, string $subject): \DateTimeImmutable
    {
        try {
            return BusinessDay::fromIsoDate($value)->date;
        } catch (InvalidCatalogEntry $failure) {
            throw new InvalidDeclaredRuleInput(sprintf('The %s must be an ISO 8601 calendar day.', $subject), previous: $failure);
        }
    }

    /**
     * The scale of a rate period, single or tiered. A single rate is the
     * one-bracket case, so a client sends one shape whichever it means and no
     * screen has to guess whether a percentage covers a slice or the whole
     * balance.
     */
    private static function scale(DeclaredRuleInput $input): RateScale
    {
        $application = RateApplication::tryFrom($input->rateApplication ?? '')
            ?? throw new InvalidDeclaredRuleInput('A rate period states how its brackets apply: MARGINAL or FLAT_BY_BRACKET.');

        if ([] === $input->brackets) {
            throw new InvalidDeclaredRuleInput('A rate period carries at least one bracket.');
        }

        if (count($input->brackets) > self::MAX_BRACKETS) {
            throw new InvalidDeclaredRuleInput(sprintf('A rate scale carries at most %d brackets.', self::MAX_BRACKETS));
        }

        try {
            $brackets = [];
            foreach ($input->brackets as $bracket) {
                $brackets[] = new RateBracket(
                    self::decimal($bracket->lowerBound),
                    null === $bracket->upperBound ? null : self::decimal($bracket->upperBound),
                    self::decimal($bracket->percentage),
                );
            }

            return new RateScale($brackets, $application);
        } catch (InvalidCatalogEntry $failure) {
            // The tiling rules — starting at zero, meeting exactly, ending
            // without a limit — are stated back to the client rather than
            // swallowed: a scale with a gap leaves amounts with no rate at all.
            throw new InvalidDeclaredRuleInput($failure->getMessage(), previous: $failure);
        }
    }

    private static function decimal(string $value): DecimalValue
    {
        try {
            return DecimalValue::fromString($value);
        } catch (MalformedDecimal|PrecisionExceeded $failure) {
            throw new InvalidDeclaredRuleInput($failure->getMessage(), previous: $failure);
        }
    }
}
