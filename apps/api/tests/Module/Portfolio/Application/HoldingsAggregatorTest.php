<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\HoldingsAccount;
use App\Module\Portfolio\Application\HoldingsAggregator;
use App\Module\Portfolio\Application\HoldingsKnownCost;
use App\Module\Portfolio\Application\HoldingsTooLarge;
use App\Module\Portfolio\Application\ValuationView;
use App\Module\Portfolio\Domain\AssetClass;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InstrumentRegion;
use App\Module\Portfolio\Domain\InstrumentSource;
use App\Module\Portfolio\Domain\InstrumentStatus;
use App\Module\Portfolio\Domain\InstrumentType;
use App\Module\Portfolio\Domain\Ticker;
use App\Module\Portfolio\Domain\Valuation\CashSource;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;
use App\Module\Portfolio\Domain\Valuation\PriceQuality;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationDetail;
use App\Module\Portfolio\Domain\Valuation\ValuationFlag;
use App\Module\Portfolio\Domain\Valuation\ValuationSource;
use PHPUnit\Framework\TestCase;

final class HoldingsAggregatorTest extends TestCase
{
    private const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    private const string PEA = '00000000-0000-7000-8000-0000000000c1';
    private const string CTO = '00000000-0000-7000-8000-0000000000c2';
    private const string W = '00000000-0000-7000-8000-000000000b01';
    private const string A = '00000000-0000-7000-8000-000000000b02';

    /** PEA 2026-10-31 holds W; CTO 2026-11-03 holds W and A (unknown cost). Hand computed in the ticket. */
    public function testTheWorkedExample(): void
    {
        $result = HoldingsAggregator::aggregate(self::example(), self::day('2026-11-04'), 'VALUE_DESC', false);

        $items = self::items($result);
        self::assertCount(2, $items);
        [$w, $a] = $items;
        self::assertSame(self::W, self::map($w['instrument'])['id']);
        self::assertSame('15', $w['quantity']);
        self::assertSame('1510.00', $w['value']);
        self::assertSame('1380.00', $w['costBasis']);
        self::assertSame('130.00', $w['unrealizedGain']);
        self::assertSame('0.094203', $w['performance']);
        self::assertSame('92.0000', $w['averageUnitCost']);
        self::assertNull($w['reason']);
        self::assertSame(['ratio' => '0.601593625498007968127490', 'percentDisplay' => '60.16', 'reason' => null], $w['share']);
        self::assertSame(['price' => '102.0000', 'priceDate' => '2026-11-03', 'priceQuality' => 'CURRENT'], $w['lastPrice']);
        $accounts = self::list($w['accounts']);
        self::assertSame(['PEA', 'CTO'], array_column($accounts, 'label'));
        self::assertSame('100.00', $accounts[0]['unrealizedGain']);
        self::assertSame('0.111111', $accounts[0]['performance']);
        self::assertSame('30.00', $accounts[1]['unrealizedGain']);
        self::assertSame('0.062500', $accounts[1]['performance']);

        self::assertSame('1000.00', $a['value']);
        self::assertNull($a['costBasis']);
        self::assertNull($a['unrealizedGain']);
        self::assertNull($a['performance']);
        self::assertNull($a['averageUnitCost']);
        self::assertSame('UNKNOWN_LOT_COST', $a['reason']);
        self::assertSame('39.84', self::map($a['share'])['percentDisplay']);

        $totals = self::map($result['totals']);
        self::assertSame('2510.00', $totals['securitiesValue']);
        self::assertNull($totals['costBasis']);
        self::assertNull($totals['unrealizedGain']);
        self::assertNull($totals['performance']);
        self::assertSame('170.00', $totals['cash']);
        self::assertSame('2680.00', $totals['totalValue']);
        self::assertSame('UNKNOWN_LOT_COST', $totals['reason']);
        self::assertSame('2026-10-31', $result['valuedFrom']);
        self::assertSame('2026-11-03', $result['valuedTo']);
        self::assertSame('DEGRADED', $result['quality']);
        self::assertSame([['code' => 'MIXED_VALUATION_DATES', 'accountIds' => [self::PEA, self::CTO]]], $result['warnings']);
        self::assertSame('EUR', $result['assetCode']);
        self::assertSame([], $result['unsplitAccounts']);
        self::assertFalse($result['truncated']);
        self::assertArrayNotHasKey('assetClass', $w);
    }

    public function testSortsKeepNullsLastThenNameThenId(): void
    {
        $by = static fn (string $sort): array => array_column(array_map(static fn (array $item): array => self::map($item['instrument']), self::items(
            HoldingsAggregator::aggregate(self::example(), self::day('2026-11-04'), $sort, false),
        )), 'name');
        self::assertSame(['ETF Monde', 'Action Alpha'], $by('VALUE_DESC'));
        self::assertSame(['ETF Monde', 'Action Alpha'], $by('GAIN_DESC'));
        self::assertSame(['ETF Monde', 'Action Alpha'], $by('PERFORMANCE_DESC'));
        self::assertSame(['Action Alpha', 'ETF Monde'], $by('NAME_ASC'));
    }

    public function testEqualValuesTieOnNameThenInstrumentId(): void
    {
        $pea = self::account(self::PEA, 'PEA', '2026-10-31', '0', [
            self::line('00000000-0000-7000-8000-000000000b09', '1', '10', '10', '10'),
            self::line(self::W, '1', '10', '10', '10'),
            self::line(self::A, '1', '10', '10', '10'),
        ]);
        $names = array_map(static fn (array $item): string => self::string(self::map($item['instrument'])['id']), self::items(HoldingsAggregator::aggregate([$pea], self::day('2026-11-04'), 'VALUE_DESC', false)));
        // Same value; the two named "ETF Monde" fall back to the identifier, "Action Alpha" comes first.
        self::assertSame([self::A, self::W, '00000000-0000-7000-8000-000000000b09'], $names);
    }

