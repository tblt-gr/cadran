<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

use App\Module\Accounts\Domain\Account;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Portfolio\Application\ValuationResolver;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\InstrumentStatus;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\Valuation\ValuationCalculator;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reference\Domain\Asset;

/**
 * Checks a takeover request against the account and its instruments and collects every problem, so a preview shows
 * them all and a record refuses on the same list. It reads and writes nothing else.
 */
final readonly class TakeoverResolver
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function __construct(private AssetCatalog $assets, private InstrumentRepository $instruments)
    {
    }

    /** The takeover day as a calendar date, or null with its error when the literal is not a real day. */
    public static function parseDate(string $literal): ?\DateTimeImmutable
    {
        try {
            return ValuationResolver::parseDay($literal, 'invalid_date');
        } catch (InvalidPortfolioOperation) {
            return null;
        }
    }

    public function resolve(Account $account, TakeoverInput $input, \DateTimeImmutable $today): ResolvedTakeover
    {
        $asset = $this->assets->findByCode($account->assetCode) ?? throw new \LogicException('The account asset is missing from the reference.');
        $errors = [];
        $date = self::parseDate($input->takenOverOn);
        if (null === $date) {
            $errors[] = new TakeoverError(null, 'takenOverOn', 'invalid_date');
        } elseif ($date->format('Y-m-d') < $account->openedOn->format('Y-m-d')) {
            $errors[] = new TakeoverError(null, 'takenOverOn', 'before_account_opening');
        } elseif ($date->format('Y-m-d') > $today->format('Y-m-d')) {
            $errors[] = new TakeoverError(null, 'takenOverOn', 'in_the_future');
        }

        $cash = $this->cash($input->cash, $asset, $errors);
        $lines = [];
        $count = count($input->lines);
        if ($count < 1 || $count > TakeoverInput::MAX_LINES) {
            $errors[] = new TakeoverError(null, 'lines', 'lines_count');
        } else {
            $lines = $this->lines($account, $asset, $input->lines, $date, $errors);
        }

        return new ResolvedTakeover($lines, $cash, $errors, $asset->precision->display);
    }

    /** @param list<TakeoverError> $errors */
    private function cash(?string $literal, Asset $asset, array &$errors): ?DecimalValue
    {
        if (null === $literal) {
            return null;
        }
        $value = self::amount($literal, $asset, null, 'cash', $errors);
        if (null !== $value && $value->isNegative()) {
            $errors[] = new TakeoverError(null, 'cash', 'negative');

            return null;
        }

        return $value;
    }

    /**
     * @param list<TakeoverLineInput> $inputs
     * @param list<TakeoverError>     $errors
     *
     * @return list<ResolvedTakeoverLine>
     */
    private function lines(Account $account, Asset $asset, array $inputs, ?\DateTimeImmutable $date, array &$errors): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (TakeoverLineInput $line): string => $line->instrumentId, $inputs),
            static fn (string $id): bool => 1 === preg_match(self::UUID_PATTERN, $id),
        )));
        $known = [];
        foreach ($this->instruments->findMany($account->workspace, $ids) as $instrument) {
            $known[$instrument->id] = $instrument;
        }

        $seen = [];
        $lines = [];
        foreach ($inputs as $offset => $input) {
            $number = $offset + 1;
            $instrument = $known[$input->instrumentId] ?? null;
            // An unknown instrument leaves the scales below unbounded (null) because that line already errors.
            if (null === $instrument) {
                $errors[] = new TakeoverError($number, 'instrumentId', 'unknown_instrument');
            } elseif (InstrumentStatus::ACTIVE !== $instrument->status) {
                $errors[] = new TakeoverError($number, 'instrumentId', 'instrument_inactive');
            } elseif (!$instrument->currencyCode->equals($account->assetCode)) {
                $errors[] = new TakeoverError($number, 'instrumentId', 'instrument_currency_mismatch');
            } elseif (isset($seen[$instrument->id])) {
                $errors[] = new TakeoverError($number, 'instrumentId', 'duplicate_instrument');
            }
            $seen[$input->instrumentId] = true;

            $quantity = self::figure($input->quantity, $instrument?->quantityPrecision, 'quantity', $number, 'quantity_precision_exceeded', $errors, true);
            $price = self::figure($input->price, $instrument?->pricePrecision, 'price', $number, 'price_precision_exceeded', $errors, true);
            $cost = null === $input->costBasis ? null : self::amount($input->costBasis, $asset, $number, 'costBasis', $errors);
            if (null !== $cost && $cost->isNegative()) {
                $errors[] = new TakeoverError($number, 'costBasis', 'negative');
                $cost = null;
            }
            $value = null;
            if (null !== $quantity && null !== $price) {
                try {
                    $value = ValuationCalculator::marketValue($quantity, $price, $asset->precision->display);
                } catch (PrecisionExceeded) {
                    $errors[] = new TakeoverError($number, 'price', 'value_too_large');
                }
            }
            $lines[] = new ResolvedTakeoverLine($input->instrumentId, $instrument, $quantity, $cost, $this->priceDate($input->priceDate, $date, $number, $errors), $value);
        }

        return $lines;
    }

    /**
     * A price date is optional and defaults to the takeover day; it can not lie after it.
     *
     * @param list<TakeoverError> $errors
     */
    private function priceDate(?string $literal, ?\DateTimeImmutable $takenOverOn, int $line, array &$errors): ?\DateTimeImmutable
    {
        if (null === $literal) {
            return $takenOverOn;
        }
        $day = self::parseDate($literal);
        if (null === $day) {
            $errors[] = new TakeoverError($line, 'priceDate', 'invalid_date');
        } elseif (null !== $takenOverOn && $day > $takenOverOn) {
            $errors[] = new TakeoverError($line, 'priceDate', 'price_date_after_takeover');

            return null;
        }

        return $day;
    }

    /**
     * A strictly positive figure within the scale its instrument allows.
     *
     * @param list<TakeoverError> $errors
     */
    private static function figure(string $literal, ?int $scale, string $field, int $line, string $precisionCode, array &$errors, bool $positive): ?DecimalValue
    {
        try {
            $value = DecimalValue::fromString($literal);
        } catch (MalformedDecimal) {
            $errors[] = new TakeoverError($line, $field, 'invalid_decimal');

            return null;
        } catch (PrecisionExceeded) {
            $errors[] = new TakeoverError($line, $field, 'precision_exceeded');

            return null;
        }
        if ($positive && ($value->isNegative() || 0 === $value->compareTo(DecimalValue::zero()))) {
            $errors[] = new TakeoverError($line, $field, 'not_positive');

            return null;
        }
        if (null !== $scale && $value->scale() > $scale) {
            $errors[] = new TakeoverError($line, $field, $precisionCode);

            return null;
        }

        return $value;
    }

    /** @param list<TakeoverError> $errors */
    private static function amount(string $literal, Asset $asset, ?int $line, string $field, array &$errors): ?DecimalValue
    {
        try {
            return $asset->amount($literal)->value;
        } catch (MalformedDecimal) {
            $errors[] = new TakeoverError($line, $field, 'invalid_decimal');
        } catch (PrecisionExceeded) {
            $errors[] = new TakeoverError($line, $field, 'precision_exceeded');
        }

        return null;
    }
}
