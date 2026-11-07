<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationDetail;
use App\Module\Portfolio\Domain\Valuation\ValuationFlag;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Pure aggregation of the retained valuations of the portfolio accounts by instrument. The lines
 * come from the frozen position snapshots, so the lines of one account sum to its securities value.
 *
 * @phpstan-import-type AccountLine from HoldingRow
 * @phpstan-import-type Summary from HoldingsAccountSummaries
 */
final class HoldingsAggregator
{
    public const int MAX_ITEMS = 1000;
    public const array SORTS = ['VALUE_DESC', 'GAIN_DESC', 'PERFORMANCE_DESC', 'NAME_ASC'];

    /**
     * @param list<HoldingsAccount> $accounts the accounts in label order, already capped
     *
     * @return array<string, mixed>
     */
    public static function aggregate(array $accounts, \DateTimeImmutable $asOf, string $sort, bool $truncated, ?string $instrumentId = null): array
    {
        $byInstrument = [];
        $unsplit = [];
        $contributing = [];
        $cash = '0';
        $cashMissing = false;
        $warnings = ['STALE_PRICES' => [], 'POSITIONS_CHANGED' => []];
        foreach ($accounts as $account) {
            $valuation = $account->view?->valuation;
            if (null === $account->view || null === $valuation) {
                $cashMissing = true;
                $unsplit[] = self::unsplit($account, 'NO_VALUATION', null);
                continue;
            }
            $cash = ExactDecimal::addForResponse($cash, $valuation->cash->toString());
            $reason = match (true) {
                ValuationCompleteness::PARTIAL === $valuation->completeness => 'PARTIAL_VALUATION',
                ValuationDetail::TOTAL === $valuation->detail => 'TOTAL_VALUATION',
                default => null,
            };
            if (null !== $reason) {
                $unsplit[] = self::unsplit($account, $reason, $valuation->securitiesValue?->toString());
                continue;
            }
            foreach ($account->view->flags as $flag) {
                if (ValuationFlag::STALE_PRICES === $flag || ValuationFlag::POSITIONS_CHANGED === $flag) {
                    $warnings[$flag->value][] = $account->accountId;
                }
            }
            foreach ($valuation->positions as $position) {
                if (ExactDecimal::isZero($position->quantity)) {
                    continue;
                }
                $instrument = $account->view->instruments[$position->instrumentId] ?? throw new \LogicException('A frozen instrument is missing.');
                $byInstrument[$instrument->id]['instrument'] = $instrument;
                $byInstrument[$instrument->id]['lines'][] = ['account' => $account, 'position' => $position, 'valuedOn' => $valuation->valuedOn->format('Y-m-d')];
                $contributing[$account->accountId] = $valuation->valuedOn->format('Y-m-d');
            }
        }
        if (null !== $instrumentId) {
            $group = $byInstrument[$instrumentId] ?? null;
            $items = null === $group ? [] : [self::item($group['instrument'], $group['lines'])];
            $assets = array_unique(array_map(static fn (HoldingsAccount $account): string => $account->assetCode, $accounts));
            $mixed = count($assets) > 1;
            if ($mixed) {
                $items = array_map(static fn (HoldingRow $item): HoldingRow => $item->withoutAmounts(), $items);
            }
            // Keep the same whole-portfolio denominator as the Investments page, while computing only this item.
            $securities = self::sum(array_map(static fn (HoldingsAccount $account): ?string => $account->view?->valuation->securitiesValue?->toString(), $accounts));
            $items = self::share($items, $securities, $mixed);

            return ['items' => array_map(static fn (HoldingRow $item): array => $item->toArray(), $items), 'truncated' => $truncated];
        }
        if (count($byInstrument) > self::MAX_ITEMS) {
            throw new HoldingsTooLarge('The holdings span more distinct instruments than the read bound.');
        }

        $items = array_values(array_map(static fn (array $group): HoldingRow => self::item($group['instrument'], $group['lines']), $byInstrument));
        $assets = array_values(array_unique(array_map(static fn (HoldingsAccount $account): string => $account->assetCode, $accounts)));
        $mixed = count($assets) > 1;
        if ($mixed) {
            $items = array_map(static fn (HoldingRow $item): HoldingRow => $item->withoutAmounts(), $items);
        }
        $figures = HoldingsAccountSummaries::figures($accounts, $mixed);
        $totals = self::totals($items, $unsplit, $figures, $cash, $cashMissing, $mixed, [] === $accounts);
        $items = self::share($items, $totals['securitiesValue'], $mixed);
        $summaries = HoldingsAccountSummaries::withShares($figures, $totals['securitiesValue'], $mixed);
        usort($items, static fn (HoldingRow $left, HoldingRow $right): int => self::compare($left, $right, $sort));

        $dates = array_values($contributing);
        $mixedDates = [] !== $dates && min($dates) !== max($dates);
        $warningList = [];
        if ($mixedDates) {
            $warningList[] = ['code' => 'MIXED_VALUATION_DATES', 'accountIds' => array_keys($contributing)];
        }
        foreach (['STALE_PRICES', 'POSITIONS_CHANGED'] as $code) {
            if ([] !== $warnings[$code]) {
                $warningList[] = ['code' => $code, 'accountIds' => $warnings[$code]];
            }
        }
        if ($truncated) {
            $warningList[] = ['code' => 'TRUNCATED_ACCOUNTS', 'accountIds' => []];
        }

        return ['asOf' => $asOf->format('Y-m-d'), 'assetCode' => $mixed ? null : ($assets[0] ?? null),
            'valuedFrom' => [] === $dates ? null : min($dates), 'valuedTo' => [] === $dates ? null : max($dates),
            'quality' => match (true) {
                'MIXED_ASSETS' === $totals['reason'] || [] === $accounts => 'NOT_CALCULABLE',
                [] !== $warningList || null !== $totals['reason'] => 'DEGRADED',
                default => 'EXACT',
            },
            'warnings' => $warningList, 'totals' => $totals,
            'items' => array_map(static fn (HoldingRow $item): array => $item->toArray(), $items),
            'allocation' => self::allocation($items, $totals['securitiesValue'], $unsplit, $summaries),
            'unsplitAccounts' => $unsplit, 'accounts' => $summaries, 'truncated' => $truncated];
    }