    public function testThePerAccountViewMatchesTheWorkedExampleAndAddsATotalValuedAccount(): void
    {
        $av = self::account('00000000-0000-7000-8000-0000000000c3', 'Assurance-vie', '2026-11-01', '10.00', [self::line(self::A, '1', null, null, '450.00')], detail: ValuationDetail::TOTAL, securities: '500.00');
        $result = HoldingsAggregator::aggregate([...self::example(), $av], self::day('2026-11-04'), 'NAME_ASC', false);

        $accounts = self::list($result['accounts']);
        // Securities descending: CTO 1510, AV 500, PEA 1000 -> CTO, PEA, AV.
        self::assertSame(['CTO', 'PEA', 'Assurance-vie'], array_column($accounts, 'label'));
        [$cto, $pea, $total] = $accounts;
        self::assertSame('LINES', $cto['detail']);
        self::assertSame('1510.00', $cto['securitiesValue']);
        self::assertNull($cto['costBasis']);
        self::assertNull($cto['unrealizedGain']);
        self::assertSame('120.00', $cto['cash']);
        self::assertSame('2026-11-03', $cto['valuedOn']);
        self::assertSame(['1000.00', '510.00'], array_column(self::list($cto['lines']), 'value'));
        self::assertSame(['Action Alpha', 'ETF Monde'], array_column(array_map(static fn (array $line): array => self::map($line['instrument']), self::list($cto['lines'])), 'name'));
        self::assertSame('1000.00', $pea['securitiesValue']);
        self::assertSame('900.00', $pea['costBasis']);
        self::assertSame('100.00', $pea['unrealizedGain']);
        self::assertSame('0.111111', $pea['performance']);
        // Denominator 1510 + 1000 + 500 = 3010.
        self::assertSame('33.22', self::map($pea['share'])['percentDisplay']);
        self::assertSame('TOTAL', $total['detail']);
        self::assertSame('500.00', $total['securitiesValue']);
        self::assertSame('450.00', $total['costBasis']);
        self::assertSame('50.00', $total['unrealizedGain']);
        self::assertSame('0.111111', $total['performance']);
        self::assertSame([], $total['lines']);

        $totals = self::map($result['totals']);
        $sum = '0';
        foreach ($accounts as $account) {
            $sum = (string) \Brick\Math\BigDecimal::of($sum)->plus(self::string($account['securitiesValue']));
        }
        self::assertSame((string) \Brick\Math\BigDecimal::of(self::string($totals['totalValue']))->minus(self::string($totals['cash'])), $sum);
        foreach ($accounts as $account) {
            if ('LINES' === $account['detail']) {
                $lines = '0';
                foreach (self::list($account['lines']) as $line) {
                    $lines = (string) \Brick\Math\BigDecimal::of($lines)->plus(self::string($line['value']));
                }
                self::assertSame($account['securitiesValue'], $lines);
            }
        }
    }

    public function testThePerAccountViewShowsPartialAndMissingValuationsWithoutZero(): void
    {
        $partial = new HoldingsAccount('00000000-0000-7000-8000-0000000000c3', 'PER', 'EUR', self::view(
            '00000000-0000-7000-8000-0000000000c3', '2026-11-02', '5.00', [], ValuationDetail::POSITIONS, ValuationCompleteness::PARTIAL, null,
        ));
        $none = new HoldingsAccount('00000000-0000-7000-8000-0000000000c4', 'Vide', 'EUR', null);
        $accounts = self::list(HoldingsAggregator::aggregate([$none, $partial, ...self::example()], self::day('2026-11-04'), 'VALUE_DESC', false)['accounts']);
        self::assertSame(['CTO', 'PEA', 'PER', 'Vide'], array_column($accounts, 'label'));
        self::assertSame('PARTIAL', $accounts[2]['detail']);
        self::assertNull($accounts[2]['securitiesValue']);
        self::assertSame('5.00', $accounts[2]['cash']);
        self::assertSame('NONE', $accounts[3]['detail']);
        self::assertNull($accounts[3]['valuedOn']);
        self::assertNull($accounts[3]['cash']);
        // A null account value makes the denominator unknown: no share, never zero.
        self::assertSame('MISSING_VALUE', self::map($accounts[0]['share'])['reason']);
    }

    public function testAccountsTieOnLabelThenIdentifier(): void
    {
        $one = self::account('00000000-0000-7000-8000-0000000000c9', 'Meme', '2026-11-03', '0', [self::line(self::W, '1', '10', '10', '5')]);
        $two = self::account('00000000-0000-7000-8000-0000000000c1', 'meme', '2026-11-03', '0', [self::line(self::W, '1', '10', '10', '5')]);
        $ids = array_column(self::list(HoldingsAggregator::aggregate([$one, $two], self::day('2026-11-04'), 'VALUE_DESC', false)['accounts']), 'accountId');
        self::assertSame(['00000000-0000-7000-8000-0000000000c1', '00000000-0000-7000-8000-0000000000c9'], $ids);
    }

    public function testMixedAssetsNullThePerAccountAmountsAndShares(): void
    {
        $btc = self::account(self::CTO, 'Crypto', '2026-11-03', '1', [self::line(self::A, '1', '10', '10', '5')], asset: 'BTC');
        $pea = self::account(self::PEA, 'PEA', '2026-11-03', '1', [self::line(self::W, '1', '10', '10', '5')]);
        foreach (self::list(HoldingsAggregator::aggregate([$pea, $btc], self::day('2026-11-04'), 'VALUE_DESC', false)['accounts']) as $account) {
            foreach (['securitiesValue', 'costBasis', 'unrealizedGain', 'performance', 'cash'] as $key) {
                self::assertNull($account[$key], $key);
            }
            self::assertSame('MIXED_ASSETS', self::map($account['share'])['reason']);
            self::assertSame('LINES', $account['detail']);
            foreach (self::list($account['lines']) as $line) {
                self::assertSame('1', $line['quantity']);
                self::assertNull($line['value']);
                self::assertNull($line['unrealizedGain']);
            }
        }
    }

