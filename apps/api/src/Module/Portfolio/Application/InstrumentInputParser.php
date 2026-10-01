<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Portfolio\Domain\AssetClass;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InstrumentRegion;
use App\Module\Portfolio\Domain\InstrumentType;
use App\Module\Portfolio\Domain\InvalidInstrument;
use App\Module\Portfolio\Domain\Isin;
use App\Module\Portfolio\Domain\Ticker;
use App\Module\Portfolio\Domain\VenueMic;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reference\Domain\AssetKind;

final readonly class InstrumentInputParser
{
    public function __construct(private AssetCatalog $assets)
    {
    }

    public function parse(CreateInstrumentInput|UpdateInstrumentInput $input, ?Instrument $current = null): ParsedInstrumentInput
    {
        try {
            $isin = null === $input->isin || '' === trim($input->isin) ? null : Isin::fromString($input->isin);
            $ticker = null === $input->ticker || '' === trim($input->ticker) ? null : Ticker::fromString($input->ticker);
            $venue = null === $input->venueMic || '' === trim($input->venueMic) ? null : VenueMic::fromString($input->venueMic);
            $type = InstrumentType::tryFrom($input->instrumentType)
                ?? throw new InvalidInstrument('The instrument type is invalid.', 'invalid_instrument_type');
            $class = null === $input->assetClass ? null : AssetClass::tryFrom($input->assetClass);
            $region = null === $input->region ? null : InstrumentRegion::tryFrom($input->region);
            if ((null !== $input->assetClass && null === $class) || (null !== $input->region && null === $region)) {
                throw new InvalidInstrument('The instrument classification is invalid.', 'invalid_instrument_classification');
            }
            $currency = AssetCode::fromString(strtoupper(trim($input->currencyCode)));
            $asset = $this->assets->findByCode($currency);
            if (null === $asset || AssetKind::FIAT !== $asset->kind) {
                throw new InvalidInstrument('The instrument currency must be a supported FIAT asset.', 'unsupported_instrument_currency');
            }

            return new ParsedInstrumentInput(
                name: trim($input->name),
                isin: $isin,
                ticker: $ticker,
                venueMic: $venue,
                instrumentType: $type,
                assetClass: $class,
                region: $region,
                currencyCode: $currency,
                quantityPrecision: $input->quantityPrecision ?? (null === $current ? 6 : $current->quantityPrecision),
                pricePrecision: $input->pricePrecision ?? (null === $current ? 4 : $current->pricePrecision),
            );
        } catch (InvalidInstrument $exception) {
            throw new InvalidInstrumentInput($exception->getMessage(), $exception->problemCode, $exception);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidInstrumentInput($exception->getMessage(), 'unsupported_instrument_currency', $exception);
        }
    }
}
