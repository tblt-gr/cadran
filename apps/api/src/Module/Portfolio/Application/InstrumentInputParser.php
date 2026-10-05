<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\WorkspaceBaseCurrencyReader;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
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
    public function __construct(private AssetCatalog $assets, private WorkspaceBaseCurrencyReader $baseCurrency)
    {
    }

    public function parse(WorkspaceScope $workspace, CreateInstrumentInput|UpdateInstrumentInput $input, ?Instrument $current = null): ParsedInstrumentInput
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
            if (InstrumentType::EURO_FUND === $type) {
                return $this->euroFund($workspace, $input, $isin, $ticker, $venue, $class, $region);
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

    /** A euro fund carries no identifier, lives in the workspace currency and is counted in cents of it at a price of 1. */
    private function euroFund(WorkspaceScope $workspace, CreateInstrumentInput|UpdateInstrumentInput $input, ?Isin $isin, ?Ticker $ticker, ?VenueMic $venue, ?AssetClass $class, ?InstrumentRegion $region): ParsedInstrumentInput
    {
        if (null !== $isin || null !== $ticker || null !== $venue) {
            throw new InvalidInstrument('A euro fund carries no identifier or venue.', 'instrument_field_not_allowed');
        }
        $currency = $this->baseCurrency->currency($workspace);
        $asset = $this->assets->findByCode($currency) ?? throw new InvalidInstrument('The instrument currency must be a supported FIAT asset.', 'unsupported_instrument_currency');

        return new ParsedInstrumentInput(
            name: trim($input->name),
            isin: null,
            ticker: null,
            venueMic: null,
            instrumentType: InstrumentType::EURO_FUND,
            assetClass: $class,
            region: $region,
            currencyCode: $currency,
            quantityPrecision: $asset->precision->display,
            pricePrecision: 0,
        );
    }
}