    public function testATotalAccountCarriesItsFrozenCostAndNullWhenItIsUnknown(): void
    {
        $total = self::account(self::PEA, 'AV', '2026-11-03', '0', [self::line(self::W, '1', '10', '10', '5.00')], detail: ValuationDetail::TOTAL, securities: '500.00');
        $account = self::list(HoldingsAggregator::aggregate([$total], self::day('2026-11-04'), 'VALUE_DESC', false)['accounts'])[0];
        self::assertSame('500.00', $account['securitiesValue']);
        self::assertSame('5.00', $account['costBasis']);
        self::assertSame('495.00', $account['unrealizedGain']);
        self::assertSame('99.000000', $account['performance']);

        $unknown = self::account(self::PEA, 'AV', '2026-11-03', '0', [self::line(self::W, '1', '10', '10', null)], detail: ValuationDetail::TOTAL, securities: '500.00');
        $account = self::list(HoldingsAggregator::aggregate([$unknown], self::day('2026-11-04'), 'VALUE_DESC', false)['accounts'])[0];
        self::assertSame('500.00', $account['securitiesValue']);
        self::assertNull($account['costBasis']);
        self::assertNull($account['unrealizedGain']);
        self::assertNull($account['performance']);
    }

    public function testAccentedLabelsSortCaseAndAccentInsensitively(): void
    {
        $zebra = self::account(self::CTO, 'Zebra', '2026-11-03', '0', [self::line(self::W, '1', '10', '10', '5')]);
        $epargne = self::account(self::PEA, 'Épargne', '2026-11-03', '0', [self::line(self::W, '1', '10', '10', '5')]);
        $labels = array_column(self::list(HoldingsAggregator::aggregate([$zebra, $epargne], self::day('2026-11-04'), 'VALUE_DESC', false)['accounts']), 'label');
        self::assertSame(['Épargne', 'Zebra'], $labels);
    }

    public function testAnUnpricedLineNullsTheAccountAndAVeryLargeValueStaysExact(): void
    {
        $huge = '99999999999999999999999999.99';
        $pea = self::account(self::PEA, 'PEA', '2026-11-03', '0', [
            self::line(self::W, '10', null, null, '900.00'),
            self::line(self::A, '1', $huge, $huge, '1.00'),
        ]);
        $big = self::account(self::CTO, 'Gros', '2026-11-03', '0', [self::line(self::A, '1', $huge, $huge, '1.00')]);
        $accounts = self::list(HoldingsAggregator::aggregate([$pea, $big], self::day('2026-11-04'), 'VALUE_DESC', false)['accounts']);
        self::assertSame(['Gros', 'PEA'], array_column($accounts, 'label'));
        self::assertSame($huge, $accounts[0]['securitiesValue']);
        self::assertSame('99999999999999999999999998.99', $accounts[0]['unrealizedGain']);
        self::assertNull($accounts[1]['securitiesValue']);
        self::assertNull($accounts[1]['unrealizedGain']);
        self::assertNull($accounts[1]['performance']);
        self::assertSame('MISSING_VALUE', self::map($accounts[1]['share'])['reason']);
        $lines = self::list($accounts[1]['lines']);
        self::assertSame([$huge, null], array_column($lines, 'value'));
    }

    public function testNoAccountHasNoSummary(): void
    {
        self::assertSame([], HoldingsAggregator::aggregate([], self::day('2026-11-04'), 'VALUE_DESC', false)['accounts']);
    }

    public function testTheKnownCostFiguresSitBesideTheNullTotalOfTheWorkedExample(): void
    {
        $result = HoldingsAggregator::aggregate(self::example(), self::day('2026-11-04'), 'VALUE_DESC', false);
        $totals = self::map($result['totals']);
        self::assertNull($totals['costBasis']);
        self::assertSame('UNKNOWN_LOT_COST', $totals['reason']);
        self::assertSame([
            'securitiesValue' => '1510.00', 'costBasis' => '1380.00', 'unrealizedGain' => '130.00', 'performance' => '0.094203',
            'excludedCount' => 1, 'excludedValue' => '1000.00',
        ], $totals['knownCost']);
        $accounts = self::list($result['accounts']);
        self::assertSame(['CTO', 'PEA'], array_column($accounts, 'label'));
        self::assertSame([
            'securitiesValue' => '510.00', 'costBasis' => '480.00', 'unrealizedGain' => '30.00', 'performance' => '0.062500',
            'excludedCount' => 1, 'excludedValue' => '1000.00',
        ], $accounts[0]['knownCost']);
        // PEA knows its full cost: nothing to put beside it.
        self::assertNull($accounts[1]['knownCost']);
    }

    public function testNothingKnownMeansNoKnownCostFigures(): void
    {
        $pea = self::account(self::PEA, 'PEA', '2026-11-03', '0', [self::line(self::W, '1', '10', '10', null), self::line(self::A, '1', '10', '10', null)]);
        $result = HoldingsAggregator::aggregate([$pea], self::day('2026-11-04'), 'VALUE_DESC', false);
        self::assertSame('UNKNOWN_LOT_COST', self::map($result['totals'])['reason']);
        self::assertNull(self::map($result['totals'])['knownCost']);
        self::assertNull(self::list($result['accounts'])[0]['knownCost']);
        // Everything known: no known-cost figures either.
        self::assertNull(self::map(HoldingsAggregator::aggregate([self::account(self::PEA, 'PEA', '2026-11-03', '0', [self::line(self::W, '1', '10', '10', '5')])], self::day('2026-11-04'), 'VALUE_DESC', false)['totals'])['knownCost']);
    }

