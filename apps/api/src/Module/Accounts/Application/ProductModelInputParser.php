<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\InvalidProductModel;
use App\Module\Accounts\Domain\ModelRule;
use App\Module\Accounts\Domain\ModelRuleValue;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\RateApplication;
use App\Module\Catalog\Domain\RateBracket;
use App\Module\Catalog\Domain\RateScale;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\RuleValueType;
use App\Module\Catalog\Domain\WrapperKind;
use App\Module\Catalog\Domain\YieldKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;

/**
 * Turns the strings a client submits into the closed types the domain accepts.
 *
 * Every unknown token is refused rather than defaulted. A rule whose value
 * does not match the shape its kind declares is refused here too, before any
 * value object is built, so a client is told what it sent wrong instead of
 * being handed the domain's own wording.
 */
final class ProductModelInputParser
{
    /**
     * A submitted scale is bounded: a client cannot make one request build a
     * thousand brackets the database then has to store and re-read.
     */
    public const int MAX_BRACKETS = 20;
    public const int MAX_RULES_PER_REQUEST = 20;

    public static function family(string $value): AccountKind
    {
        return AccountKind::tryFrom($value) ?? throw new InvalidProductModelInput('The model family must be a supported account kind.');
    }

    public static function wrapperKind(string $value): WrapperKind
    {
        return WrapperKind::tryFrom($value) ?? throw new InvalidProductModelInput('The model envelope must be a supported wrapper kind.');
    }

    public static function yieldKind(string $value): YieldKind
    {
        return YieldKind::tryFrom($value) ?? throw new InvalidProductModelInput('The model yield must be a supported yield kind.');
    }

    public static function valuationMode(string $value): AccountValuationMode
    {
        return AccountValuationMode::tryFrom($value) ?? throw new InvalidProductModelInput('The model valuation mode must be a supported mode.');
    }

    /**
     * @param list<string> $values
     */
    public static function capabilities(array $values): ProductCapabilities
    {
        try {
            return ProductCapabilities::fromStrings($values);
        } catch (InvalidCatalogEntry $failure) {
            throw new InvalidProductModelInput($failure->getMessage(), previous: $failure);
        }
    }

    public static function rule(ModelRuleInput $input, string $id): ModelRule
    {
        $kind = RuleKind::tryFrom($input->kind) ?? throw new InvalidProductModelInput('The rule kind must be a supported kind.');

        try {
            return new ModelRule(
                id: $id,
                kind: $kind,
                value: self::value($kind, $input),
                period: self::period($input),
            );
        } catch (InvalidProductModel $failure) {
            throw new InvalidProductModelInput($failure->getMessage(), previous: $failure);
        }
    }

    private static function value(RuleKind $kind, ModelRuleInput $input): ModelRuleValue
    {
        return match ($kind->valueType()) {
            RuleValueType::AMOUNT => ModelRuleValue::amount(new AssetAmount(
                self::decimal($input->amount ?? throw new InvalidProductModelInput('A ceiling period carries an amount.')),
                self::assetCode($input->amountAssetCode ?? throw new InvalidProductModelInput('A ceiling amount carries the asset it is denominated in.')),
            )),
            RuleValueType::PERCENTAGE => ModelRuleValue::rate(self::scale($input)),
            RuleValueType::TEXT => ModelRuleValue::text(
                $input->text ?? throw new InvalidProductModelInput('This period carries a text value.'),
            ),
        };
    }

    /**
     * The scale of a rate period, single or tiered. A single rate is the
     * one-bracket case, so a client sends one shape whichever it means and no
     * screen has to guess whether a percentage covers a slice or the whole
     * balance.
     */
    private static function scale(ModelRuleInput $input): RateScale
    {
        $application = RateApplication::tryFrom($input->rateApplication ?? '')
            ?? throw new InvalidProductModelInput('A rate period states how its brackets apply: MARGINAL or FLAT_BY_BRACKET.');

        if ([] === $input->brackets) {
            throw new InvalidProductModelInput('A rate period carries at least one bracket.');
        }

        if (count($input->brackets) > self::MAX_BRACKETS) {
            throw new InvalidProductModelInput(sprintf('A rate scale carries at most %d brackets.', self::MAX_BRACKETS));
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
            throw new InvalidProductModelInput($failure->getMessage(), previous: $failure);
        }
    }

    private static function period(ModelRuleInput $input): EffectivePeriod
    {
        $validFrom = self::businessDay($input->validFrom, 'start date');
        // A null end means "in force with no known end", never an expiry.
        $validTo = null === $input->validTo ? null : self::businessDay($input->validTo, 'end date');

        try {
            return new EffectivePeriod($validFrom, $validTo);
        } catch (InvalidCatalogEntry $failure) {
            throw new InvalidProductModelInput($failure->getMessage(), previous: $failure);
        }
    }

    public static function businessDay(string $value, string $subject): \DateTimeImmutable
    {
        try {
            return BusinessDay::fromIsoDate($value)->date;
        } catch (InvalidCatalogEntry $failure) {
            throw new InvalidProductModelInput(sprintf('The %s must be an ISO 8601 calendar day.', $subject), previous: $failure);
        }
    }

    public static function assetCode(string $value): AssetCode
    {
        try {
            return AssetCode::fromString($value);
        } catch (\InvalidArgumentException $failure) {
            throw new InvalidProductModelInput('The amount asset must be a supported asset code.', previous: $failure);
        }
    }

    private static function decimal(string $value): DecimalValue
    {
        try {
            return DecimalValue::fromString($value);
        } catch (MalformedDecimal|PrecisionExceeded $failure) {
            throw new InvalidProductModelInput($failure->getMessage(), previous: $failure);
        }
    }
}
