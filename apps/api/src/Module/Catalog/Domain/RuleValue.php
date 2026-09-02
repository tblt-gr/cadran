<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\DecimalValue;

/**
 * The value of a catalogue rule, in exactly one of the three shapes a rule
 * kind may take.
 *
 * A percentage is stored as written in the source — `1.7` reads 1.7 % — so no
 * consumer converts a rate to display it. A text value is an uppercase token,
 * never a sentence: the interface translates the token, which keeps regulatory
 * wording out of the React bundle and out of the database alike.
 */
final readonly class RuleValue
{
    public const int MAX_TEXT_LENGTH = 64;

    private const string TEXT_PATTERN = '/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/D';

    private function __construct(
        public RuleValueType $type,
        public ?AssetAmount $amount,
        public ?DecimalValue $percentage,
        public ?string $text,
    ) {
    }

    public static function amount(AssetAmount $amount): self
    {
        return new self(RuleValueType::AMOUNT, $amount, null, null);
    }

    public static function percentage(DecimalValue $percentage): self
    {
        return new self(RuleValueType::PERCENTAGE, null, $percentage, null);
    }

    public static function text(string $token): self
    {
        if (strlen($token) > self::MAX_TEXT_LENGTH || 1 !== preg_match(self::TEXT_PATTERN, $token)) {
            throw new InvalidCatalogEntry(sprintf('A rule text value is an uppercase token of at most %d characters.', self::MAX_TEXT_LENGTH));
        }

        return new self(RuleValueType::TEXT, null, null, $token);
    }
}
