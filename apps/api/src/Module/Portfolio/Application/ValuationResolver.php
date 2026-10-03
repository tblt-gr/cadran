<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\Valuation\PriceEntry;
use App\Module\Portfolio\Domain\Valuation\ValuationCalculator;
use App\Module\Portfolio\Domain\Valuation\ValuationDetail;
use App\Module\Portfolio\Domain\Valuation\ValuationRules;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reference\Domain\Asset;

/**
 * Turns what the client typed into validated, exact valuation figures. It reads the account, its operations and
 * its instruments and writes nothing.
 */
final readonly class ValuationResolver
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function __construct(private AssetCatalog $assets, private ValuationHoldings $holdings)
    {
    }

    /** A valuation date is a real ISO 8601 day: parsed before any lock so the period guard can read it. */
    public static function parseDay(string $literal, string $code): \DateTimeImmutable
    {
        $date = 1 === preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $literal)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $literal, new \DateTimeZone('UTC'))
            : false;
        if (false === $date || $date->format('Y-m-d') !== $literal) {
            throw new InvalidPortfolioOperation('A date is a real ISO 8601 calendar day.', $code);
        }

        return $date;
    }

    /** The date rules shared by a draft and a record: on or after the opening, on or before today. */
    public static function assertValuationDay(Account $account, \DateTimeImmutable $valuedOn, \DateTimeImmutable $today): void
    {
        $day = $valuedOn->format('Y-m-d');
        if ($day < $account->openedOn->format('Y-m-d') || $day > $today->format('Y-m-d')) {
            throw new InvalidPortfolioOperation('The valuation date lies before the account opening or in the future.', 'invalid_valuation_date');
        }
    }

    public function resolve(Account $account, ValuationInput $input, \DateTimeImmutable $today): ResolvedValuation
    {
        $detail = ValuationDetail::tryFrom($input->detail) ?? throw new InvalidPortfolioOperation('A valuation is TOTAL or POSITIONS.', 'invalid_valuation_detail');
        $valuedOn = self::parseDay($input->valuedOn, 'invalid_valuation_date');
        self::assertValuationDay($account, $valuedOn, $today);
        $asset = $this->assets->findByCode($account->assetCode) ?? throw new \LogicException('The account asset is missing from the reference.');
        $this->assertFields($detail, $input);
        if (null !== $input->note && ('' === $input->note || mb_strlen($input->note) > ValuationRules::MAX_NOTE_LENGTH)) {
            throw InvalidPortfolioOperation::field('invalid_valuation_payload', 'note');
        }

        $observedCash = self::amount($input->observedCash, $asset, 'observedCash');
        $observedTotal = self::amount($input->observedTotal, $asset, 'observedTotal');
        [$held, $instruments] = $this->holdings->held($account->workspace, $account->id, $valuedOn);
        $derivedCash = $this->holdings->derivedCash($account->workspace, $account->id, $valuedOn);

        try {
            if (ValuationDetail::TOTAL === $detail) {
                $total = self::amount($input->totalValue, $asset, 'totalValue') ?? throw InvalidPortfolioOperation::field('invalid_valuation_total', 'totalValue');
                if ($total->isNegative()) {
                    throw InvalidPortfolioOperation::field('invalid_valuation_total', 'totalValue');
                }
                $figures = ValuationCalculator::total($derivedCash, $observedCash, $total, $held);
            } else {
                $figures = ValuationCalculator::positions(
                    $valuedOn, $asset->precision->display, $derivedCash, $observedCash, $held,
                    $this->priceEntries($input->prices ?? [], $instruments, $valuedOn),
                );
            }
        } catch (PrecisionExceeded) {
            throw new InvalidPortfolioOperation('A valuation figure is too large to be stored exactly.', 'invalid_valuation_total');
        }

        return new ResolvedValuation($valuedOn, $detail, $figures, $derivedCash, $observedTotal, $input->note, array_values($instruments), $asset->precision->display);
    }

    private function assertFields(ValuationDetail $detail, ValuationInput $input): void
    {
        if (ValuationDetail::TOTAL === $detail) {
            foreach (['prices' => null !== $input->prices, 'observedTotal' => null !== $input->observedTotal] as $field => $present) {
                if ($present) {
                    throw InvalidPortfolioOperation::field('valuation_field_not_allowed', $field);
                }
            }

            return;
        }
        if (null !== $input->totalValue) {
            throw InvalidPortfolioOperation::field('valuation_field_not_allowed', 'totalValue');
        }
    }

    /**
     * @param list<PriceInput>          $inputs
     * @param array<string, Instrument> $held   the instruments held on the date
     *
     * @return array<string, PriceEntry>
     */
    private function priceEntries(array $inputs, array $held, \DateTimeImmutable $valuedOn): array
    {
        $entries = [];
        foreach ($inputs as $index => $input) {
            $path = '/prices/'.$index;
            $instrument = $held[$input->instrumentId] ?? null;
            if (1 !== preg_match(self::UUID_PATTERN, $input->instrumentId) || null === $instrument) {
                throw InvalidPortfolioOperation::field('unknown_valuation_instrument', $path.'/instrumentId');
            }
            if (isset($entries[$input->instrumentId])) {
                throw InvalidPortfolioOperation::field('invalid_valuation_price', $path.'/instrumentId');
            }
            $entries[$input->instrumentId] = new PriceEntry(self::price($input, $instrument, $path), self::priceDate($input, $valuedOn, $path));
        }
        foreach (array_keys($held) as $instrumentId) {
            if (!isset($entries[$instrumentId])) {
                throw InvalidPortfolioOperation::field('valuation_price_entry_missing', '/prices');
            }
        }

        return $entries;
    }

    private static function price(PriceInput $input, Instrument $instrument, string $path): ?DecimalValue
    {
        if (null === $input->price) {
            return null;
        }
        try {
            $price = DecimalValue::fromString($input->price);
        } catch (MalformedDecimal) {
            throw InvalidPortfolioOperation::field('invalid_valuation_price', $path.'/price');
        } catch (PrecisionExceeded) {
            throw InvalidPortfolioOperation::field('price_precision_exceeded', $path.'/price');
        }
        if ($price->isNegative() || 0 === $price->compareTo(DecimalValue::zero())) {
            throw InvalidPortfolioOperation::field('invalid_valuation_price', $path.'/price');
        }
        if ($price->scale() > $instrument->pricePrecision) {
            throw InvalidPortfolioOperation::field('price_precision_exceeded', $path.'/price');
        }

        return $price;
    }

    private static function priceDate(PriceInput $input, \DateTimeImmutable $valuedOn, string $path): ?\DateTimeImmutable
    {
        if (null === $input->price) {
            if (null !== $input->priceDate) {
                throw InvalidPortfolioOperation::field('invalid_price_date', $path.'/priceDate');
            }

            return null;
        }
        if (null === $input->priceDate) {
            throw InvalidPortfolioOperation::field('invalid_price_date', $path.'/priceDate');
        }
        try {
            $date = self::parseDay($input->priceDate, 'invalid_price_date');
        } catch (InvalidPortfolioOperation) {
            throw InvalidPortfolioOperation::field('invalid_price_date', $path.'/priceDate');
        }
        if ($date->format('Y-m-d') > $valuedOn->format('Y-m-d')) {
            throw InvalidPortfolioOperation::field('invalid_price_date', $path.'/priceDate');
        }

        return $date;
    }

    private static function amount(?AmountInput $input, Asset $asset, string $field): ?DecimalValue
    {
        if (null === $input) {
            return null;
        }
        if ($input->assetCode !== $asset->code->toString()) {
            throw InvalidPortfolioOperation::field('operation_asset_mismatch', $field);
        }
        try {
            return $asset->amount($input->value)->value;
        } catch (MalformedDecimal|PrecisionExceeded) {
            throw InvalidPortfolioOperation::field('invalid_valuation_total', $field);
        }
    }
}
