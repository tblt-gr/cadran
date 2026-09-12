<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

/** A signed, inclusive amount range in one asset; either bound may be open, not both. */
final readonly class RuleAmountRange
{
    private function __construct(public ?DecimalValue $min, public ?DecimalValue $max, public AssetCode $asset)
    {
    }

    public static function fromDocument(mixed $document): self
    {
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidCategorizationRule('The amount condition must be an object.');
        }
        $keys = array_keys($document);
        sort($keys);
        if (['assetCode', 'max', 'min'] !== $keys || !is_string($document['assetCode'])) {
            throw new InvalidCategorizationRule('The amount condition carries exactly min, max and assetCode.');
        }
        $min = self::bound($document['min']);
        $max = self::bound($document['max']);
        if (null === $min && null === $max) {
            throw new InvalidCategorizationRule('The amount condition needs at least one bound.');
        }
        if (null !== $min && null !== $max && $min->compareTo($max) > 0) {
            throw new InvalidCategorizationRule('The amount condition minimum cannot exceed its maximum.');
        }
        try {
            $asset = AssetCode::fromString($document['assetCode']);
        } catch (\Exception $exception) {
            throw new InvalidCategorizationRule('The amount condition asset is invalid.', previous: $exception);
        }

        return new self($min, $max, $asset);
    }

    public function contains(AssetAmount $amount): bool
    {
        return $amount->asset->equals($this->asset)
            && (null === $this->min || $amount->value->compareTo($this->min) >= 0)
            && (null === $this->max || $amount->value->compareTo($this->max) <= 0);
    }

    /** @return array{min: ?string, max: ?string, assetCode: string} */
    public function toDocument(): array
    {
        return ['min' => $this->min?->toString(), 'max' => $this->max?->toString(), 'assetCode' => $this->asset->toString()];
    }

    private static function bound(mixed $value): ?DecimalValue
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidCategorizationRule('An amount bound must be a decimal string or null.');
        }
        try {
            return DecimalValue::fromString($value);
        } catch (\Exception $exception) {
            throw new InvalidCategorizationRule('An amount bound must be an exact decimal.', previous: $exception);
        }
    }
}
