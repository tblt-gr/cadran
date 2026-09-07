<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Reference\Application\AssetCatalog;

/**
 * Validates the common API amount fragment before it can reach persistence.
 */
final readonly class AmountInputParser
{
    public function __construct(private AssetCatalog $assets)
    {
    }

    public function __invoke(mixed $fragment, string $pointer): AssetAmount
    {
        if (!is_array($fragment)
            || array_is_list($fragment)
            || !array_key_exists('value', $fragment)
            || !array_key_exists('assetCode', $fragment)
        ) {
            throw new InvalidAmountInput($pointer, 'amount.missing');
        }

        return $this->parse(
            new AmountInput($fragment['value'], $fragment['assetCode'], $pointer),
            self::member($pointer, 'value'),
            self::member($pointer, 'assetCode'),
        );
    }

    /**
     * Adapts legacy flat request fields without inventing a JSON object that
     * the client never submitted.
     */
    public function fromFields(
        mixed $value,
        mixed $assetCode,
        string $valuePointer,
        string $assetCodePointer,
    ): AssetAmount {
        return $this->parse(
            new AmountInput($value, $assetCode, ''),
            $valuePointer,
            $assetCodePointer,
        );
    }

    private function parse(AmountInput $input, string $valuePointer, string $assetCodePointer): AssetAmount
    {
        if (!is_string($input->value)) {
            throw new InvalidAmountInput($valuePointer, 'amount.not_a_string');
        }
        if (!is_string($input->assetCode)) {
            throw new InvalidAmountInput($assetCodePointer, 'amount.not_a_string');
        }

        try {
            $value = DecimalValue::fromString($input->value);
        } catch (MalformedDecimal $failure) {
            throw new InvalidAmountInput($valuePointer, 'amount.not_canonical', $failure);
        } catch (PrecisionExceeded $failure) {
            throw new InvalidAmountInput($valuePointer, 'amount.precision_exceeded', $failure);
        }

        try {
            $code = AssetCode::fromString($input->assetCode);
        } catch (\InvalidArgumentException $failure) {
            throw new InvalidAmountInput($assetCodePointer, 'amount.asset_unknown', $failure);
        }

        $asset = $this->assets->findByCode($code);
        if (null === $asset) {
            throw new InvalidAmountInput($assetCodePointer, 'amount.asset_unknown');
        }

        try {
            $value->assertScaleAtMost($asset->precision->storage);
        } catch (PrecisionExceeded $failure) {
            throw new InvalidAmountInput($valuePointer, 'amount.asset_precision_exceeded', $failure);
        }

        return new AssetAmount($value, $asset->code);
    }

    private static function member(string $pointer, string $member): string
    {
        return rtrim($pointer, '/').'/'.$member;
    }
}
