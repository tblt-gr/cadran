<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/** The euro-fund support and the operation rules of a life-insurance contract, through the public API. */
final class EuroFundSupportControllerTest extends WebTestCase
{
    private const string LIFE = PortfolioFixture::ACCOUNT;
    private const string SECURITIES = PortfolioFixture::CASH_ACCOUNT;
    private const string FUND = '00000000-0000-7000-8000-000000000b03';
    private const string ETF = PortfolioFixture::ETF;

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
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
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        self::getContainer()->set(ClockInterface::class, new MockClock('2026-10-05T12:00:00+00:00'));

        $portfolio = new PortfolioFixture($connection);
        $portfolio->account(self::LIFE, label: 'Assurance-vie', kind: 'INSURANCE_CONTRACT');
        $portfolio->account(self::SECURITIES, label: 'Compte-titres');
        $portfolio->euroFund(self::FUND);
        $portfolio->instrument(self::ETF, name: 'ETF Monde', isin: 'IE00B4L5Y983');

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

    public function testAnEuroFundIsCreatedWithoutIdentifiersAndWithForcedPrecisions(): void
    {
        $this->send('POST', '/api/v1/instruments', ['name' => 'Fonds euros Nouveau', 'instrumentType' => 'EURO_FUND', 'currencyCode' => 'EUR', 'quantityPrecision' => 6, 'pricePrecision' => 4]);

        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $fund = $this->decode();
        self::assertSame('EURO_FUND', $fund['instrumentType']);
        self::assertNull($fund['isin']);
        self::assertNull($fund['ticker']);
        self::assertNull($fund['venueMic']);
        self::assertSame(2, $fund['quantityPrecision']);
        self::assertSame(0, $fund['pricePrecision']);
        self::assertSame('EUR', $fund['currencyCode']);
    }

    public function testAnEuroFundRefusesAnIdentifierOrAVenue(): void
    {
        foreach ([['isin' => 'FR0000120271'], ['ticker' => 'FEUR'], ['venueMic' => 'XPAR']] as $identifier) {
            $this->send('POST', '/api/v1/instruments', ['name' => 'Fonds euros Démo', 'instrumentType' => 'EURO_FUND', 'currencyCode' => 'EUR', ...$identifier]);
            self::assertResponseStatusCodeSame(422, (string) $this->client->getResponse()->getContent());
            self::assertSame('instrument_field_not_allowed', $this->decode()['code']);
        }
        $count = $this->connection->fetchOne("SELECT count(*) FROM portfolio_instruments WHERE instrument_type = 'EURO_FUND'");
        self::assertIsInt($count);
        self::assertSame(1, $count);
    }

    public function testOtherTypesStillNeedAnIdentifier(): void
    {
        $this->send('POST', '/api/v1/instruments', ['name' => 'Sans identifiant', 'instrumentType' => 'FUND', 'currencyCode' => 'EUR']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('instrument_identifier_required', $this->decode()['code']);
    }

    public function testAnOperationOnAContractAccountNeedsTheContract(): void
    {
        foreach ([
            $this->buy(self::FUND, '1', '100'),
            ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('100')],
        ] as $body) {
            $this->send('POST', $this->operations(self::LIFE), $body);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('life_insurance_contract_required', $this->decode()['code']);
        }
        self::assertSame(0, $this->rows('portfolio_operations'));
    }