    /** @return array{accountId: string, label: string, reason: string, securitiesValue: ?string} */
    private static function unsplit(HoldingsAccount $account, string $reason, ?string $securities): array
    {
        return ['accountId' => $account->accountId, 'label' => $account->label, 'reason' => $reason, 'securitiesValue' => $securities];
    }

    /**
     * @param list<array{account: HoldingsAccount, position: PositionSnapshot, valuedOn: string}> $lines
     */
    private static function item(Instrument $instrument, array $lines): HoldingRow
    {
        $quantity = '0';
        $accounts = [];
        foreach ($lines as $line) {
            $quantity = ExactDecimal::addForResponse($quantity, $line['position']->quantity->toString());
            $accounts[] = self::accountLine($line);
        }
        $value = self::sum(array_map(static fn (array $line): ?string => $line['value'], $accounts));
        $cost = self::sum(array_map(static fn (array $line): ?string => $line['costBasis'], $accounts));
        $gain = null !== $value && null !== $cost ? ExactDecimal::subtractForResponse($value, $cost) : null;
        usort($accounts, static fn (array $left, array $right): int => self::nullsLast($left['value'], $right['value']) ?: self::labelKey($left['label']) <=> self::labelKey($right['label']));

        return new HoldingRow(
            ['id' => $instrument->id, 'name' => $instrument->name, 'isin' => $instrument->isin?->toString(),
                'ticker' => $instrument->ticker?->toString(), 'status' => $instrument->status->value, 'quantityPrecision' => $instrument->quantityPrecision],
            $instrument->instrumentType->value, $instrument->assetClass?->value, $instrument->region?->value,
            $quantity, $value, $cost, $gain, PortfolioUnrealizedGain::performance($gain, $cost),
            null === $cost || ExactDecimal::isZeroForResponse($quantity) ? null
                : (string) BigDecimal::of($cost)->dividedBy(BigDecimal::of($quantity), max(0, $instrument->pricePrecision), RoundingMode::HalfEven),
            match (true) {
                null === $value => 'MISSING_PRICE',
                null === $cost => 'UNKNOWN_LOT_COST',
                default => null,
            },
            self::lastPrice($lines), ['ratio' => null, 'percentDisplay' => null, 'reason' => 'MISSING_VALUE'], $accounts,
        );
    }

    /**
     * @param array{account: HoldingsAccount, position: PositionSnapshot, valuedOn: string} $line
     *
     * @return AccountLine
     */
    private static function accountLine(array $line): array
    {
        $position = $line['position'];
        $value = $position->marketValue?->toString();
        $cost = $position->averageCostBasis?->toString();
        $gain = null !== $value && null !== $cost ? ExactDecimal::subtractForResponse($value, $cost) : null;

        return ['accountId' => $line['account']->accountId, 'label' => $line['account']->label, 'quantity' => $position->quantity->toString(),
            'value' => $value, 'costBasis' => $cost, 'unrealizedGain' => $gain, 'performance' => PortfolioUnrealizedGain::performance($gain, $cost),
            'valuedOn' => $line['valuedOn'], 'priceDate' => $position->priceDate?->format('Y-m-d'), 'priceQuality' => $position->priceQuality->value];
    }

