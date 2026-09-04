<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\RateScale;
use App\Module\Catalog\Domain\RuleValueType;
use App\Module\Foundation\Domain\AssetAmount;

/**
 * The value of one rule period of a workspace product model, in exactly one of
 * the three shapes a rule kind may take.
 *
 * A rate is held as a complete bracket scale rather than as a lone percentage,
 * which is the difference with the system catalogue: a workspace models the
 * product its own institution sells, and a promotional or tiered rate has no
 * single figure to record. A single rate is the one-bracket case of the same
 * shape, so no consumer reads a workspace rate two ways.
 */
final readonly class ModelRuleValue
{
    public const int MAX_TEXT_LENGTH = 64;

    private const string TEXT_PATTERN = '/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/D';

    private function __construct(
        public RuleValueType $type,
        public ?AssetAmount $amount,
        public ?RateScale $scale,
        public ?string $text,
    ) {
    }

    public static function amount(AssetAmount $amount): self
    {
        if ($amount->value->isNegative()) {
            // A ceiling below zero would refuse every balance, including an
            // empty account, which is never what a holder meant to record.
            throw new InvalidProductModel('A model amount is zero or above.');
        }

        return new self(RuleValueType::AMOUNT, $amount, null, null);
    }

    public static function rate(RateScale $scale): self
    {
        return new self(RuleValueType::PERCENTAGE, null, $scale, null);
    }

    /**
     * A text value is an uppercase token, never a sentence: the interface
     * translates the token, which keeps contractual wording out of the bundle
     * and out of the database alike.
     */
    public static function text(string $token): self
    {
        if (strlen($token) > self::MAX_TEXT_LENGTH || 1 !== preg_match(self::TEXT_PATTERN, $token)) {
            throw new InvalidProductModel(sprintf('A model rule text value is an uppercase token of at most %d characters.', self::MAX_TEXT_LENGTH));
        }

        return new self(RuleValueType::TEXT, null, null, $token);
    }
}