    public function testASecuritiesAccountKeepsItsDepositWhileAContractRefusesIt(): void
    {
        $this->send('POST', $this->operations(self::SECURITIES), ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('500')]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        $this->createContract();
        foreach ([
            ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('500')],
            ['type' => 'WITHDRAWAL', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('10')],
            ['type' => 'SECURITY_TRANSFER_IN', 'tradeDate' => '2026-03-02', 'instrumentId' => self::ETF, 'quantity' => '1'],
            ['type' => 'SECURITY_TRANSFER_OUT', 'tradeDate' => '2026-03-02', 'instrumentId' => self::ETF, 'quantity' => '1'],
        ] as $body) {
            $this->send('POST', $this->operations(self::LIFE), $body);
            self::assertResponseStatusCodeSame(422, $body['type']);
            self::assertSame('operation_type_not_allowed_for_account', $this->decode()['code'], $body['type']);
        }
    }

    public function testAnEuroFundOperationMustBePricedAtOne(): void
    {
        $this->createContract();

        $this->send('POST', $this->operations(self::LIFE), $this->buy(self::FUND, '1.01', '101.00'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('euro_fund_price_fixed', $this->decode()['code']);

        $this->send('POST', $this->operations(self::LIFE), $this->buy(self::FUND, '1', '100.00'));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    public function testTheValuationDraftPricesAnEuroFundAtOneOnTheValuationDate(): void
    {
        $this->createContract();
        $this->send('POST', $this->operations(self::LIFE), $this->buy(self::FUND, '1', '100.00'));
        self::assertResponseStatusCodeSame(201);

        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/portfolio-valuations/draft?valuedOn=2026-04-30');

        self::assertResponseIsSuccessful();
        $positions = $this->decode()['positions'];
        self::assertIsArray($positions);
        self::assertCount(1, $positions);
        $line = $positions[0];
        self::assertIsArray($line);
        self::assertSame('1', $line['lastPrice']);
        self::assertSame('2026-04-30', $line['lastPriceDate']);
        self::assertNull($line['lastPriceOrigin']);
        self::assertSame('100.00', $line['quantity']);
    }

    public function testLargeEuroFundQuantitiesKeepTheirExactGrossAmount(): void
    {
        $this->createContract();
        $body = $this->buy(self::FUND, '1', '1234567890123456789.12');
        $grossInput = $body['grossAmount'];
        self::assertIsArray($grossInput);
        self::assertSame('1234567890123456789.12', $grossInput['value']);
        $this->send('POST', $this->operations(self::LIFE), $body);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $gross = $this->decode()['grossAmount'];
        self::assertIsArray($gross);
        self::assertSame('1234567890123456789.12', $gross['value']);
    }

    public function testAPositionsValuationRefusesAnEuroFundPriceOtherThanOne(): void
    {
        $this->createContract();
        $this->send('POST', $this->operations(self::LIFE), $this->buy(self::FUND, '1', '100.00'));
        self::assertResponseStatusCodeSame(201);

        $this->send('POST', '/api/v1/accounts/'.self::LIFE.'/portfolio-valuations', $this->positions('1.5'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('euro_fund_price_fixed', $this->decode()['code']);

        $this->send('POST', '/api/v1/accounts/'.self::LIFE.'/portfolio-valuations', $this->positions('1'));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $recorded = $this->decode();
        $lines = $recorded['positions'];
        self::assertIsArray($lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame('CURRENT', $line['priceQuality']);
        $marketValue = $line['marketValue'];
        self::assertIsArray($marketValue);
        self::assertSame('100.00', $marketValue['value']);
    }

    public function testTheContractAccountIsListedAmongPortfoliosOnlyInPortfolioMode(): void
    {
        $portfolio = new PortfolioFixture($this->connection);
        $portfolio->account('00000000-0000-7000-8000-0000000000d4', label: 'Assurance-vie manuelle', kind: 'INSURANCE_CONTRACT', valuationMode: 'SNAPSHOTS');
        $portfolio->account('00000000-0000-7000-8000-0000000000d5', label: 'PEE', kind: 'EMPLOYEE_BENEFIT');

        $this->send('GET', '/api/v1/portfolios');

        self::assertResponseIsSuccessful();
        $items = $this->decode()['items'] ?? $this->decode();
        self::assertIsArray($items);
        $labels = array_map(static fn (mixed $item): string => is_array($item) && is_string($item['label'] ?? null) ? $item['label'] : '', array_values($items));
        self::assertContains('Assurance-vie', $labels);
        self::assertContains('Compte-titres', $labels);
        self::assertNotContains('Assurance-vie manuelle', $labels);
        self::assertNotContains('PEE', $labels);
    }

    private function createContract(): void
    {
        $this->send('PUT', '/api/v1/accounts/'.self::LIFE.'/life-insurance-contract', [
            'insurer' => 'Assureur Démo', 'subscribedOn' => '2024-02-10', 'managementMode' => 'FREE', 'contractNumber' => 'AV-00012345',
        ]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function buy(string $instrument, string $price, string $quantity): array
    {
        return [
            'type' => 'BUY', 'tradeDate' => '2026-03-02', 'instrumentId' => $instrument, 'quantity' => $quantity, 'unitPrice' => $price,
            'grossAmount' => $this->eur(ExactDecimal::multiply(DecimalValue::fromString($price), DecimalValue::fromString($quantity))->toString()),
        ];
    }

    /** @return array<string, mixed> */
    private function positions(string $price): array
    {
        return [
            'valuedOn' => '2026-04-30', 'detail' => 'POSITIONS', 'observedCash' => $this->eur('0'),
            'prices' => [['instrumentId' => self::FUND, 'price' => $price, 'priceDate' => '2026-04-30']],
        ];
    }

    /** @return array{value: string, assetCode: string} */
    private function eur(string $value): array
    {
        return ['value' => $value, 'assetCode' => 'EUR'];
    }

    private function operations(string $account): string
    {
        return '/api/v1/accounts/'.$account.'/portfolio-operations';
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function rows(string $table): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM '.$table);
        self::assertIsInt($count);

        return $count;
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $typed = [];
        foreach ($decoded as $key => $item) {
            $typed[(string) $key] = $item;
        }

        return $typed;
    }
}