    /**
     * The price of the line with the latest price date; ties go to the latest valuation, then the account label.
     *
     * @param list<array{account: HoldingsAccount, position: PositionSnapshot, valuedOn: string}> $lines
     *
     * @return array{price: string, priceDate: string, priceQuality: string}|null
     */
    private static function lastPrice(array $lines): ?array
    {
        $priced = array_values(array_filter($lines, static fn (array $line): bool => null !== $line['position']->unitPrice && null !== $line['position']->priceDate));
        usort($priced, static fn (array $left, array $right): int => $right['position']->priceDate <=> $left['position']->priceDate
            ?: strcmp($right['valuedOn'], $left['valuedOn']) ?: self::labelKey($left['account']->label) <=> self::labelKey($right['account']->label));
        $last = $priced[0]['position'] ?? null;

        return null === $last || null === $last->unitPrice || null === $last->priceDate ? null
            : ['price' => $last->unitPrice->toString(), 'priceDate' => $last->priceDate->format('Y-m-d'), 'priceQuality' => $last->priceQuality->value];
    }

    /**
     * @param list<HoldingRow>                                                                        $items
     * @param list<array{accountId: string, label: string, reason: string, securitiesValue: ?string}> $unsplit
     * @param list<Summary>                                                                           $figures
     *
     * @return array{securitiesValue: ?string, costBasis: ?string, unrealizedGain: ?string, performance: ?string, cash: ?string, totalValue: ?string, reason: ?string, knownCost: ?array<string, mixed>}
     */
    private static function totals(array $items, array $unsplit, array $figures, string $cash, bool $cashMissing, bool $mixed, bool $noAccount): array
    {
        if ($mixed || $noAccount) {
            return ['securitiesValue' => null, 'costBasis' => null, 'unrealizedGain' => null, 'performance' => null, 'cash' => null,
                'totalValue' => null, 'reason' => $mixed ? 'MIXED_ASSETS' : 'NO_VALUATION', 'knownCost' => null];
        }
        $costs = [];
        foreach ($figures as $figure) {
            $costs[$figure['accountId']] = $figure['costBasis'];
        }
        $itemsSecurities = self::sum(array_map(static fn (HoldingRow $item): ?string => $item->value, $items));
        $unsplitSecurities = self::sum(array_map(static fn (array $account): ?string => $account['securitiesValue'], $unsplit));
        // The frozen cost of a total-valued account is the one the accounts overview exposes; unknown stays unknown.
        $cost = self::sum([...array_map(static fn (HoldingRow $item): ?string => $item->costBasis, $items),
            ...array_map(static fn (array $account): ?string => $costs[$account['accountId']] ?? null, $unsplit)]);
        $securities = null === $itemsSecurities || null === $unsplitSecurities ? null : ExactDecimal::addForResponse($itemsSecurities, $unsplitSecurities);
        $gain = null !== $securities && null !== $cost ? ExactDecimal::subtractForResponse($securities, $cost) : null;
        $total = null === $securities || $cashMissing ? null : ExactDecimal::addForResponse($securities, $cash);
        $reason = match (true) {
            null === $itemsSecurities => 'MISSING_PRICE',
            null === $unsplitSecurities => 'NO_VALUATION',
            null === $cost => 'UNKNOWN_LOT_COST',
            $cashMissing => 'NO_VALUATION',
            default => null,
        };

        $known = null;
        if ('UNKNOWN_LOT_COST' === $reason) {
            $entries = [];
            foreach ($figures as $figure) {
                if ('LINES' === $figure['detail']) {
                    array_push($entries, ...array_map(static fn (array $line): array => ['value' => $line['value'], 'cost' => $line['costBasis']], $figure['lines']));
                } elseif ('TOTAL' === $figure['detail']) {
                    $entries[] = ['value' => $figure['securitiesValue'], 'cost' => $figure['costBasis']];
                } elseif ('PARTIAL' === $figure['detail'] && null !== $figure['securitiesValue']) {
                    // Counted in the securities total, but nothing says what it cost: one excluded block.
                    $entries[] = ['value' => $figure['securitiesValue'], 'cost' => null];
                }
            }
            $known = HoldingsKnownCost::of($entries);
        }

        return ['securitiesValue' => $securities, 'costBasis' => $cost, 'unrealizedGain' => $gain,
            'performance' => PortfolioUnrealizedGain::performance($gain, $cost), 'cash' => $cashMissing ? null : $cash,
            'totalValue' => $total, 'reason' => $reason, 'knownCost' => $known];
    }

    /**
     * @param list<HoldingRow> $items
     *
     * @return list<HoldingRow>
     */
    private static function share(array $items, ?string $securities, bool $mixed): array
    {
        return array_map(static fn (HoldingRow $item): HoldingRow => $item->withShare(HoldingsShares::of($item->value, $securities, $mixed)), $items);
    }

