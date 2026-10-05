<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\LogCapture;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class LifeInsuranceContractControllerTest extends WebTestCase
{
    private const string LIFE = PortfolioFixture::ACCOUNT;
    private const string SNAPSHOTS = '00000000-0000-7000-8000-0000000000d4';
    private const string BENEFIT = '00000000-0000-7000-8000-0000000000d5';
    private const string SECURITIES = PortfolioFixture::CASH_ACCOUNT;
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000d7';
    private const string EURO_FUND = '00000000-0000-7000-8000-000000000b03';
    private const string OTHER_EURO_FUND = '00000000-0000-7000-8000-000000000b04';
    private const string FOREIGN_EURO_FUND = '00000000-0000-7000-8000-000000000b05';
    private const string STOCK = PortfolioFixture::STOCK;
    private const string MISSING = '00000000-0000-7000-8000-0000000000ee';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private LogCapture $logger;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->databaseReady = true;
        $this->fixture->reset();
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        self::getContainer()->set(ClockInterface::class, new MockClock('2026-10-05T12:00:00+00:00'));
        $this->logger = LogCapture::attach(self::getContainer()->get('logger'));

        $portfolio = new PortfolioFixture($connection);
        $portfolio->account(self::LIFE, label: 'Assurance-vie', kind: 'INSURANCE_CONTRACT');
        $portfolio->account(self::SECURITIES, label: 'Compte-titres');
        $portfolio->account(self::SNAPSHOTS, label: 'Assurance-vie manuelle', kind: 'INSURANCE_CONTRACT', valuationMode: 'SNAPSHOTS');
        $portfolio->account(self::BENEFIT, label: 'PEE', kind: 'EMPLOYEE_BENEFIT');
        $portfolio->account(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin', kind: 'INSURANCE_CONTRACT');
        $portfolio->euroFund(self::EURO_FUND);
        $portfolio->euroFund(self::OTHER_EURO_FUND, name: 'Fonds euros Autre');
        $portfolio->euroFund(self::FOREIGN_EURO_FUND, WorkspaceFixture::OTHER_WORKSPACE, 'Fonds euros voisin');
        $portfolio->instrument(self::STOCK, name: 'Action Démo', isin: 'FR0000120271');

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->send('POST', '/api/v1/session', ['email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        self::assertResponseStatusCodeSame(204);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testOnlyAnAccountFollowedInPortfolioModeAsALifeInsuranceContractIsEligible(): void
    {
        foreach ([[self::SNAPSHOTS, 'account_not_portfolio'], [self::BENEFIT, 'account_not_portfolio'], [self::SECURITIES, 'account_not_life_insurance']] as [$account, $code]) {
            $this->send('GET', $this->path($account));
            self::assertResponseStatusCodeSame(422, $account);
            self::assertSame($code, $this->decode()['code']);
        }
        foreach ([self::FOREIGN, self::MISSING, 'not-a-uuid'] as $account) {
            $this->send('GET', $this->path($account));
            self::assertResponseStatusCodeSame(404, $account);
        }
    }

    public function testAnAccountWithoutContractReadsAsEmpty(): void
    {
        $this->send('GET', $this->path());

        self::assertResponseIsSuccessful();
        self::assertSame(['contract' => null, 'feeTerms' => [], 'euroFundRates' => [], 'euroFunds' => []], $this->decode());
    }

    public function testTheContractNumberIsReturnedMaskedAndNeverStoredLoggedOrAudited(): void
    {
        $this->send('PUT', $this->path(), $this->contract());

        self::assertResponseStatusCodeSame(201);
        $contract = $this->decode();
        self::assertSame(
            ['id', 'accountId', 'insurer', 'distributor', 'commercialName', 'contractNumberMasked', 'subscribedOn', 'taxStartOn', 'managementMode', 'status', 'closedOn', 'version'],
            array_keys($contract),
        );
        self::assertSame('•••• 2345', $contract['contractNumberMasked']);
        self::assertSame('2024-02-10', $contract['taxStartOn']);
        self::assertSame('OPEN', $contract['status']);
        self::assertSame(1, $contract['version']);

        $this->send('GET', $this->path());
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('00012345', $content);
        self::assertStringNotContainsString('beneficiar', strtolower($content));
        self::assertSame('2345', $this->connection->fetchOne('SELECT contract_number_suffix FROM portfolio_life_insurance_contracts'));

        $trail = implode(' ', array_map(static fn (mixed $row): string => is_string($row) ? $row : '', $this->connection->fetchFirstColumn("SELECT concat(before_json::text, after_json::text, event_type) FROM audit_events WHERE entity_type = 'life_insurance_contract'")));
        self::assertStringContainsString('life_insurance_contract.created', $trail);
        self::assertStringNotContainsString('00012345', $trail);
        self::assertStringNotContainsString('AV-', $trail);
        self::assertStringNotContainsString('Assureur Démo', $trail);
        self::assertFalse($this->logger->contains('00012345'));
        self::assertSame(0, self::integer($this->connection->fetchOne("SELECT count(*) FROM audit_events WHERE concat(before_json::text, after_json::text) LIKE '%00012345%'")));
    }

    public function testARefusedNumberNeverEchoesTheSubmittedValue(): void
    {
        $this->send('PUT', $this->path(), ['contractNumber' => 'AV_00012345/x'] + $this->contract());

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_contract_field', $this->decode()['code']);
        self::assertStringNotContainsString('00012345', (string) $this->client->getResponse()->getContent());
        self::assertFalse($this->logger->contains('00012345'));
    }

    public function testASecondCreateAndAStaleUpdateAreConflicts(): void
    {
        $this->createContract();

        $this->send('PUT', $this->path(), $this->contract());
        self::assertResponseStatusCodeSame(409);
        self::assertSame('life_insurance_contract_exists', $this->decode()['code']);

        $this->send('PUT', $this->path(), $this->contract(['version' => 7]));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_contract_version', $this->decode()['code']);
    }

    public function testAnUpdateKeepsTheNumberWhenOmittedAndClearsItWhenNull(): void
    {
        $this->createContract();

        $body = $this->contract(['version' => 1, 'managementMode' => 'MANAGED']);
        unset($body['contractNumber']);
        $this->send('PUT', $this->path(), $body);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('•••• 2345', $this->decode()['contractNumberMasked']);
        self::assertSame('MANAGED', $this->decode()['managementMode']);
        self::assertSame(2, $this->decode()['version']);

        $this->send('PUT', $this->path(), $this->contract(['version' => 2, 'contractNumber' => null]));
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->decode()['contractNumberMasked']);
        self::assertNull($this->connection->fetchOne('SELECT contract_number_suffix FROM portfolio_life_insurance_contracts') ?: null);

        $updates = $this->connection->fetchFirstColumn("SELECT after_json::text FROM audit_events WHERE event_type = 'life_insurance_contract.updated' ORDER BY occurred_at, id");
        self::assertCount(2, $updates);
        self::assertStringContainsString('managementMode', self::text($updates[0]));
        self::assertStringNotContainsString('MANAGED', self::text($updates[0]));
    }

    public function testARevisedSubscriptionCannotInvalidateRecordedFeeTerms(): void
    {
        $this->send('PUT', $this->path(), $this->contract(['subscribedOn' => '2020-01-01']));
        $this->send('POST', $this->path(suffix: '/fee-terms'), $this->feeTerms('2020-06-01'));
        self::assertResponseStatusCodeSame(201);
        $this->send('GET', $this->path());
        $before = $this->decode();
        $auditCount = $this->rows('audit_events');

        $this->send('PUT', $this->path(), $this->contract(['version' => 1, 'subscribedOn' => '2025-01-01']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_fee_terms_date', $this->decode()['code']);
        $this->send('GET', $this->path());
        self::assertSame($before, $this->decode());
        self::assertSame($auditCount, $this->rows('audit_events'));
    }

    public function testARevisedSubscriptionCannotInvalidateRecordedRateYears(): void
    {
        $this->send('PUT', $this->path(), $this->contract(['subscribedOn' => '2020-01-01']));
        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2020));
        self::assertResponseStatusCodeSame(201);
        $this->send('GET', $this->path());
        $before = $this->decode();
        $auditCount = $this->rows('audit_events');

        $this->send('PUT', $this->path(), $this->contract(['version' => 1, 'subscribedOn' => '2025-01-01']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_euro_fund_rate', $this->decode()['code']);
        $this->send('GET', $this->path());
        self::assertSame($before, $this->decode());
        self::assertSame($auditCount, $this->rows('audit_events'));

        $this->send('PUT', $this->path(), $this->contract(['version' => 7, 'subscribedOn' => '2025-01-01']));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_contract_version', $this->decode()['code']);
    }

    public function testAnEarlierSubscriptionPreservesAllRecordedChildren(): void
    {
        $this->send('PUT', $this->path(), $this->contract(['subscribedOn' => '2020-06-01']));
        $this->send('POST', $this->path(suffix: '/fee-terms'), $this->feeTerms('2020-06-01'));
        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2020));
        $this->send('GET', $this->path());
        $before = $this->decode();

        $this->send('PUT', $this->path(), $this->contract(['version' => 1, 'subscribedOn' => '2019-01-01']));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->decode()['version']);
        $this->send('GET', $this->path());
        self::assertSame($before['feeTerms'], $this->decode()['feeTerms']);
        self::assertSame($before['euroFundRates'], $this->decode()['euroFundRates']);
    }

    public function testSubscriptionChangesWithinARateYearRemainAllowed(): void
    {
        $this->send('PUT', $this->path(), $this->contract(['subscribedOn' => '2020-01-01']));
        $this->send('POST', $this->path(suffix: '/fee-terms'), $this->feeTerms('2020-12-01'));
        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2020));

        $this->send('PUT', $this->path(), $this->contract(['version' => 1, 'subscribedOn' => '2020-11-01']));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('2020-11-01', $this->decode()['subscribedOn']);
        self::assertSame(2, $this->decode()['version']);
    }

    public function testInvalidContractFieldsAndDatesAreRefused(): void
    {
        foreach ([
            [['insurer' => ''], 'invalid_contract_field'],
            [['insurer' => str_repeat('a', 81)], 'invalid_contract_field'],
            [['managementMode' => 'AUTO'], 'invalid_contract_field'],
            [['subscribedOn' => '2026-10-06'], 'invalid_contract_date'],
            [['taxStartOn' => '2027-01-01'], 'invalid_contract_date'],
            [['subscribedOn' => '2026-02-30'], 'invalid_contract_date'],
        ] as [$overrides, $code]) {
            $this->send('PUT', $this->path(), $overrides + $this->contract());
            self::assertResponseStatusCodeSame(422, $code);
            self::assertSame($code, $this->decode()['code'], json_encode($overrides, JSON_THROW_ON_ERROR));
        }
        $this->send('PUT', $this->path(), ['insurer' => 'A', 'beneficiary' => 'Jeanne'] + $this->contract());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_life_insurance_payload', $this->decode()['code']);
        self::assertSame(0, $this->rows('portfolio_life_insurance_contracts'));
    }

    public function testFeeTermsAreVersionedByDateAndValidated(): void
    {
        $this->send('POST', $this->path(suffix: '/fee-terms'), $this->feeTerms('2026-01-01'));
        self::assertResponseStatusCodeSame(404);
        $this->createContract();

        $this->send('POST', $this->path(suffix: '/fee-terms'), $this->feeTerms('2026-01-01'));
        self::assertResponseStatusCodeSame(201);
        $first = $this->decode();
        self::assertSame(['id', 'validFrom', 'entryFeeRate', 'euroFundManagementFeeRate', 'unitLinkedManagementFeeRate', 'switchFeeRate', 'switchFeeFixed', 'sourceNote'], array_keys($first));
        self::assertSame('0.60', $first['euroFundManagementFeeRate']);
        self::assertSame(['value' => '5.00', 'assetCode' => 'EUR'], $first['switchFeeFixed']);

        $this->send('POST', $this->path(suffix: '/fee-terms'), $this->feeTerms('2026-01-01'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('fee_terms_exist', $this->decode()['code']);

        foreach ([
            [['entryFeeRate' => '100.0001'], 'invalid_fee_rate'],
            [['switchFeeRate' => '-0.1'], 'invalid_fee_rate'],
            [['unitLinkedManagementFeeRate' => '0.12345'], 'invalid_fee_rate'],
            [['entryFeeRate' => 1.5], 'invalid_life_insurance_payload'],
            [['validFrom' => '2024-02-09'], 'invalid_fee_terms_date'],
            [['switchFeeFixed' => ['value' => '-1', 'assetCode' => 'EUR']], 'invalid_fee_rate'],
            [['switchFeeFixed' => ['value' => '1', 'assetCode' => 'USD']], 'invalid_fee_rate'],
        ] as [$overrides, $code]) {
            $this->send('POST', $this->path(suffix: '/fee-terms'), $overrides + $this->feeTerms('2026-06-01'));
            self::assertSame($code, $this->decode()['code'], json_encode($overrides, JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
        }

        $this->send('POST', $this->path(suffix: '/fee-terms'), $this->feeTerms('2026-06-01', ['entryFeeRate' => '0.8']));
        self::assertResponseStatusCodeSame(201);
        $this->send('GET', $this->path());
        $listed = self::list($this->decode()['feeTerms']);
        self::assertSame(['2026-06-01', '2026-01-01'], array_column($listed, 'validFrom'));
    }

    public function testFeeTermsCanBeRemovedAndTheRemovalIsAuditedWithoutFigures(): void
    {
        $this->createContract();
        $this->send('POST', $this->path(suffix: '/fee-terms'), $this->feeTerms('2026-01-01'));
        $id = self::text($this->decode()['id']);

        $this->send('DELETE', '/api/v1/life-insurance-fee-terms/'.self::MISSING);
        self::assertResponseStatusCodeSame(404);
        $this->send('DELETE', '/api/v1/life-insurance-fee-terms/'.$id);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->rows('portfolio_life_insurance_fee_terms'));

        $events = $this->connection->fetchFirstColumn("SELECT event_type FROM audit_events WHERE entity_type = 'life_insurance_fee_terms' ORDER BY occurred_at, id");
        self::assertSame(['life_insurance_fee_terms.added', 'life_insurance_fee_terms.removed'], $events);
        $trail = implode(' ', array_map(static fn (mixed $row): string => is_string($row) ? $row : '', $this->connection->fetchFirstColumn("SELECT concat(before_json::text, after_json::text) FROM audit_events WHERE entity_type = 'life_insurance_fee_terms'")));
        self::assertStringNotContainsString('0.60', $trail);
        self::assertStringNotContainsString('Conditions', $trail);
    }

    public function testFeeTermsAreLimitedPerContract(): void
    {
        $this->createContract();
        $contract = self::text($this->connection->fetchOne('SELECT id FROM portfolio_life_insurance_contracts'));
        for ($index = 0; $index < 50; ++$index) {
            $this->connection->insert('portfolio_life_insurance_fee_terms', [
                'id' => sprintf('00000000-0000-7000-8000-%012x', 0x900 + $index), 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
                'contract_id' => $contract, 'valid_from' => (new \DateTimeImmutable('2025-01-01'))->modify('+'.$index.' days')->format('Y-m-d'),
                'recorded_at' => '2026-10-05 10:00:00+00', 'recorded_by' => WorkspaceFixture::OWNER_ID,
            ]);
        }

        $this->send('POST', $this->path(suffix: '/fee-terms'), $this->feeTerms('2026-01-01'));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('fee_terms_limit_reached', $this->decode()['code']);
    }

    public function testEuroFundRatesAreRecordedOncePerSupportAndYear(): void
    {
        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2025));
        self::assertResponseStatusCodeSame(404);
        $this->createContract();

        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2025));
        self::assertResponseStatusCodeSame(201);
        $rate = $this->decode();
        self::assertSame(['id', 'instrumentId', 'year', 'servedRate', 'bonusRate', 'basis', 'sourceNote'], array_keys($rate));
        self::assertSame('2.50', $rate['servedRate']);
        self::assertNull($rate['bonusRate']);

        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2025, ['servedRate' => '3']));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('euro_fund_rate_exists', $this->decode()['code']);

        foreach ([
            [['instrumentId' => self::STOCK], 'instrument_not_euro_fund'],
            [['instrumentId' => self::FOREIGN_EURO_FUND], 'instrument_not_euro_fund'],
            [['instrumentId' => self::MISSING], 'instrument_not_euro_fund'],
            [['servedRate' => '100.0001'], 'invalid_euro_fund_rate'],
            [['bonusRate' => '-0.1'], 'invalid_euro_fund_rate'],
            [['year' => 2023], 'invalid_euro_fund_rate'],
            [['year' => 2027], 'invalid_euro_fund_rate'],
            [['basis' => 'WHATEVER'], 'invalid_euro_fund_rate'],
        ] as [$overrides, $code]) {
            $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $overrides + $this->rate(2024));
            self::assertResponseStatusCodeSame(422, $code);
            self::assertSame($code, $this->decode()['code'], json_encode($overrides, JSON_THROW_ON_ERROR));
        }
    }

    public function testRecordingARateLocksTheEuroFundIdentityAndAllowsMetadataChanges(): void
    {
        $this->createContract();
        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2025));
        self::assertResponseStatusCodeSame(201);
        $this->send('GET', '/api/v1/instruments/'.self::EURO_FUND);
        $instrument = $this->decode();
        self::assertTrue($instrument['used']);
        self::assertSame(2, $instrument['version']);

        $body = ['name' => 'Support renommé', 'currencyCode' => 'EUR', 'instrumentType' => 'FUND', 'ticker' => 'FUND', 'version' => 2];
        $this->send('PUT', '/api/v1/instruments/'.self::EURO_FUND, $body);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('instrument_identity_locked', $this->decode()['code']);
        $this->send('PUT', '/api/v1/instruments/'.self::EURO_FUND, ['name' => 'Support renommé', 'currencyCode' => 'EUR', 'instrumentType' => 'EURO_FUND', 'version' => 1]);
        self::assertResponseStatusCodeSame(409);
        $this->send('PUT', '/api/v1/instruments/'.self::EURO_FUND, ['name' => 'Support renommé', 'currencyCode' => 'EUR', 'instrumentType' => 'EURO_FUND', 'version' => 2]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Support renommé', $this->decode()['name']);
        $this->send('POST', '/api/v1/instruments/'.self::EURO_FUND.'/deactivate', ['version' => 3]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('INACTIVE', $this->decode()['status']);
    }

    public function testARefusedRateDoesNotMarkAnInstrumentUsed(): void
    {
        $this->createContract();
        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2025, ['servedRate' => '100.0001']));
        self::assertResponseStatusCodeSame(422);
        $this->send('GET', '/api/v1/instruments/'.self::EURO_FUND);
        self::assertFalse($this->decode()['used']);
        self::assertSame(1, $this->decode()['version']);
        self::assertSame(0, $this->rows('portfolio_euro_fund_rates'));
    }

    public function testARetypedInstrumentCannotBeRatedAsAEuroFund(): void
    {
        $this->createContract();
        $this->send('PUT', '/api/v1/instruments/'.self::EURO_FUND, ['name' => 'Autre support', 'currencyCode' => 'EUR', 'instrumentType' => 'FUND', 'ticker' => 'FUND', 'version' => 1]);
        self::assertResponseStatusCodeSame(200);
        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2025));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('instrument_not_euro_fund', $this->decode()['code']);
        self::assertSame(0, $this->rows('portfolio_euro_fund_rates'));
    }

    public function testEuroFundsReportTheLastClosedYearRateOnAFrozenClock(): void
    {
        $this->createContract();
        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2025));
        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2024));
        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2024, ['instrumentId' => self::OTHER_EURO_FUND]));

        $this->send('GET', $this->path());
        $funds = [];
        foreach (self::list($this->decode()['euroFunds']) as $fund) {
            $funds[self::text(self::map($fund['instrument'])['id'])] = $fund;
        }

        self::assertSame(2025, $funds[self::EURO_FUND]['latestRateYear']);
        self::assertFalse($funds[self::EURO_FUND]['missingLastYear']);
        self::assertSame(2024, $funds[self::OTHER_EURO_FUND]['latestRateYear']);
        self::assertTrue($funds[self::OTHER_EURO_FUND]['missingLastYear']);
        self::assertSame('EURO_FUND', self::map($funds[self::EURO_FUND]['instrument'])['instrumentType']);
    }

    public function testAHeldEuroFundWithoutARateIsReportedEvenForANewContract(): void
    {
        $this->send('PUT', $this->path(), $this->contract(['subscribedOn' => '2026-01-01']));
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', '/api/v1/accounts/'.self::LIFE.'/portfolio-operations', [
            'type' => 'BUY', 'tradeDate' => '2026-04-01', 'instrumentId' => self::EURO_FUND,
            'quantity' => '100.00', 'unitPrice' => '1', 'grossAmount' => ['value' => '100.00', 'assetCode' => 'EUR'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->send('GET', $this->path());
        $funds = self::list($this->decode()['euroFunds']);
        self::assertCount(1, $funds);
        self::assertNull($funds[0]['latestRateYear']);
        self::assertTrue($funds[0]['missingLastYear']);
    }

    public function testRatesCanBeRemovedAndAreIsolatedByWorkspace(): void
    {
        $this->createContract();
        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2025));
        $id = self::text($this->decode()['id']);

        $this->send('DELETE', '/api/v1/life-insurance-euro-fund-rates/'.self::MISSING);
        self::assertResponseStatusCodeSame(404);
        $this->send('DELETE', '/api/v1/life-insurance-euro-fund-rates/'.$id);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(['euro_fund_rate.recorded', 'euro_fund_rate.removed'], $this->connection->fetchFirstColumn("SELECT event_type FROM audit_events WHERE entity_type = 'euro_fund_rate' ORDER BY occurred_at, id"));
    }

    public function testAnotherWorkspaceCannotReachAnyContractRoute(): void
    {
        $this->createContract();
        $this->send('POST', $this->path(suffix: '/fee-terms'), $this->feeTerms('2026-01-01'));
        $termsId = self::text($this->decode()['id']);
        $this->send('POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2025));
        $rateId = self::text($this->decode()['id']);
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->send('POST', '/api/v1/session', ['email' => WorkspaceFixture::OTHER_OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        self::assertResponseStatusCodeSame(204);

        foreach ([
            ['GET', $this->path(), null],
            ['PUT', $this->path(), $this->contract()],
            ['POST', $this->path(suffix: '/fee-terms'), $this->feeTerms('2026-02-01')],
            ['POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2025)],
            ['DELETE', '/api/v1/life-insurance-fee-terms/'.$termsId, null],
            ['DELETE', '/api/v1/life-insurance-euro-fund-rates/'.$rateId, null],
        ] as [$method, $uri, $body]) {
            $this->send($method, $uri, $body);
            self::assertResponseStatusCodeSame(404, $method.' '.$uri);
        }
        self::assertSame(1, $this->rows('portfolio_life_insurance_fee_terms'));
        self::assertSame(1, $this->rows('portfolio_euro_fund_rates'));
    }

    public function testMutationsRequireTheCsrfToken(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'invalid');

        foreach ([
            ['PUT', $this->path(), $this->contract()],
            ['POST', $this->path(suffix: '/fee-terms'), $this->feeTerms('2026-01-01')],
            ['POST', $this->path(suffix: '/euro-fund-rates'), $this->rate(2025)],
            ['DELETE', '/api/v1/life-insurance-fee-terms/'.self::MISSING, null],
            ['DELETE', '/api/v1/life-insurance-euro-fund-rates/'.self::MISSING, null],
        ] as [$method, $uri, $body]) {
            $this->send($method, $uri, $body);
            self::assertResponseStatusCodeSame(403, $method.' '.$uri);
        }
        self::assertSame(0, $this->rows('portfolio_life_insurance_contracts'));
    }

    private function createContract(): void
    {
        $this->send('PUT', $this->path(), $this->contract());
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function contract(array $overrides = []): array
    {
        return [
            'insurer' => 'Assureur Démo', 'distributor' => null, 'commercialName' => 'Contrat Démo Vie', 'contractNumber' => 'AV-00012345',
            'subscribedOn' => '2024-02-10', 'managementMode' => 'FREE',
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function feeTerms(string $validFrom, array $overrides = []): array
    {
        return [
            'validFrom' => $validFrom, 'entryFeeRate' => '1', 'euroFundManagementFeeRate' => '0.60', 'unitLinkedManagementFeeRate' => '0.85',
            'switchFeeRate' => '0.50', 'switchFeeFixed' => ['value' => '5.00', 'assetCode' => 'EUR'], 'sourceNote' => 'Conditions générales 2026',
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function rate(int $year, array $overrides = []): array
    {
        return [
            'instrumentId' => self::EURO_FUND, 'year' => $year, 'servedRate' => '2.50', 'basis' => 'NET_OF_MANAGEMENT_FEES', 'sourceNote' => 'Relevé annuel',
            ...$overrides,
        ];
    }

    private function path(string $account = self::LIFE, string $suffix = ''): string
    {
        return '/api/v1/accounts/'.$account.'/life-insurance-contract'.$suffix;
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function rows(string $table): int
    {
        return self::integer($this->connection->fetchOne('SELECT count(*) FROM '.$table));
    }

    private static function integer(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        return self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $typed = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $typed[$key] = $item;
        }

        return $typed;
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        return array_map(self::map(...), array_values($value));
    }
}
