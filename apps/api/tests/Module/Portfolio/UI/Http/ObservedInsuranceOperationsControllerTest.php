<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class ObservedInsuranceOperationsControllerTest extends LifeInsuranceApiTestCase
{
    protected function configureServices(): void
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(MockClock::class, $clock);
        $clock->modify('+87 days');
    }

    /** @return array<string, mixed> */
    private function interest(): array
    {
        return ['type' => 'EURO_FUND_INTEREST', 'tradeDate' => '2026-12-31', 'instrumentId' => self::FUND,
            'grossAmount' => $this->eur('150.00'), 'taxes' => $this->eur('25.80'), 'interestBasis' => 'NET_OF_MANAGEMENT_FEES', 'rateYear' => 2026];
    }

    public function testObservedFieldsPreviewAnnualAgreementAndReplay(): void
    {
        $this->record($this->premium());
        $this->record($this->surrender());
        $this->record(['type' => 'SWITCH', 'tradeDate' => '2026-09-01', 'fees' => $this->eur('10.00'), 'legs' => [
            ['direction' => 'OUT', 'instrumentId' => self::FUND, 'amount' => $this->eur('2000.00')],
            ['direction' => 'IN', 'instrumentId' => self::ETF, 'amount' => $this->eur('1990.00'), 'quantity' => '20', 'unitPrice' => '99.50'],
        ]]);
        $this->send('POST', $this->operations(self::LIFE).'/preview', $this->interest());
        self::assertResponseIsSuccessful();
        self::assertSame('124.20', self::amountValue($this->decode(), 'netCreditedAmount'));
        $this->client->setServerParameter('HTTP_IDEMPOTENCY_KEY', 'observed-interest');
        $interest = $this->record($this->interest());
        self::assertEquals($interest, $this->record($this->interest()));
        $this->client->setServerParameter('HTTP_IDEMPOTENCY_KEY', 'observed-unit-fee');
        $fee = $this->record(['type' => 'MANAGEMENT_FEE', 'tradeDate' => '2026-12-31', 'instrumentId' => self::ETF, 'fees' => $this->eur('30.00'), 'quantity' => '0.30', 'unitPrice' => '100.00']);
        self::assertSame('MANAGEMENT', $fee['feeNature']);
        $this->client->setServerParameter('HTTP_IDEMPOTENCY_KEY', 'observed-euro-fee');
        $this->record(['type' => 'MANAGEMENT_FEE', 'tradeDate' => '2026-12-31', 'instrumentId' => self::FUND, 'fees' => $this->eur('12.00')]);
        $this->client->setServerParameter('HTTP_IDEMPOTENCY_KEY', 'observed-contribution');
        $this->record(['type' => 'SOCIAL_CONTRIBUTION', 'tradeDate' => '2026-12-31', 'instrumentId' => self::FUND, 'taxes' => $this->eur('2.00'), 'rateYear' => 2026]);
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/life-insurance-income-and-fees?year=2026');
        self::assertResponseIsSuccessful();
        $annual = $this->decode();
        self::assertSame('124.20', self::map($annual['interest'])['net']);
        self::assertSame('42.00', self::map($annual['fees'])['MANAGEMENT']);
        self::assertSame('152.00', self::map($annual['fees'])['total']);
        self::assertSame('43.90', self::map($annual['withheldTaxes'])['total']);
        self::assertSame('7.50', self::map($annual['withheldTaxes'])['INCOME_TAX']);
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/portfolio-cash?from=2026-01-01&to=2026-12-31');
        self::assertResponseIsSuccessful();
        $totals = self::map($this->decode()['totals']);
        self::assertSame(self::map($annual['fees'])['total'], self::amountValue($totals, 'fees'));
        self::assertSame(self::map($annual['withheldTaxes'])['total'], self::amountValue($totals, 'taxes'));
        $this->send('GET', '/api/v1/portfolio-operations/'.self::string($interest['id']));
        self::assertResponseIsSuccessful();
        self::assertSame('NET_OF_MANAGEMENT_FEES', $this->decode()['interestBasis']);
        self::assertSame(2026, $this->decode()['rateYear']);
    }

    public function testRulesAndGenericTypesOnInsurance(): void
    {
        $this->refused([...$this->interest(), 'instrumentId' => self::ETF], 'instrument_not_euro_fund');
        $this->refused([...$this->interest(), 'taxes' => $this->eur('150.00')], 'invalid_operation_amount');
        $this->refused([...$this->interest(), 'rateYear' => 2025], 'invalid_year');
        $this->refused([...$this->interest(), 'interestBasis' => 'INVALID'], 'invalid_interest_basis');
        $this->refused(['type' => 'SOCIAL_CONTRIBUTION', 'tradeDate' => '2026-12-31', 'instrumentId' => self::ETF, 'taxes' => $this->eur('1.00'), 'rateYear' => 2026], 'instrument_not_euro_fund');
        foreach (['FEE', 'TAX', 'INTEREST'] as $type) {
            $this->refused(['type' => $type, 'tradeDate' => '2026-12-31'], 'operation_type_not_allowed_for_account');
        }
        $this->record($this->premium());
        $this->refused(['type' => 'MANAGEMENT_FEE', 'tradeDate' => '2026-12-31', 'instrumentId' => self::ETF, 'fees' => $this->eur('30.01'), 'quantity' => '0.30', 'unitPrice' => '100.00'], 'operation_amount_mismatch');
        $cash = $this->record(['type' => 'MANAGEMENT_FEE', 'tradeDate' => '2026-12-31', 'fees' => $this->eur('12.00')]);
        self::assertSame('-12.00', self::amountValue($cash, 'cashAmount'));
        $this->send('POST', $this->operations(self::SECURITIES), ['type' => 'FEE', 'tradeDate' => '2026-12-31', 'fees' => $this->eur('1.00')]);
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', $this->operations(self::SECURITIES), $this->interest());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('operation_type_not_allowed_for_account', $this->decode()['code']);
    }

    public function testYearAndReadWriteIsolation(): void
    {
        foreach (['2025', '2027', 'bad', ''] as $year) {
            $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/life-insurance-income-and-fees?year='.$year);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('invalid_year', $this->decode()['code']);
        }
        $foreign = '00000000-0000-7000-8000-000000000eee';
        (new PortfolioFixture($this->connection))->account($foreign, WorkspaceFixture::OTHER_WORKSPACE, kind: 'INSURANCE_CONTRACT');
        foreach ([$foreign, '00000000-0000-7000-8000-000000000fff'] as $id) {
            $this->send('GET', '/api/v1/accounts/'.$id.'/life-insurance-income-and-fees?year=2026');
            self::assertResponseStatusCodeSame(404);
            $this->send('POST', $this->operations($id), $this->interest());
            self::assertResponseStatusCodeSame(404);
        }
        $this->send('GET', '/api/v1/accounts/'.self::SECURITIES.'/life-insurance-income-and-fees?year=2026');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('account_not_life_insurance', $this->decode()['code']);
    }

    public function testCorrectionVoidAndQualityEvidenceFollowActiveOperations(): void
    {
        $original = $this->record([...$this->interest(), 'interestBasis' => 'UNKNOWN']);
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/life-insurance-income-and-fees?year=2026');
        self::assertResponseIsSuccessful();
        self::assertIsArray($this->decode()['qualityIssues']);
        self::assertCount(2, $this->decode()['qualityIssues']);
        $this->send('POST', '/api/v1/accounts/'.self::LIFE.'/life-insurance-contract/euro-fund-rates', [
            'instrumentId' => self::FUND, 'year' => 2026, 'servedRate' => '2.50', 'basis' => 'NET_OF_MANAGEMENT_FEES', 'sourceNote' => 'Observed statement']);
        self::assertResponseStatusCodeSame(201);
        $id = self::string($original['id']);
        $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/correct', ['version' => 1, 'operation' => [...$this->interest(), 'grossAmount' => $this->eur('200.00')]]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $corrected = $this->decode();
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/life-insurance-income-and-fees?year=2026');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decode()['qualityIssues']);
        self::assertSame('174.20', self::map($this->decode()['interest'])['net']);
        self::assertSame(1, $this->decode()['sourceCount']);
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($corrected['id']).'/void', ['version' => 1, 'reason' => 'Statement corrected']);
        self::assertResponseIsSuccessful();
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/life-insurance-income-and-fees?year=2026');
        self::assertResponseIsSuccessful();
        self::assertSame('0', self::map($this->decode()['interest'])['net']);
        self::assertSame(0, $this->decode()['sourceCount']);
    }

    public function testClosedPeriodsAndSafeMigrationRollbackPreserveObservedRows(): void
    {
        $this->record($this->interest());
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel ?? self::bootKernel());
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('doctrine:migrations:execute'));
        try {
            $tester->execute(['versions' => ['DoctrineMigrations\\Version20261007130000'], '--down' => true, '--no-interaction' => true]);
            self::fail('Observed rows must prevent a destructive rollback.');
        } catch (\Doctrine\Migrations\Exception\AbortMigration $exception) {
            self::assertStringContainsString('exported before rolling back', $exception->getMessage());
        }
        self::assertSame(1, $this->rows('portfolio_operations'));
        $this->closeMonthInDatabase($this->connection, 2026, 12);
        $this->send('POST', $this->operations(self::LIFE), $this->interest());
        self::assertResponseStatusCodeSame(409);
    }

    public function testLegacyGenericFeesRemainReadableOnTheContract(): void
    {
        $this->send('POST', $this->operations(self::SECURITIES), ['type' => 'FEE', 'tradeDate' => '2026-12-31', 'fees' => $this->eur('1.00')]);
        self::assertResponseStatusCodeSame(201);
        $id = self::string($this->decode()['id']);
        $this->connection->update('portfolio_operations', ['account_id' => self::LIFE], ['id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE]);
        $this->send('GET', '/api/v1/portfolio-operations/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame('FEE', $this->decode()['type']);
        self::assertSame('OTHER', $this->decode()['feeNature']);
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/life-insurance-income-and-fees?year=2026');
        self::assertResponseIsSuccessful();
        self::assertSame('1.00', self::map($this->decode()['fees'])['OTHER']);
    }

    public function testSurrendersRejectInterestOnlyFieldsBeforePersistence(): void
    {
        foreach ([$this->surrender(), $this->full()] as $surrender) {
            $this->refused([...$surrender, 'rateYear' => 40000], 'operation_field_not_allowed');
            $this->refused([...$surrender, 'interestBasis' => 'UNKNOWN'], 'operation_field_not_allowed');
        }
        self::assertSame(0, $this->rows('portfolio_operations'));
    }

    public function testDerivedEuroFundQuantitiesMustFitExactlyWithoutRounding(): void
    {
        $this->refused([...$this->interest(), 'grossAmount' => $this->eur('0.001'), 'taxes' => null], 'quantity_precision_exceeded');
        $this->refused([...$this->interest(), 'grossAmount' => $this->eur('1.00'), 'taxes' => $this->eur('0.001')], 'quantity_precision_exceeded');
        $this->refused(['type' => 'MANAGEMENT_FEE', 'tradeDate' => '2026-12-31', 'instrumentId' => self::FUND, 'fees' => $this->eur('0.001')], 'quantity_precision_exceeded');
        $this->refused(['type' => 'SOCIAL_CONTRIBUTION', 'tradeDate' => '2026-12-31', 'instrumentId' => self::FUND, 'taxes' => $this->eur('0.001'), 'rateYear' => 2026], 'quantity_precision_exceeded');
        $boundary = $this->record([...$this->interest(), 'grossAmount' => $this->eur('1.001'), 'taxes' => $this->eur('0.001')]);
        self::assertSame('1.001', self::amountValue($boundary, 'grossAmount'));
        $this->send('POST', $this->operations(self::LIFE).'/preview', [...$this->interest(), 'grossAmount' => $this->eur('1.001'), 'taxes' => $this->eur('0.001')]);
        self::assertResponseIsSuccessful();
        self::assertSame('1.000', self::amountValue($this->decode(), 'netCreditedAmount'));
    }

    public function testExactNetCreditRoundTripsFromPositionsToFullSurrender(): void
    {
        $interest = $this->record([...$this->interest(), 'grossAmount' => $this->eur('1.001'), 'taxes' => $this->eur('0.001')]);
        self::assertSame('1.001', self::amountValue($interest, 'grossAmount'));
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/portfolio-positions?asOf=2026-12-31');
        self::assertResponseIsSuccessful();
        $items = $this->decode()['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);
        $position = self::map($items[0]);
        $quantity = self::string($position['quantity']);
        self::assertSame('1', $quantity);
        $instrumentId = self::string(self::map($position['instrument'])['id']);
        $surrender = $this->record(['type' => 'FULL_SURRENDER', 'tradeDate' => '2026-12-31', 'grossAmount' => $this->eur($quantity), 'legs' => [
            ['direction' => 'OUT', 'instrumentId' => $instrumentId, 'quantity' => $quantity, 'unitPrice' => '1', 'amount' => $this->eur($quantity)],
        ]]);
        self::assertSame('FULL_SURRENDER', $surrender['type']);
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/portfolio-positions?asOf=2026-12-31');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decode()['items']);
    }
}