    public function testAnUnknownExcludedValueMakesTheExcludedValueNull(): void
    {
        $known = HoldingsKnownCost::of([
            ['value' => '100.00', 'cost' => '80.00'],
            ['value' => null, 'cost' => '50.00'],
            ['value' => '30.00', 'cost' => null],
        ]);
        self::assertSame(['securitiesValue' => '100.00', 'costBasis' => '80.00', 'unrealizedGain' => '20.00', 'performance' => '0.250000', 'excludedCount' => 2, 'excludedValue' => null], $known);
        self::assertNull(HoldingsKnownCost::of([['value' => null, 'cost' => null]]));
    }

    public function testTotalAccountsAreOneBlockIncludedWhenCostedAndExcludedWhenBare(): void
    {
        $costed = self::account(self::PEA, 'Chiffre', '2026-11-03', '0', [self::line(self::W, '1', null, null, '450.00')], detail: ValuationDetail::TOTAL, securities: '500.00');
        $bare = self::account(self::CTO, 'Nu', '2026-11-03', '0', [], detail: ValuationDetail::TOTAL, securities: '700.00');
        $totals = self::map(HoldingsAggregator::aggregate([$costed, $bare], self::day('2026-11-04'), 'VALUE_DESC', false)['totals']);
        self::assertSame('UNKNOWN_LOT_COST', $totals['reason']);
        self::assertSame([
            'securitiesValue' => '500.00', 'costBasis' => '450.00', 'unrealizedGain' => '50.00', 'performance' => '0.111111',
            'excludedCount' => 1, 'excludedValue' => '700.00',
        ], $totals['knownCost']);
        // A total-valued account has no lines of its own: no per-account figures.
        $accounts = self::list(HoldingsAggregator::aggregate([$costed, $bare], self::day('2026-11-04'), 'VALUE_DESC', false)['accounts']);
        self::assertNull($accounts[0]['knownCost']);
        self::assertNull($accounts[1]['knownCost']);
    }

    public function testMixedAssetsHaveNoKnownCostFigures(): void
    {
        $pea = self::account(self::PEA, 'PEA', '2026-11-03', '0', [self::line(self::W, '1', '10', '10', '5'), self::line(self::A, '1', '10', '10', null)]);
        $btc = self::account(self::CTO, 'Crypto', '2026-11-03', '0', [self::line(self::A, '1', '10', '10', '5')], asset: 'BTC');
        $result = HoldingsAggregator::aggregate([$pea, $btc], self::day('2026-11-04'), 'VALUE_DESC', false);
        self::assertNull(self::map($result['totals'])['knownCost']);
        foreach (self::list($result['accounts']) as $account) {
            self::assertNull($account['knownCost']);
        }
    }

    public function testAPartialAccountWithKnownSecuritiesIsAnExcludedEntryOfTheKnownCostFigures(): void
    {
        $a = self::account(self::PEA, 'A', '2026-11-03', '0', [self::line(self::W, '1', '1000.00', '1000.00', '800.00')]);
        $b = self::account(self::CTO, 'B', '2026-11-03', '0', [self::line(self::A, '1', '500.00', '500.00', null)]);
        $partial = new HoldingsAccount('00000000-0000-7000-8000-0000000000c3', 'P', 'EUR', self::view(
            '00000000-0000-7000-8000-0000000000c3', '2026-11-02', '0', [], ValuationDetail::POSITIONS, ValuationCompleteness::PARTIAL, '400.00',
        ));
        $result = HoldingsAggregator::aggregate([$a, $b, $partial], self::day('2026-11-04'), 'VALUE_DESC', false);
        $totals = self::map($result['totals']);
        self::assertSame('1900.00', $totals['securitiesValue']);
        self::assertNull($totals['costBasis']);
        self::assertSame('UNKNOWN_LOT_COST', $totals['reason']);
        self::assertSame([
            'securitiesValue' => '1000.00', 'costBasis' => '800.00', 'unrealizedGain' => '200.00', 'performance' => '0.250000',
            'excludedCount' => 2, 'excludedValue' => '900.00',
        ], $totals['knownCost']);
        $summary = array_values(array_filter(self::list($result['accounts']), static fn (array $account): bool => 'P' === $account['label']))[0];
        self::assertNull($summary['knownCost']);
    }

    public function testANoneAccountMakesTheTotalUnknownAndIsNeverCountedAsExcluded(): void
    {
        $a = self::account(self::PEA, 'A', '2026-11-03', '0', [self::line(self::W, '1', '1000.00', '1000.00', '800.00'), self::line(self::A, '1', '500.00', '500.00', null)]);
        $none = new HoldingsAccount('00000000-0000-7000-8000-0000000000c4', 'Vide', 'EUR', null);
        $totals = self::map(HoldingsAggregator::aggregate([$a, $none], self::day('2026-11-04'), 'VALUE_DESC', false)['totals']);
        self::assertNull($totals['securitiesValue']);
        self::assertSame('NO_VALUATION', $totals['reason']);
        self::assertNull($totals['knownCost']);
    }

    public function testNoAccountIsNotCalculable(): void
    {
        $result = HoldingsAggregator::aggregate([], self::day('2026-11-04'), 'VALUE_DESC', false);
        self::assertSame('NOT_CALCULABLE', $result['quality']);
        self::assertNull($result['assetCode']);
        self::assertNull(self::map($result['totals'])['totalValue']);
        self::assertSame('NO_VALUATION', self::map($result['totals'])['reason']);
        self::assertSame([], $result['items']);
    }

