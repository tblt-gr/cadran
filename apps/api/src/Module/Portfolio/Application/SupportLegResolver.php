<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Portfolio\Domain\EuroFundPrice;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InstrumentType;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\LegDirection;
use App\Module\Portfolio\Domain\OperationLeg;
use App\Module\Reference\Domain\Asset;

final class SupportLegResolver
{
    /** @param list<OperationLegInput> $inputs
     * @param list<Instrument> $instruments
     *
     * @return list<OperationLeg>
     */
    public static function resolve(Account $account, Asset $asset, array $inputs, array $instruments): array
    {
        $byId = [];
        foreach ($instruments as $instrument) {
            $byId[$instrument->id] = $instrument;
        }
        $legs = [];
        foreach ($inputs as $offset => $input) {
            $index = $offset + 1;
            $instrument = $byId[$input->instrumentId];
            $direction = LegDirection::tryFrom($input->direction) ?? throw new InvalidPortfolioOperation('Invalid support direction.', 'invalid_leg_direction', ['legIndex' => $index]);
            if ($input->amount->assetCode !== $account->assetCode->toString()) {
                throw new InvalidPortfolioOperation('Leg amounts must use the account asset.', 'operation_asset_mismatch', ['legIndex' => $index]);
            }
            try {
                $amount = $asset->amount($input->amount->value)->value;
            } catch (MalformedDecimal|PrecisionExceeded) {
                throw new InvalidPortfolioOperation('A leg amount exceeds its precision.', 'invalid_operation_amount', ['legIndex' => $index]);
            }
            if (InstrumentType::EURO_FUND === $instrument->instrumentType) {
                EuroFundPrice::assert(self::figure($input->unitPrice, 'price_precision_exceeded', $index));
                if (null !== $input->quantity && 0 !== self::figure($input->quantity, 'quantity_precision_exceeded', $index)?->compareTo($amount)) {
                    throw new InvalidPortfolioOperation('Euro-fund quantity must equal its amount.', 'leg_amount_mismatch', ['legIndex' => $index, 'expectedAmount' => $amount->toString()]);
                }
                $quantity = $amount;
                $price = DecimalValue::fromString('1');
            } else {
                $quantity = self::figure($input->quantity, 'quantity_precision_exceeded', $index) ?? throw InvalidPortfolioOperation::field('operation_field_required', 'quantity');
                $price = self::figure($input->unitPrice, 'price_precision_exceeded', $index) ?? throw InvalidPortfolioOperation::field('operation_field_required', 'unitPrice');
            }
            $legs[] = new OperationLeg($index, $direction, $instrument->id, $quantity, $price, $amount, $instrument->quantityPrecision, $instrument->pricePrecision, $asset->precision->display);
        }

        return $legs;
    }

    private static function figure(?string $literal, string $code, int $index): ?DecimalValue
    {
        if (null === $literal) {
            return null;
        }
        try {
            return DecimalValue::fromString($literal);
        } catch (MalformedDecimal|PrecisionExceeded) {
            throw new InvalidPortfolioOperation('A leg figure is not an exact storable decimal.', $code, ['legIndex' => $index]);
        }
    }
}
