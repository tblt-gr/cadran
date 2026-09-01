<?php

declare(strict_types=1);

namespace App\Module\Reference\Domain;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\RoundingMode;

/**
 * A currency or crypto-asset of the read-only system reference: the unit every
 * financial figure is denominated in, with the precisions and rounding rule it
 * is read against.
 */
final readonly class Asset
{
    public const int MAX_DISPLAY_NAME_LENGTH = 64;

    public function __construct(
        public AssetCode $code,
        public AssetKind $kind,
        public string $displayName,
        public AssetPrecision $precision,
        public RoundingMode $roundingMode,
    ) {
        if ('' === trim($displayName) || mb_strlen($displayName) > self::MAX_DISPLAY_NAME_LENGTH) {
            throw new \InvalidArgumentException(sprintf('An asset name is between 1 and %d characters.', self::MAX_DISPLAY_NAME_LENGTH));
        }
    }

    /**
     * Turns a submitted literal into an amount of this asset, refusing anything
     * this asset cannot store as written. This is the boundary the exactness
     * invariant relies on: it runs before persistence, so a figure is never
     * shortened on its way to NUMERIC(50,24).
     */
    public function amount(string $literal): AssetAmount
    {
        $value = DecimalValue::fromString($literal);
        $value->assertScaleAtMost($this->precision->storage);

        return new AssetAmount($value, $this->code);
    }

    /**
     * The smallest amount this asset shows: one unit of its display precision.
     * Interfaces need it to size an input step or a placeholder, and computing
     * it here keeps that decimal a server-produced canonical string.
     */
    public function displayStep(): AssetAmount
    {
        $literal = 0 === $this->precision->display
            ? '1'
            : '0.'.str_repeat('0', $this->precision->display - 1).'1';

        return new AssetAmount(DecimalValue::fromString($literal), $this->code);
    }
}