    public function testEveryUnsplitReasonKeepsItsSecuritiesInTheTotalOnly(): void
    {
        $cto = self::account(self::CTO, 'CTO', '2026-11-03', '120.00', [self::line(self::W, '5', '102.0000', '510.00', '480.00')]);
        $total = self::account(self::PEA, 'AV', '2026-11-01', '10.00', [], detail: ValuationDetail::TOTAL, securities: '700.00');
        $partial = new HoldingsAccount('00000000-0000-7000-8000-0000000000c3', 'PER', 'EUR', self::view(
            '00000000-0000-7000-8000-0000000000c3', '2026-11-02', '5.00', [], ValuationDetail::POSITIONS, ValuationCompleteness::PARTIAL, null,
        ));
        $none = new HoldingsAccount('00000000-0000-7000-8000-0000000000c4', 'Vide', 'EUR', null);

        $result = HoldingsAggregator::aggregate([$cto, $total, $partial, $none], self::day('2026-11-04'), 'VALUE_DESC', false);

        self::assertSame([
            ['accountId' => self::PEA, 'label' => 'AV', 'reason' => 'TOTAL_VALUATION', 'securitiesValue' => '700.00'],
            ['accountId' => '00000000-0000-7000-8000-0000000000c3', 'label' => 'PER', 'reason' => 'PARTIAL_VALUATION', 'securitiesValue' => null],
            ['accountId' => '00000000-0000-7000-8000-0000000000c4', 'label' => 'Vide', 'reason' => 'NO_VALUATION', 'securitiesValue' => null],
        ], $result['unsplitAccounts']);
        $totals = self::map($result['totals']);
        // One unsplit account has no known value: the securities total is unknown, never a partial sum.
        self::assertNull($totals['securitiesValue']);
        self::assertNull($totals['cash']);
        self::assertNull($totals['totalValue']);
        self::assertSame('NO_VALUATION', $totals['reason']);
        self::assertSame(1, count(self::items($result)));
    }

    public function testATotalValuedAccountCountsInEverySecuritiesTotal(): void
    {
        $cto = self::account(self::CTO, 'CTO', '2026-11-03', '120.00', [self::line(self::W, '5', '102.0000', '510.00', '480.00')]);
        $total = self::account(self::PEA, 'AV', '2026-11-03', '10.00', [self::line(self::A, '1', null, null, '450.00')], detail: ValuationDetail::TOTAL, securities: '700.00');
        $result = HoldingsAggregator::aggregate([$cto, $total], self::day('2026-11-04'), 'VALUE_DESC', false);
        $totals = self::map($result['totals']);
        // 510 + 700 = 1210 securities, 480 + 450 = 930 cost, 1210 - 930 = 280 gain, 280 / 930 = 0.301075.
        self::assertSame('1210.00', $totals['securitiesValue']);
        self::assertSame('930.00', $totals['costBasis']);
        self::assertSame('280.00', $totals['unrealizedGain']);
        self::assertSame('0.301075', $totals['performance']);
        self::assertSame('1340.00', $totals['totalValue']);
        self::assertNull($totals['reason']);
        self::assertSame('EXACT', $result['quality']);
        self::assertSame([], $result['warnings']);
        // The item share is weighed against the full securities total: 510 / 1210.
        self::assertSame('42.15', self::map(self::items($result)[0]['share'])['percentDisplay']);
    }

    public function testATotalAccountWithoutFrozenPositionsHasAnUnknownCostNotAZeroCost(): void
    {
        $bare = self::account(self::PEA, 'AV', '2026-11-03', '10.00', [], detail: ValuationDetail::TOTAL, securities: '700.00');
        $cto = self::account(self::CTO, 'CTO', '2026-11-03', '0', [self::line(self::W, '5', '102.0000', '510.00', '480.00')]);
        $result = HoldingsAggregator::aggregate([$cto, $bare], self::day('2026-11-04'), 'VALUE_DESC', false);
        $account = array_values(array_filter(self::list($result['accounts']), static fn (array $summary): bool => 'AV' === $summary['label']))[0];
        self::assertSame('700.00', $account['securitiesValue']);
        self::assertNull($account['costBasis']);
        self::assertNull($account['unrealizedGain']);
        self::assertNull($account['performance']);
        $totals = self::map($result['totals']);
        self::assertSame('1210.00', $totals['securitiesValue']);
        self::assertNull($totals['costBasis']);
        self::assertNull($totals['unrealizedGain']);
        self::assertNull($totals['performance']);
        self::assertSame('UNKNOWN_LOT_COST', $totals['reason']);
        self::assertSame('1220.00', $totals['totalValue']);

        // An empty total-valued account holds nothing: its zero cost is not a guess.
        $empty = self::account(self::PEA, 'Vide', '2026-11-03', '0', [], detail: ValuationDetail::TOTAL, securities: '0');
        $summary = self::list(HoldingsAggregator::aggregate([$empty], self::day('2026-11-04'), 'VALUE_DESC', false)['accounts'])[0];
        self::assertSame('0', $summary['costBasis']);
    }

