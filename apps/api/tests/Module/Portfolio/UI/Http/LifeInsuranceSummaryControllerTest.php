<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class LifeInsuranceSummaryControllerTest extends LifeInsuranceApiTestCase
{
    protected function configureServices(): void
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(MockClock::class, $clock);
        $clock->modify('+100 days');
    }

    public function testExactReferenceSequenceAndHistoricalCutoff(): void
    {
        $this->record($this->premium());
        $this->record($this->surrender());
        $this->record(['type' => 'SWITCH', 'tradeDate' => '2026-09-01', 'fees' => $this->eur('10.00'), 'legs' => [
            ['direction' => 'OUT', 'instrumentId' => self::FUND, 'amount' => $this->eur('2000.00')],
            ['direction' => 'IN', 'instrumentId' => self::ETF, 'amount' => $this->eur('1990.00'), 'quantity' => '20', 'unitPrice' => '99.50'],
        ]]);
        $this->record(['type' => 'EURO_FUND_INTEREST', 'tradeDate' => '2026-12-31', 'instrumentId' => self::FUND,
            'grossAmount' => $this->eur('150.00'), 'taxes' => $this->eur('25.80'), 'interestBasis' => 'UNKNOWN', 'rateYear' => 2026]);
        $this->record(['type' => 'MANAGEMENT_FEE', 'tradeDate' => '2026-12-31', 'instrumentId' => self::ETF,
            'fees' => $this->eur('30.00'), 'quantity' => '0.30', 'unitPrice' => '100.00']);
        $this->valuation('2026-12-31');
        $summary = $this->summary('2026-12-31');
        $value = self::map($summary['valuation']);
        self::assertSame('9293.00', $value['totalValue']);
        self::assertSame('4124.20', $value['euroFundValue']);
        self::assertSame('5168.80', $value['unitLinkedValue']);
        self::assertSame('0.00', $value['cash']);
        $allocation = self::map($summary['allocation']);
        self::assertSame('AVAILABLE', $allocation['status']);
        self::assertSame(['0.443796', '0.556204', '0.000000'], array_column(self::sequence($allocation['items']), 'share'));
        $cumulative = self::map($summary['cumulative']);
        self::assertSame('10000.00', $cumulative['premiums']);
        self::assertSame('1050.00', $cumulative['surrendersGross']);
        self::assertSame('1033.90', $cumulative['surrendersNetPaid']);
        self::assertSame('140.00', self::map($cumulative['fees'])['total']);
        self::assertSame('34.40', self::map($cumulative['withheldTaxes'])['SOCIAL_CONTRIBUTIONS']);
        self::assertSame(5, $cumulative['sourceCount']);
        self::assertSame('326.90', self::map($summary['gainSinceOpening'])['value']);
        self::assertSame(['status' => 'NO_RULE_SET', 'ruleSetId' => null], $summary['taxEstimate']);
        self::assertSame(['taxStartOn' => '2026-01-01', 'years' => 0, 'months' => 11], $summary['contractAge']);
        $historical = self::map($this->summary('2026-06-10')['cumulative']);
        self::assertSame('100.00', self::map($historical['fees'])['total']);
        self::assertSame('8.60', self::map($historical['withheldTaxes'])['SOCIAL_CONTRIBUTIONS']);
        self::assertSame(2, $historical['sourceCount']);
    }

    public function testNoValuationTotalAndFreshnessBoundary(): void
    {
        self::assertSame('NO_VALUATION', self::map($this->summary('2026-12-31')['allocation'])['status']);
        $this->valuation('2026-12-01', 'TOTAL');
        $day31 = $this->summary('2027-01-01');
        self::assertFalse(self::map($day31['valuation'])['stale']);
        self::assertSame('NOT_DETAILED', self::map($day31['allocation'])['status']);
        self::assertNull(self::map($day31['valuation'])['euroFundValue']);
        self::assertTrue(self::map($this->summary('2027-01-02')['valuation'])['stale']);
    }

    public function testAccountAuthorizationAndDateValidation(): void
    {
        $foreign = '00000000-0000-7000-8000-000000000d90';
        (new PortfolioFixture($this->connection))->account($foreign, WorkspaceFixture::OTHER_WORKSPACE, kind: 'INSURANCE_CONTRACT');
        foreach ([$foreign, 'unknown'] as $id) {
            $this->send('GET', '/api/v1/accounts/'.$id.'/life-insurance-summary');
            self::assertResponseStatusCodeSame(404);
        }
        $this->send('GET', '/api/v1/accounts/'.self::SECURITIES.'/life-insurance-summary');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('account_not_life_insurance', $this->decode()['code']);
        foreach (['2027-02-01', '2026-02-30', '', 'bad', '2026-12-31&asOf[]=x'] as $day) {
            $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/life-insurance-summary?asOf='.$day);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('invalid_summary_date', $this->decode()['code']);
        }
    }

    public function testMissingContractRemainsReadable(): void
    {
        $id = '00000000-0000-7000-8000-000000000d90';
        (new PortfolioFixture($this->connection))->account($id, label: 'Contrat sans fiche', kind: 'INSURANCE_CONTRACT');
        $this->send('GET', '/api/v1/accounts/'.$id.'/life-insurance-summary');
        self::assertResponseIsSuccessful();
        self::assertNull($this->decode()['contract']);
        self::assertNull($this->decode()['contractAge']);
    }

    public function testServedRatesCreditedLastYearAndUnitLinkedGainStaySeparate(): void
    {
        $this->record($this->premium());
        $this->record(['type' => 'EURO_FUND_INTEREST', 'tradeDate' => '2026-12-31', 'instrumentId' => self::FUND,
            'grossAmount' => $this->eur('150.00'), 'taxes' => $this->eur('25.80'), 'interestBasis' => 'UNKNOWN', 'rateYear' => 2026]);
        $this->record(['type' => 'PROFIT_SHARING', 'tradeDate' => '2026-12-31', 'instrumentId' => self::FUND,
            'grossAmount' => $this->eur('10.00'), 'taxes' => $this->eur('1.00'), 'interestBasis' => 'NET_OF_MANAGEMENT_FEES', 'rateYear' => 2026]);
        $this->send('POST', '/api/v1/accounts/'.self::LIFE.'/life-insurance-contract/euro-fund-rates', [
            'instrumentId' => self::FUND, 'year' => 2026, 'servedRate' => '2.50', 'bonusRate' => '0.10',
            'basis' => 'NET_OF_MANAGEMENT_FEES', 'sourceNote' => 'Statement']);
        self::assertResponseStatusCodeSame(201);
        $this->valuation('2026-12-31');
        $summary = $this->summary('2027-01-01');
        $rows = array_column(self::sequence($summary['supports']), null, 'type');
        $euro = self::map($rows['EURO_FUND']);
        self::assertSame(['year' => 2026, 'rate' => '2.50', 'bonusRate' => '0.10', 'basis' => 'NET_OF_MANAGEMENT_FEES'], $euro['latestServedRate']);
        self::assertSame('133.20', $euro['creditedLastClosedYear']);
        self::assertFalse($euro['missingLastYear']);
        self::assertNull($euro['unrealizedGain']);
        self::assertNull($euro['unrealizedPerformance']);
        $units = self::map($rows['UNIT_LINKED']);
        self::assertSame('260.00', $units['unrealizedGain']);
        self::assertSame('0.066667', $units['unrealizedPerformance']);
        self::assertNull($units['latestServedRate']);
        self::assertNull($units['creditedLastClosedYear']);
        self::assertSame(['gross' => '10.00', 'net' => '9.00'], self::map($summary['cumulative'])['profitSharing']);
        $taxYears = self::sequence($summary['taxYears']);
        self::assertCount(2, $taxYears);
        self::assertSame('26.80', self::map($taxYears[0])['withheldSocialContributions']);
        self::assertSame('0', self::map($taxYears[1])['withheldSocialContributions']);
        self::assertSame(['INTEREST_BASIS_UNKNOWN'], array_column(self::sequence($summary['qualityIssues']), 'code'));
    }

    public function testTaxTrackingKeepsEarlierRecordedOperationsWhileCumulativeStartsAtSubscription(): void
    {
        $premium = $this->record($this->premium());
        $this->connection->update('portfolio_operations', ['trade_date' => '2025-12-31'], ['id' => self::string($premium['id']), 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE]);
        $summary = $this->summary('2026-12-31');
        $years = self::sequence($summary['taxYears']);
        self::assertCount(2, $years);
        self::assertSame(2025, self::map($years[0])['year']);
        self::assertSame('10000.00', self::map($years[0])['premiums']);
        self::assertSame('0', self::map($summary['cumulative'])['premiums']);
        self::assertSame(0, self::map($summary['cumulative'])['sourceCount']);
    }

    public function testTaxCalendarBoundAndAgeAreFactual(): void
    {
        $this->connection->update('portfolio_life_insurance_contracts', ['tax_start_on' => '1967-01-01'], ['account_id' => self::LIFE, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE]);
        $summary = $this->summary('2026-12-31');
        self::assertCount(60, self::sequence($summary['taxYears']));
        self::assertSame(['taxStartOn' => '1967-01-01', 'years' => 59, 'months' => 11], $summary['contractAge']);
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/life-insurance-summary?asOf=2027-01-01');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_summary_date', $this->decode()['code']);
    }

    public function testCorrectionAndVoidPreserveFrozenValuationAndActiveSources(): void
    {
        $premium = $this->record($this->premium());
        $this->valuation('2026-12-01');
        $surrender = $this->record($this->surrender());
        $summary = $this->summary('2026-12-31');
        self::assertContains('POSITIONS_CHANGED', self::sequence(self::map($summary['valuation'])['flags']));
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($surrender['id']).'/void', ['version' => 1, 'reason' => 'Correction']);
        self::assertResponseIsSuccessful();
        self::assertSame('0', self::map($this->summary('2026-12-31')['cumulative'])['surrendersGross']);
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($premium['id']).'/correct', ['version' => 1, 'operation' => [...$this->premium(), 'tradeDate' => '2026-01-16']]);
        self::assertResponseStatusCodeSame(201);
        $corrected = $this->decode();
        $cumulative = self::map($this->summary('2026-12-31')['cumulative']);
        self::assertSame(1, $cumulative['sourceCount']);
        self::assertSame([$corrected['id']], array_column(self::sequence($cumulative['sources']), 'id'));
    }

    public function testPartialOnlyIsNotAnAnchorAndStalePricesAreFlagged(): void
    {
        $this->record($this->premium());
        $this->send('POST', '/api/v1/accounts/'.self::LIFE.'/portfolio-valuations', ['valuedOn' => '2026-11-30', 'detail' => 'POSITIONS', 'prices' => [
            ['instrumentId' => self::FUND, 'price' => '1', 'priceDate' => '2026-11-30'],
            ['instrumentId' => self::ETF, 'price' => null, 'priceDate' => null],
        ]]);
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->summary('2026-12-31')['valuation']);
        $this->send('POST', '/api/v1/accounts/'.self::LIFE.'/portfolio-valuations', ['valuedOn' => '2026-12-31', 'detail' => 'POSITIONS', 'prices' => [
            ['instrumentId' => self::FUND, 'price' => '1', 'priceDate' => '2026-12-31'],
            ['instrumentId' => self::ETF, 'price' => '104', 'priceDate' => '2026-12-20'],
        ], 'observedTotal' => $this->eur('10200')]);
        self::assertResponseStatusCodeSame(201);
        $value = self::map($this->summary('2026-12-31')['valuation']);
        self::assertTrue($value['stale']);
        self::assertSame(0, $value['ageDays']);
        self::assertSame('40.00', $value['observedTotalDifference']);
        self::assertSame('0.015', $value['tolerance']);
        self::assertContains('OBSERVED_TOTAL_DIFFERENCE', self::sequence($value['flags']));
    }

    /** @return array<mixed> */
    private static function sequence(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private function valuation(string $day, string $detail = 'POSITIONS'): void
    {
        $body = ['valuedOn' => $day, 'detail' => $detail];
        if ('TOTAL' === $detail) {
            $body['totalValue'] = $this->eur('0');
        } else {
            $body['prices'] = [
                ['instrumentId' => self::FUND, 'price' => '1', 'priceDate' => $day],
                ['instrumentId' => self::ETF, 'price' => '104.00', 'priceDate' => $day],
            ];
        }
        $this->send('POST', '/api/v1/accounts/'.self::LIFE.'/portfolio-valuations', $body);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function summary(string $day): array
    {
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/life-insurance-summary?asOf='.$day);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control') ?? '');

        return $this->decode();
    }
}
