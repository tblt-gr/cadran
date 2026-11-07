<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InstrumentHoldingControllerTest extends WebTestCase
{
    private const string PEA = PortfolioFixture::ACCOUNT;
    private const string CTO = '00000000-0000-7000-8000-0000000000e1';
    private const string AV = '00000000-0000-7000-8000-0000000000e2';
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000d7';
    private const string FOREIGN_INSTRUMENT = '00000000-0000-7000-8000-000000000b09';
    private const string W = PortfolioFixture::ETF;
    private const string A = PortfolioFixture::STOCK;

    private KernelBrowser $client;
    private WorkspaceFixture $fixture;
    private Connection $connection;
    private PortfolioFixture $portfolio;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
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

        $this->portfolio = new PortfolioFixture($connection);
        $this->portfolio->account(self::PEA, label: 'PEA');
        $this->portfolio->account(self::CTO, label: 'CTO');
        $this->portfolio->account(self::AV, label: 'Assurance-vie');
        $this->portfolio->account(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin');
        $this->portfolio->instrument(self::W, name: 'ETF Monde', isin: 'IE00B4L5Y983');
        $this->portfolio->instrument(self::A, name: 'Action Alpha', isin: 'FR0000120073');
        $this->portfolio->instrument(self::FOREIGN_INSTRUMENT, WorkspaceFixture::OTHER_WORKSPACE, 'Titre voisin', 'US0378331005');
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testReferenceHoldingAndHistoryAreExact(): void
    {
        $this->login();
        $this->operation('DEPOSIT', '2026-03-01', ['grossAmount' => ['value' => '2000.00', 'assetCode' => 'EUR']]);
        $this->operation('BUY', '2026-03-02', ['instrumentId' => self::W, 'quantity' => '10', 'unitPrice' => '100.00', 'grossAmount' => ['value' => '1000.00', 'assetCode' => 'EUR'], 'fees' => ['value' => '2.00', 'assetCode' => 'EUR']]);
        $this->operation('DIVIDEND', '2026-03-03', ['instrumentId' => self::W, 'grossAmount' => ['value' => '15.00', 'assetCode' => 'EUR'], 'taxes' => ['value' => '4.50', 'assetCode' => 'EUR']]);
        $this->operation('SELL', '2026-03-04', ['instrumentId' => self::W, 'quantity' => '4', 'unitPrice' => '110.00', 'grossAmount' => ['value' => '440.00', 'assetCode' => 'EUR'], 'fees' => ['value' => '1.00', 'assetCode' => 'EUR']]);
        $this->postValuation(self::PEA, ['valuedOn' => '2026-03-31', 'detail' => 'POSITIONS', 'prices' => [['instrumentId' => self::W, 'price' => '105.0000', 'priceDate' => '2026-03-31']]]);
        self::assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/api/v1/instruments/'.self::W.'/holding');
        self::assertResponseStatusCodeSame(200);
        $body = self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['realizedGain' => '39.20', 'income' => '15.00', 'feesAndTaxes' => '-5.50', 'totalGain' => '77.50', 'reason' => null], $body['totals']);
        $account = self::list($body['accounts'])[0];
        self::assertSame('28.80', $account['unrealizedGain']);
        self::assertSame(3, $account['operationCount']);
        self::assertSame('2026-03-02', $account['firstOperationOn']);
        self::assertSame('2026-03-04', $account['lastOperationOn']);
        $holdings = $this->holdings();
        self::assertSame(self::list($holdings['items'])[0], $body['holding']);
    }

    public function testUnknownAndForeignInstrumentAreIndistinguishable(): void
    {
        $this->login();
        foreach ([self::FOREIGN_INSTRUMENT, '00000000-0000-7000-8000-000000000fff', 'invalid'] as $id) {
            $this->client->request('GET', '/api/v1/instruments/'.$id.'/holding');
            self::assertResponseStatusCodeSame(404);
            self::assertSame('instrument_not_found', self::map(json_decode((string) $this->client->getResponse()->getContent(), true))['code']);
        }
    }

    public function testUnusedInstrumentHasNoHoldingOrHistory(): void
    {
        $this->login();
        $this->client->request('GET', '/api/v1/instruments/'.self::W.'/holding');
        self::assertResponseStatusCodeSame(200);
        $body = self::map(json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertNull($body['holding']);
        self::assertSame([], $body['accounts']);
        self::assertSame('0.00', self::map($body['totals'])['totalGain']);
    }

    public function testTwoAccountsHaveIndependentContributionsAndTheSameRetainedHolding(): void
    {
        $this->login();
        $this->holdETF(self::PEA, '10', '90.00', '2026-10-31', '100.00');
        $this->holdETF(self::CTO, '5', '96.00', '2026-11-03', '102.00');
        $holdings = $this->holdings();
        $body = $this->sheet();
        self::assertSame(self::list($holdings['items'])[0], $body['holding']);
        self::assertCount(2, self::list($body['accounts']));
        self::assertSame('130.00', self::map($body['totals'])['totalGain']);
    }

    public function testUnknownReleasedCostRemainsNull(): void
    {
        $this->login();
        $this->operation('SECURITY_TRANSFER_IN', '2026-03-01', ['instrumentId' => self::W, 'quantity' => '2']);
        $this->operation('SELL', '2026-03-02', ['instrumentId' => self::W, 'quantity' => '1', 'unitPrice' => '100.00', 'grossAmount' => ['value' => '100.00', 'assetCode' => 'EUR']]);
        $body = $this->sheet();
        $totals = self::map($body['totals']);
        self::assertNull($totals['realizedGain']);
        self::assertNull($totals['totalGain']);
        self::assertSame('UNKNOWN_LOT_COST', $totals['reason']);
    }

    public function testSoldOutInstrumentKeepsRealizedGainWithoutAnUnrealizedValue(): void
    {
        $this->login();
        $this->operation('DEPOSIT', '2026-03-01', ['grossAmount' => ['value' => '1000.00', 'assetCode' => 'EUR']]);
        $this->operation('BUY', '2026-03-02', ['instrumentId' => self::W, 'quantity' => '1', 'unitPrice' => '100.00', 'grossAmount' => ['value' => '100.00', 'assetCode' => 'EUR']]);
        $this->operation('SELL', '2026-03-03', ['instrumentId' => self::W, 'quantity' => '1', 'unitPrice' => '110.00', 'grossAmount' => ['value' => '110.00', 'assetCode' => 'EUR']]);
        $body = $this->sheet();
        self::assertNull($body['holding']);
        self::assertSame('10.00', self::map($body['totals'])['totalGain']);
        self::assertCount(1, self::list($body['accounts']));
    }

    public function testMissingValuationDoesNotTurnAHeldPositionIntoZero(): void
    {
        $this->login();
        $this->operation('SECURITY_TRANSFER_IN', '2026-03-01', ['instrumentId' => self::W, 'quantity' => '2', 'costBasis' => ['value' => '100.00', 'assetCode' => 'EUR']]);
        $this->portfolio->discardAutomaticValuations();
        $body = $this->sheet();
        self::assertNull($body['holding']);
        self::assertNull(self::map($body['totals'])['totalGain']);
        self::assertSame('NO_VALUATION', self::map($body['totals'])['reason']);
    }

    public function testOversizedHistoryWithholdsOnlyThatAccountsFigures(): void
    {
        $this->login();
        $this->holdETF(self::CTO, '1', '100.00', '2026-03-31', '110.00');
        $this->operation('SECURITY_TRANSFER_IN', '2026-03-01', ['instrumentId' => self::W, 'quantity' => '2']);
        $this->operation('DEPOSIT', '2026-03-02', ['grossAmount' => ['value' => '1.00', 'assetCode' => 'EUR']]);
        $seed = $this->connection->fetchAssociative('SELECT * FROM portfolio_operations WHERE workspace_id = :workspace AND account_id = :account AND operation_type = :type', ['workspace' => WorkspaceFixture::OWN_WORKSPACE, 'account' => self::PEA, 'type' => 'DEPOSIT']);
        self::assertIsArray($seed);
        $this->connection->transactional(function () use ($seed): void {
            for ($index = 2; $index <= 5000; ++$index) {
                $row = $seed;
                $row['id'] = '00000000-0000-7000-8000-'.sprintf('%012x', 100000 + $index);
                $row['sequence'] = $index;
                $this->connection->insert('portfolio_operations', $row);
            }
        });
        $body = $this->sheet();
        $rows = array_column(self::list($body['accounts']), null, 'accountId');
        $large = self::map($rows[self::PEA]);
        self::assertSame('HISTORY_TOO_LARGE', $large['reason']);
        foreach (['quantity', 'value', 'unrealizedGain', 'realizedGain', 'income', 'feesAndTaxes', 'operationCount', 'firstOperationOn', 'lastOperationOn'] as $key) {
            self::assertNull($large[$key], $key);
        }
        self::assertSame('0.00', self::map($rows[self::CTO])['realizedGain']);
        self::assertNull(self::map($body['totals'])['totalGain']);
        self::assertSame('HISTORY_TOO_LARGE', self::map($body['totals'])['reason']);
    }

    public function testArchivedInstrumentsStayReadableAndTheReadRequiresASession(): void
    {
        $this->client->request('GET', '/api/v1/instruments/'.self::W.'/holding');
        self::assertResponseStatusCodeSame(401);
        $this->connection->update('portfolio_instruments', ['status' => 'INACTIVE'], ['id' => self::W, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE]);
        $this->login();
        self::assertSame('INACTIVE', self::map($this->sheet()['instrument'])['status']);
    }

    /** @return array<string, mixed> */
    private function sheet(): array
    {
        $this->client->request('GET', '/api/v1/instruments/'.self::W.'/holding');
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        return self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $fields */
    private function operation(string $type, string $day, array $fields): void
    {
        $this->send('POST', '/api/v1/accounts/'.self::PEA.'/portfolio-operations', ['type' => $type, 'tradeDate' => $day, ...$fields]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    private function login(): void
    {
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->send('POST', '/api/v1/session', ['email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        self::assertResponseStatusCodeSame(204);
    }

    private function holdETF(string $account, string $quantity, string $unitPrice, string $valuedOn, string $price): void
    {
        $gross = (string) \Brick\Math\BigDecimal::of($quantity)->multipliedBy($unitPrice)->toScale(2);
        $this->send('POST', '/api/v1/accounts/'.$account.'/portfolio-operations', ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => ['value' => '5000.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', '/api/v1/accounts/'.$account.'/portfolio-operations', ['type' => 'BUY', 'tradeDate' => '2026-03-03', 'instrumentId' => self::W,
            'quantity' => $quantity, 'unitPrice' => $unitPrice, 'grossAmount' => ['value' => $gross, 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $this->postValuation($account, ['valuedOn' => $valuedOn, 'detail' => 'POSITIONS', 'prices' => [['instrumentId' => self::W, 'price' => $price, 'priceDate' => $valuedOn]]]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @param array<string, mixed> $body */
    private function postValuation(string $account, array $body): void
    {
        $this->send('POST', '/api/v1/accounts/'.$account.'/portfolio-valuations', $body);
    }

    /** @return array<string, mixed> */
    private function holdings(): array
    {
        $this->client->request('GET', '/api/v1/holdings', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        return self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
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
}