    public function testAnAllTotalWorkspaceHasFiguresAndAnUnsplitSliceEverywhere(): void
    {
        $one = self::account(self::PEA, 'Un', '2026-11-03', '10.00', [self::line(self::W, '1', null, null, '900.00')], detail: ValuationDetail::TOTAL, securities: '1000.00');
        $two = self::account(self::CTO, 'Deux', '2026-11-03', '5.00', [self::line(self::A, '1', null, null, '450.00')], detail: ValuationDetail::TOTAL, securities: '500.00');
        $result = HoldingsAggregator::aggregate([$one, $two], self::day('2026-11-04'), 'VALUE_DESC', false);

        $totals = self::map($result['totals']);
        self::assertSame('1500.00', $totals['securitiesValue']);
        self::assertSame('1350.00', $totals['costBasis']);
        self::assertSame('150.00', $totals['unrealizedGain']);
        self::assertSame('0.111111', $totals['performance']);
        self::assertSame('15.00', $totals['cash']);
        self::assertSame('1515.00', $totals['totalValue']);
        self::assertSame([], $result['items']);
        $allocation = self::map($result['allocation']);
        foreach (['byInstrument', 'byAssetClass', 'byRegion'] as $key) {
            $slices = self::list($allocation[$key]);
            self::assertSame([['key' => 'UNSPLIT', 'label' => 'Comptes valorisés en total', 'value' => '1500.00', 'share' => '1.000000000000000000000000']], $slices, $key);
        }
        $byAccount = self::list($allocation['byAccount']);
        self::assertSame(['Un', 'Deux'], array_column($byAccount, 'label'));
        self::assertSame([self::PEA, self::CTO], array_column($byAccount, 'key'));
        self::assertSame(['0.666667', '0.333333'], array_map(static fn (array $slice): string => (string) \Brick\Math\BigDecimal::of(self::string($slice['share']))->toScale(6, \Brick\Math\RoundingMode::HalfUp), $byAccount));
        $accounts = self::list($result['accounts']);
        self::assertSame(['1000.00', '500.00'], array_column($accounts, 'securitiesValue'));
        self::assertSame(['900.00', '450.00'], array_column($accounts, 'costBasis'));
        self::assertSame(['100.00', '50.00'], array_column($accounts, 'unrealizedGain'));
        self::assertSame(['0.111111', '0.111111'], array_column($accounts, 'performance'));
        self::assertSame(['66.67', '33.33'], array_map(static fn (array $account): mixed => self::map($account['share'])['percentDisplay'], $accounts));
    }

    public function testAnUnknownFrozenCostOfATotalAccountNullsTheGainWithItsReason(): void
    {
        $known = self::account(self::PEA, 'Un', '2026-11-03', '0', [self::line(self::W, '1', null, null, '900.00')], detail: ValuationDetail::TOTAL, securities: '1000.00');
        $unknown = self::account(self::CTO, 'Deux', '2026-11-03', '0', [self::line(self::A, '1', null, null, null)], detail: ValuationDetail::TOTAL, securities: '500.00');
        $result = HoldingsAggregator::aggregate([$known, $unknown], self::day('2026-11-04'), 'VALUE_DESC', false);
        $totals = self::map($result['totals']);
        self::assertSame('1500.00', $totals['securitiesValue']);
        self::assertNull($totals['costBasis']);
        self::assertNull($totals['unrealizedGain']);
        self::assertNull($totals['performance']);
        self::assertSame('UNKNOWN_LOT_COST', $totals['reason']);
        $accounts = self::list($result['accounts']);
        self::assertNull($accounts[1]['unrealizedGain']);
        self::assertSame('100.00', $accounts[0]['unrealizedGain']);
    }

    public function testMixedAssetsWithATotalAccountStillNullEverything(): void
    {
        $total = self::account(self::PEA, 'Un', '2026-11-03', '0', [self::line(self::W, '1', null, null, '900.00')], detail: ValuationDetail::TOTAL, securities: '1000.00');
        $btc = self::account(self::CTO, 'Crypto', '2026-11-03', '0', [], detail: ValuationDetail::TOTAL, securities: '5.00', asset: 'BTC');
        $result = HoldingsAggregator::aggregate([$total, $btc], self::day('2026-11-04'), 'VALUE_DESC', false);
        self::assertNull(self::map($result['totals'])['securitiesValue']);
        self::assertSame('MIXED_ASSETS', self::map($result['totals'])['reason']);
        self::assertSame(['byInstrument' => [], 'byAssetClass' => [], 'byRegion' => [], 'byAccount' => []], $result['allocation']);
        foreach (self::list($result['accounts']) as $account) {
            self::assertNull($account['securitiesValue']);
            self::assertNull($account['costBasis']);
            self::assertSame('MIXED_ASSETS', self::map($account['share'])['reason']);
        }
    }

    public function testTheByAccountSlicesCoverEveryKnownAccountIncludingLineAccounts(): void
    {
        $total = self::account(self::PEA, 'AV', '2026-11-03', '0', [self::line(self::A, '1', null, null, '450.00')], detail: ValuationDetail::TOTAL, securities: '500.00');
        $result = HoldingsAggregator::aggregate([...self::example(), $total], self::day('2026-11-04'), 'VALUE_DESC', false);
        $byAccount = self::list(self::map($result['allocation'])['byAccount']);
        self::assertSame(['CTO', 'PEA', 'AV'], array_column($byAccount, 'label'));
        self::assertSame(['1510.00', '1000.00', '500.00'], array_column($byAccount, 'value'));
        $unsplit = array_values(array_filter(self::list(self::map($result['allocation'])['byInstrument']), static fn (array $slice): bool => 'UNSPLIT' === $slice['key']));
        self::assertSame('500.00', $unsplit[0]['value']);
    }

