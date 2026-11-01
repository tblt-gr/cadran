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
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Opened and first operation on 2026-07-01 with 100 000.00, read on 2026-10-31. July is a Modified Dietz month
 * with an opening at zero: 1 000 / (100 000 x 30/31) = 31/3000 = 0.010333. August 98 980 / 101 000 - 1 = -0.02
 * and September 101 949.40 / 98 980 - 1 = 0.03 are exact. Indexes: 101.0333 -> 101.03, x 0.98 = 99.0127 -> 99.01,
 * x 1.03 = 101.9830 -> 101.98; cumulative 1.0198304667 - 1 = 0.019830. The 0.01 / -0.02 / 0.03 chain of the
 * specification is pinned on the rates themselves in ReturnTrendTest.
 */
final class PortfolioReturnTrendControllerTest extends WebTestCase
{
    private const string ACCOUNT = PortfolioFixture::ACCOUNT;
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000d7';
    private const string SNAPSHOTS = '00000000-0000-7000-8000-0000000000d8';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private PortfolioFixture $portfolio;
    private MockClock $clock;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->clock = new MockClock('2026-10-31T12:00:00+00:00');
        self::getContainer()->set(ClockInterface::class, $this->clock);
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
        $this->portfolio->account();
        $this->portfolio->account(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin');
        $this->portfolio->account(self::SNAPSHOTS, label: 'Assurance-vie', valuationMode: 'SNAPSHOTS');
        $this->portfolio->instrument(PortfolioFixture::ETF, name: 'ETF Monde Démo', isin: 'IE00B4L5Y983');

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

    public function testTheWindowChainsFromTheOpeningMonthAndCarriesTheUnroundedCumulativeReturn(): void
    {
        $this->seedWorkedExample();

        $trend = $this->trend(self::ACCOUNT);

        self::assertCount(12, $trend['months']);
        self::assertSame('2025-10', $trend['months'][0]['month']);
        self::assertSame('2026-09', $trend['months'][11]['month']);
        self::assertSame('2026-09', $trend['to']);
        self::assertSame(array_fill(0, 9, null), array_column(array_slice($trend['months'], 0, 9), 'index'));
        self::assertSame(['101.03', '99.01', '101.98'], array_column(array_slice($trend['months'], 9), 'index'));
        self::assertSame('2026-07', $trend['from']);
        self::assertSame('0.019830', $trend['cumulativeReturn']);
        self::assertNull($trend['reason']);
        self::assertNull($trend['reasonMonth']);
    }

    public function testAMonthWithoutValuationBreaksTheChainAndNamesTheMonth(): void
    {
        $this->seedWorkedExample(withAugust: false);

        $trend = $this->trend(self::ACCOUNT);

        self::assertSame(['101.03', null, null], array_column(array_slice($trend['months'], 9), 'index'));
        self::assertNull($trend['cumulativeReturn']);
        self::assertSame('MISSING_MONTH', $trend['reason']);
        self::assertSame('2026-08', $trend['reasonMonth']);
    }

    public function testAnAccountWithoutOperationHasNoHistory(): void
    {
        $this->opened('2026-03-15');

        $trend = $this->trend(self::ACCOUNT);

        self::assertSame(array_fill(0, 12, null), array_column($trend['months'], 'index'));
        self::assertNull($trend['from']);
        self::assertNull($trend['cumulativeReturn']);
        self::assertSame('NO_HISTORY', $trend['reason']);
        self::assertNull($trend['reasonMonth']);
    }

    public function testAnOperationInTheCurrentMonthIsStillNoHistory(): void
    {
        $this->opened('2026-10-01');
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-10-02', 'grossAmount' => $this->eur('1000.00')]);

        self::assertSame('NO_HISTORY', $this->trend(self::ACCOUNT)['reason']);
    }

    public function testTheWindowFollowsTheCalendarOnTheFirstDayOfAMonth(): void
    {
        $this->clock->modify('2026-11-01T12:00:00+00:00');

        $trend = $this->trend(self::ACCOUNT);

        self::assertSame('2025-11', $trend['months'][0]['month']);
        self::assertSame('2026-10', $trend['to']);
    }

    public function testAnotherWorkspaceAccountIsNeverListed(): void
    {
        $this->seedWorkedExample();

        $this->get('/api/v1/portfolios');
        $items = $this->items();

        self::assertSame([self::ACCOUNT], array_column($items, 'accountId'));
        self::assertStringNotContainsString('Voisin', (string) $this->client->getResponse()->getContent());
        self::assertArrayHasKey('returnTrend', $items[0]);
    }

    public function testADensePortfolioLosesOnlyItsOwnTrend(): void
    {
        $this->seedWorkedExample();
        $dense = '00000000-0000-7000-8000-0000000000e1';
        $this->portfolio->account($dense, label: 'Dense', openedOn: '2026-01-01');
        $this->connection->executeStatement(
            "INSERT INTO portfolio_operations (id, workspace_id, account_id, operation_type, trade_date, sequence, asset_code, gross_amount_value, gross_amount_scale, fees_value, fees_scale, taxes_value, taxes_scale, cash_amount_value, cash_amount_scale, state, created_at, created_by, version)
             SELECT ('00000000-0000-7000-8005-' || lpad(n::text, 12, '0'))::uuid, :ws, :account, 'DEPOSIT', '2026-03-15', n, 'EUR', 1, 0, 0, 0, 0, 0, 1, 0, 'ACTIVE', now(), :owner, 1 FROM generate_series(1, 5001) AS n",
            ['ws' => WorkspaceFixture::OWN_WORKSPACE, 'account' => $dense, 'owner' => WorkspaceFixture::OWNER_ID],
        );

        $healthy = $this->trend(self::ACCOUNT);
        $trend = $this->trend($dense);

        self::assertSame('0.019830', $healthy['cumulativeReturn']);
        self::assertSame(array_fill(0, 12, null), array_column($trend['months'], 'index'));
        self::assertSame('2025-10', $trend['months'][0]['month']);
        self::assertSame('2026-09', $trend['to']);
        self::assertNull($trend['from']);
        self::assertNull($trend['cumulativeReturn']);
        self::assertSame('TOO_MANY_OPERATIONS', $trend['reason']);
        self::assertNull($trend['reasonMonth']);
    }

    private function seedWorkedExample(bool $withAugust = true): void
    {
        $this->opened('2026-07-01');
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-07-01', 'grossAmount' => $this->eur('100000.00')]);
        $this->valuation(['valuedOn' => '2026-07-31', 'detail' => 'TOTAL', 'totalValue' => $this->eur('101000.00')]);
        if ($withAugust) {
            $this->valuation(['valuedOn' => '2026-08-31', 'detail' => 'TOTAL', 'totalValue' => $this->eur('98980.00')]);
        }
        $this->valuation(['valuedOn' => '2026-09-30', 'detail' => 'TOTAL', 'totalValue' => $this->eur('101949.40')]);
    }

    /** @return array{months: list<array{month: string, index: ?string}>, from: ?string, to: string, cumulativeReturn: ?string, reason: ?string, reasonMonth: ?string} */
    private function trend(string $accountId): array
    {
        $this->get('/api/v1/portfolios');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        foreach ($this->items() as $item) {
            if ($item['accountId'] === $accountId) {
                /** @var array{months: list<array{month: string, index: ?string}>, from: ?string, to: string, cumulativeReturn: ?string, reason: ?string, reasonMonth: ?string} $trend */
                $trend = $item['returnTrend'];

                return $trend;
            }
        }

        self::fail('The account is not listed.');
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        $items = $this->decode()['items'];
        self::assertIsArray($items);

        $list = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            $typed = [];
            foreach ($item as $key => $value) {
                $typed[(string) $key] = $value;
            }
            $list[] = $typed;
        }

        return $list;
    }

    private function opened(string $day, string $account = self::ACCOUNT): void
    {
        $this->connection->executeStatement('UPDATE account_financial_accounts SET opened_on = :day, updated_at = now() WHERE id = :id', ['day' => $day, 'id' => $account]);
    }

    /** @param array<string, mixed> $body */
    private function valuation(array $body): void
    {
        $this->send('POST', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuations', $body);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @param array<string, mixed> $body */
    private function record(array $body): void
    {
        $this->send('POST', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-operations', $body);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @return array{value: string, assetCode: string} */
    private function eur(string $value): array
    {
        return ['value' => $value, 'assetCode' => 'EUR'];
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function get(string $uri): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $typed = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $typed[$key] = $value;
        }

        return $typed;
    }
}