    /**
     * @param list<HoldingRow>                                                                        $items
     * @param list<array{accountId: string, label: string, reason: string, securitiesValue: ?string}> $unsplit
     * @param list<Summary>                                                                           $summaries
     *
     * @return array{byInstrument: list<array{key: string, label: string, value: string, share: ?string}>, byAssetClass: list<array{key: string, label: string, value: string, share: ?string}>, byRegion: list<array{key: string, label: string, value: string, share: ?string}>, byAccount: list<array{key: string, label: string, value: string, share: ?string}>}
     */
    private static function allocation(array $items, ?string $securities, array $unsplit, array $summaries): array
    {
        if (null === $securities) {
            return ['byInstrument' => [], 'byAssetClass' => [], 'byRegion' => [], 'byAccount' => []];
        }
        $ordered = $items;
        usort($ordered, static fn (HoldingRow $left, HoldingRow $right): int => self::compare($left, $right, 'VALUE_DESC'));
        $instrument = [];
        foreach ($ordered as $item) {
            $instrument[] = ['key' => $item->instrument['id'], 'label' => $item->instrument['name'], 'value' => $item->value ?? throw new \LogicException('A known securities value has known lines.')];
        }
        $unsplitSlice = [];
        $unsplitSecurities = self::sum(array_map(static fn (array $account): ?string => $account['securitiesValue'], $unsplit));
        if (null !== $unsplitSecurities && ExactDecimal::compareForResponse($unsplitSecurities, '0') > 0) {
            $unsplitSlice = [['key' => 'UNSPLIT', 'label' => 'Comptes valorisés en total', 'value' => $unsplitSecurities]];
        }
        $byAccount = [];
        foreach ($summaries as $summary) {
            if (null !== $summary['securitiesValue'] && ExactDecimal::compareForResponse($summary['securitiesValue'], '0') > 0) {
                $byAccount[] = ['key' => $summary['accountId'], 'label' => $summary['label'], 'value' => $summary['securitiesValue']];
            }
        }

        return ['byInstrument' => HoldingsShares::slices([...$instrument, ...$unsplitSlice], $securities),
            'byAssetClass' => HoldingsShares::slices([...self::group($ordered, static fn (HoldingRow $item): ?string => $item->assetClass), ...$unsplitSlice], $securities),
            'byRegion' => HoldingsShares::slices([...self::group($ordered, static fn (HoldingRow $item): ?string => $item->region), ...$unsplitSlice], $securities),
            'byAccount' => HoldingsShares::slices($byAccount, $securities)];
    }

    /**
     * @param list<HoldingRow>              $items
     * @param \Closure(HoldingRow): ?string $by
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    private static function group(array $items, \Closure $by): array
    {
        $buckets = [];
        foreach ($items as $item) {
            $key = $by($item) ?? 'UNSPECIFIED';
            $buckets[$key] = ['key' => $key, 'label' => 'UNSPECIFIED' === $key ? 'Non renseignée' : $key,
                'value' => ExactDecimal::addForResponse($buckets[$key]['value'] ?? '0', $item->value ?? throw new \LogicException('A known securities value has known lines.'))];
        }
        $groups = array_values($buckets);
        usort($groups, static fn (array $left, array $right): int => ExactDecimal::compareForResponse($right['value'], $left['value']) ?: strcmp($left['key'], $right['key']));

        return $groups;
    }

    /** @param list<?string> $values null when one is null: a missing figure is never a silent partial sum */
    public static function sum(array $values): ?string
    {
        $sum = '0';
        foreach ($values as $value) {
            if (null === $value) {
                return null;
            }
            $sum = ExactDecimal::addForResponse($sum, $value);
        }

        return $sum;
    }

    private static function compare(HoldingRow $left, HoldingRow $right, string $sort): int
    {
        $order = match ($sort) {
            'GAIN_DESC' => self::nullsLast($left->unrealizedGain, $right->unrealizedGain),
            'PERFORMANCE_DESC' => self::nullsLast($left->performance, $right->performance),
            'NAME_ASC' => 0,
            default => self::nullsLast($left->value, $right->value),
        };

        return $order
            ?: self::labelKey($left->instrument['name']) <=> self::labelKey($right->instrument['name'])
            ?: strcmp($left->instrument['id'], $right->instrument['id']);
    }

    /** Case and accent insensitive ordering key of a label: "Épargne" sorts before "Zebra" without the intl extension. */
    public static function labelKey(string $label): string
    {
        return strtr(mb_strtolower($label), ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ú' => 'u',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae']);
    }

    /** Descending exact order, nulls last. */
    public static function nullsLast(?string $left, ?string $right): int
    {
        return match (true) {
            null === $left && null === $right => 0,
            null === $left => 1,
            null === $right => -1,
            default => ExactDecimal::compareForResponse($right, $left),
        };
    }
}