    public function testAMissingPriceNullsValueGainAndTotalsWithoutZero(): void
    {
        $pea = self::account(self::PEA, 'PEA', '2026-11-03', '0', [
            self::line(self::W, '10', null, null, '900.00'),
            self::line(self::A, '20', '50.0000', '1000.00', '800.00'),
        ]);
        $result = HoldingsAggregator::aggregate([$pea], self::day('2026-11-04'), 'VALUE_DESC', false);
        $items = self::items($result);
        self::assertSame(self::A, self::map($items[0]['instrument'])['id']);
        self::assertSame('MISSING_PRICE', $items[1]['reason']);
        self::assertNull($items[1]['value']);
        self::assertNull($items[1]['unrealizedGain']);
        self::assertSame('900.00', $items[1]['costBasis']);
        self::assertNull($items[1]['lastPrice']);
        self::assertSame('MISSING_VALUE', self::map($items[0]['share'])['reason']);
        $totals = self::map($result['totals']);
        self::assertNull($totals['securitiesValue']);
        self::assertNull($totals['unrealizedGain']);
        self::assertNull($totals['totalValue']);
        self::assertSame('MISSING_PRICE', $totals['reason']);
        self::assertSame(['byInstrument' => [], 'byAssetClass' => [], 'byRegion' => [], 'byAccount' => []], $result['allocation']);
    }

    public function testMixedAssetsNullEveryTotalAndShare(): void
    {
        $btc = self::account(self::CTO, 'Crypto', '2026-11-03', '1', [self::line(self::A, '1', '10', '10', '5')], asset: 'BTC');
        $pea = self::account(self::PEA, 'PEA', '2026-11-03', '1', [self::line(self::W, '1', '10', '10', '5')]);
        $result = HoldingsAggregator::aggregate([$pea, $btc], self::day('2026-11-04'), 'VALUE_DESC', false);
        $totals = self::map($result['totals']);
        foreach (['securitiesValue', 'costBasis', 'unrealizedGain', 'performance', 'cash', 'totalValue'] as $key) {
            self::assertNull($totals[$key], $key);
        }
        self::assertSame('MIXED_ASSETS', $totals['reason']);
        self::assertNull($result['assetCode']);
        self::assertSame('NOT_CALCULABLE', $result['quality']);
        self::assertSame('MIXED_ASSETS', self::map(self::items($result)[0]['share'])['reason']);
        foreach (self::items($result) as $item) {
            self::assertSame('1', $item['quantity']);
            foreach (['value', 'costBasis', 'unrealizedGain', 'performance', 'averageUnitCost'] as $key) {
                self::assertNull($item[$key], $key);
            }
            self::assertSame('MIXED_ASSETS', $item['reason']);
            foreach (self::list($item['accounts']) as $line) {
                self::assertNull($line['value']);
                self::assertNull($line['unrealizedGain']);
            }
        }
        self::assertSame(['byInstrument' => [], 'byAssetClass' => [], 'byRegion' => [], 'byAccount' => []], $result['allocation']);
    }

    public function testStalePricesChangedPositionsAndTruncationAreWarnings(): void
    {
        $pea = self::account(self::PEA, 'PEA', '2026-11-03', '0', [self::line(self::W, '1', '10', '10', '5')], flags: [ValuationFlag::STALE_PRICES, ValuationFlag::POSITIONS_CHANGED]);
        $warnings = HoldingsAggregator::aggregate([$pea], self::day('2026-11-04'), 'VALUE_DESC', true)['warnings'];
        self::assertSame([
            ['code' => 'STALE_PRICES', 'accountIds' => [self::PEA]],
            ['code' => 'POSITIONS_CHANGED', 'accountIds' => [self::PEA]],
            ['code' => 'TRUNCATED_ACCOUNTS', 'accountIds' => []],
        ], $warnings);
    }

    public function testLastPriceTiesGoToTheLatestValuationThenTheAccountLabel(): void
    {
        $pea = self::account(self::PEA, 'PEA', '2026-11-01', '0', [self::line(self::W, '1', '100.00', '100.00', '90.00', priceDate: '2026-10-30')]);
        $cto = self::account(self::CTO, 'CTO', '2026-11-03', '0', [self::line(self::W, '1', '101.00', '101.00', '90.00', priceDate: '2026-10-30')]);
        $price = self::map(self::items(HoldingsAggregator::aggregate([$pea, $cto], self::day('2026-11-04'), 'VALUE_DESC', false))[0])['lastPrice'];
        self::assertSame('101.00', self::map($price)['price']);
        $same = self::account(self::CTO, 'Alpha', '2026-11-01', '0', [self::line(self::W, '1', '101.00', '101.00', '90.00', priceDate: '2026-10-30')]);
        $price = self::map(self::items(HoldingsAggregator::aggregate([$pea, $same], self::day('2026-11-04'), 'VALUE_DESC', false))[0])['lastPrice'];
        self::assertSame('101.00', self::map($price)['price']);
    }

    public function testVeryLargeAmountsStayExactBeyondTheStorageWidth(): void
    {
        $huge = '99999999999999999999999999.99';
        $pea = self::account(self::PEA, 'PEA', '2026-11-03', '0', [self::line(self::W, '1', $huge, $huge, '1.00'), self::line(self::A, '1', $huge, $huge, '1.00')]);
        $cto = self::account(self::CTO, 'CTO', '2026-11-03', '0', [self::line(self::W, '1', $huge, $huge, '1.00')]);
        $result = HoldingsAggregator::aggregate([$pea, $cto], self::day('2026-11-04'), 'VALUE_DESC', false);
        self::assertSame('299999999999999999999999999.97', self::map($result['totals'])['securitiesValue']);
        self::assertSame('199999999999999999999999999.98', self::items($result)[0]['value']);
        self::assertSame('0.666666666666666666666667', self::map(self::items($result)[0]['share'])['ratio']);
    }

    public function testMoreThanAThousandInstrumentsIsRefused(): void
    {
        $lines = [];
        for ($index = 0; $index <= HoldingsAggregator::MAX_ITEMS; ++$index) {
            $lines[] = self::line(sprintf('00000000-0000-7000-8000-%012d', 100000 + $index), '1', '1', '1', '1');
        }
        $this->expectException(HoldingsTooLarge::class);
        HoldingsAggregator::aggregate([self::account(self::PEA, 'PEA', '2026-11-03', '0', $lines)], self::day('2026-11-04'), 'VALUE_DESC', false);
    }

    public function testAllocationGroupsByClassAndRegionWithUnspecifiedAndShares(): void
    {
        $result = HoldingsAggregator::aggregate(self::example(), self::day('2026-11-04'), 'VALUE_DESC', false);
        $allocation = self::map($result['allocation']);
        $classes = self::list($allocation['byAssetClass']);
        self::assertSame(['EQUITY', 'UNSPECIFIED'], array_column($classes, 'key'));
        self::assertSame('Non renseignée', $classes[1]['label']);
        self::assertSame('1510.00', $classes[0]['value']);
        self::assertSame('1000.00', $classes[1]['value']);
        $regions = self::list($allocation['byRegion']);
        self::assertSame(['WORLD', 'UNSPECIFIED'], array_column($regions, 'key'));
        self::assertSame(['ETF Monde', 'Action Alpha'], array_column(self::list($allocation['byInstrument']), 'label'));
        self::assertSame('0.601593625498007968127490', self::list($allocation['byInstrument'])[0]['share']);
    }

    /** @return list<HoldingsAccount> */
    private static function example(): array
    {
        return [
            self::account(self::PEA, 'PEA', '2026-10-31', '50.00', [self::line(self::W, '10', '100.0000', '1000.00', '900.00', priceDate: '2026-10-31')]),
            self::account(self::CTO, 'CTO', '2026-11-03', '120.00', [
                self::line(self::W, '5', '102.0000', '510.00', '480.00'),
                self::line(self::A, '20', '50.0000', '1000.00', null),
            ]),
        ];
    }

    /**
     * @param list<PositionSnapshot> $positions
     * @param list<ValuationFlag>    $flags
     */
    private static function account(string $id, string $label, string $valuedOn, string $cash, array $positions, ValuationDetail $detail = ValuationDetail::POSITIONS, ?string $securities = null, string $asset = 'EUR', array $flags = []): HoldingsAccount
    {
        return new HoldingsAccount($id, $label, $asset, self::view($id, $valuedOn, $cash, $positions, $detail, ValuationCompleteness::COMPLETE, $securities ?? '0', $asset, $flags));
    }

    /**
     * @param list<PositionSnapshot> $positions
     * @param list<ValuationFlag>    $flags
     */
    private static function view(string $accountId, string $valuedOn, string $cash, array $positions, ValuationDetail $detail, ValuationCompleteness $completeness, ?string $securities, string $asset = 'EUR', array $flags = []): ValuationView
    {
        $valuation = new Valuation(
            id: 'v-'.$accountId.$valuedOn, workspace: WorkspaceScope::fromString(self::WORKSPACE), accountId: $accountId,
            valuedOn: new \DateTimeImmutable($valuedOn), assetCode: AssetCode::fromString($asset), cash: DecimalValue::fromString($cash),
            derivedCash: DecimalValue::fromString($cash), cashSource: CashSource::DERIVED,
            securitiesValue: null === $securities ? null : DecimalValue::fromString($securities), totalValue: ValuationCompleteness::COMPLETE === $completeness ? DecimalValue::fromString($cash) : null, observedTotal: null,
            detail: $detail, completeness: $completeness, source: ValuationSource::MANUAL, note: null, supersedesId: null,
            supersededAt: null, withdrawn: false, recordedAt: new \DateTimeImmutable('2026-11-04T10:00:00+00:00'),
            recordedBy: '00000000-0000-7000-8000-000000000001', version: 1, positions: $positions,
        );
        $instruments = [
            self::W => self::instrument(self::W, 'ETF Monde', AssetClass::EQUITY, InstrumentRegion::WORLD),
            self::A => self::instrument(self::A, 'Action Alpha', null, null),
            '00000000-0000-7000-8000-000000000b09' => self::instrument('00000000-0000-7000-8000-000000000b09', 'ETF Monde', null, null),
        ];
        foreach ($positions as $position) {
            $instruments[$position->instrumentId] ??= self::instrument($position->instrumentId, 'Titre '.$position->instrumentId, null, null);
        }

        return new ValuationView($valuation, $flags, $instruments, 2);
    }

    private static function instrument(string $id, string $name, ?AssetClass $class, ?InstrumentRegion $region): Instrument
    {
        return new Instrument(
            $id, WorkspaceScope::fromString(self::WORKSPACE), $name, null, Ticker::fromString('T'.strtoupper(substr($id, -3))), null, InstrumentType::ETF, $class, $region,
            AssetCode::fromString('EUR'), 6, 4, InstrumentSource::MANUAL, null, null, InstrumentStatus::ACTIVE, null,
            new \DateTimeImmutable('2026-01-01'), '00000000-0000-7000-8000-000000000001', new \DateTimeImmutable('2026-01-01'), 1,
        );
    }

    private static function line(string $instrumentId, string $quantity, ?string $price, ?string $value, ?string $cost, string $priceDate = '2026-11-03'): PositionSnapshot
    {
        return new PositionSnapshot(
            'p-'.$instrumentId, 'v', $instrumentId, DecimalValue::fromString($quantity),
            null === $price ? null : DecimalValue::fromString($price), null === $price ? null : new \DateTimeImmutable($priceDate), null,
            null === $value ? null : DecimalValue::fromString($value), null === $cost ? null : DecimalValue::fromString($cost),
            null === $price ? PriceQuality::MISSING : PriceQuality::CURRENT,
        );
    }

    private static function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private static function items(array $result): array
    {
        return self::list($result['items']);
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values(array_map(self::map(...), $value));
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $typed = [];
        foreach ($value as $key => $item) {
            $typed[(string) $key] = $item;
        }

        return $typed;
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
