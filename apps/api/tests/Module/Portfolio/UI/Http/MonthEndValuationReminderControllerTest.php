<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Portfolio\Infrastructure\Persistence\ValuationRow;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/** The month-end valuations owed, computed from source data in the workspace calendar. */
final class MonthEndValuationReminderControllerTest extends WebTestCase
{
    private const string ACCOUNT = PortfolioFixture::ACCOUNT;
    private const string SECOND = '00000000-0000-7000-8000-0000000000d3';
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
        $this->clock = new MockClock('2026-09-30T12:00:00+00:00');
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

    public function testTheMonthIsDueFromItsLastDayInTheWorkspaceCalendar(): void
    {
        $this->opened('2026-09-01');

        $this->at('2026-09-29T12:00:00+00:00');
        self::assertSame([], $this->reminders()['items']);

        $this->at('2026-09-30T12:00:00+00:00');
        $body = $this->reminders();
        self::assertSame('2026-09-30', $body['today']);
        self::assertSame([['accountId' => self::ACCOUNT, 'accountLabel' => 'Compte-titres', 'month' => '2026-09', 'valuationDate' => '2026-09-30']], $body['items']);
        self::assertSame(['2026-09'], $this->summary()['dueMonths']);

        $this->at('2026-10-02T12:00:00+00:00');
        self::assertSame(['2026-09'], $this->months(), 'a missed month stays due');
    }

    public function testTheSameInstantIsAnotherDayInAnotherWorkspaceTimeZone(): void
    {
        $this->opened('2026-09-01');
        $this->at('2026-09-29T23:30:00+00:00');
        self::assertSame(['2026-09'], $this->months(), 'Paris is already on 30 September');
        self::assertSame('2026-09-30', $this->reminders()['today']);

        $this->connection->executeStatement("UPDATE identity_workspaces SET timezone = 'America/New_York'");
        self::assertSame([], $this->reminders()['items'], 'New York is still on 29 September');
        self::assertSame('2026-09-29', $this->reminders()['today']);

        $this->at('2026-10-01T03:59:00+00:00');
        self::assertSame(['2026-09'], $this->months());
        self::assertSame('2026-09-30', $this->reminders()['today']);
    }

    public function testTheWindowIsTwelveMonthsBackAndNeverBeforeTheOpeningMonth(): void
    {
        $this->opened('2020-01-01');
        $months = $this->months();
        self::assertCount(13, $months);
        self::assertSame('2025-09', $months[0]);
        self::assertSame('2026-09', $months[12]);

        $this->opened('2026-07-15');
        self::assertSame(['2026-07', '2026-08', '2026-09'], $this->months());
        self::assertSame(['2026-07', '2026-08', '2026-09'], $this->summary()['dueMonths']);
    }

    public function testACompleteValuationInTheMonthSatisfiesItAndAPartialOneDoesNot(): void
    {
        $this->opened('2026-06-01');
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-06-02', 'grossAmount' => $this->eur('3000.00')]);
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-06-03', 'instrumentId' => PortfolioFixture::ETF, 'quantity' => '10', 'unitPrice' => '231.20', 'grossAmount' => $this->eur('2312.00')]);

        $this->valuation(['valuedOn' => '2026-06-15', 'detail' => 'TOTAL', 'totalValue' => $this->eur('3664.70')]);
        $this->valuation(['valuedOn' => '2026-07-31', 'detail' => 'POSITIONS', 'prices' => [['instrumentId' => PortfolioFixture::ETF, 'price' => null, 'priceDate' => null]]]);
        $this->valuation(['valuedOn' => '2026-09-01', 'detail' => 'TOTAL', 'totalValue' => $this->eur('3700.00')]);

        self::assertSame(['2026-07', '2026-08'], $this->months());

        $this->valuation(['valuedOn' => '2026-07-30', 'detail' => 'TOTAL', 'totalValue' => $this->eur('3680.00')]);
        self::assertSame(['2026-08'], $this->summary()['dueMonths']);
    }

    public function testAValuationOnTheFirstOfTheNextMonthDoesNotSatisfyTheMonth(): void
    {
        $this->opened('2026-08-01');
        $this->valuation(['valuedOn' => '2026-09-01', 'detail' => 'TOTAL', 'totalValue' => $this->eur('100.00')]);

        self::assertSame(['2026-08'], $this->months());
    }

    public function testAClosedMonthIsNotDueAndTheListIsOrderedByMonthThenLabel(): void
    {
        $this->portfolio->account(self::SECOND, label: 'Assurance titres');
        $this->opened('2026-08-01', self::SECOND);
        $this->opened('2026-07-01');
        $this->connection->insert('account_period_closures', [
            'id' => '00000000-0000-7000-8000-0000000000c1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'year' => 2026, 'month' => 8,
            'closed_at' => '2026-09-02 10:00:00+00', 'closed_by' => WorkspaceFixture::OWNER_ID, 'version' => 1,
        ]);

        self::assertSame(
            [['2026-07', 'Compte-titres'], ['2026-09', 'Assurance titres'], ['2026-09', 'Compte-titres']],
            array_map(static fn (array $item): array => [$item['month'], $item['accountLabel']], $this->reminders()['items']),
        );

        $this->connection->executeStatement('UPDATE account_period_closures SET reopened_at = now(), reopen_reason = \'Correction\'');
        self::assertContains('2026-08', $this->months());
    }

    public function testClosedArchivedAndNonPortfolioAccountsAreExcluded(): void
    {
        $this->portfolio->account(self::SECOND, label: 'Ancien', openedOn: '2026-01-01', closedOn: '2026-03-14');
        $this->connection->executeStatement("UPDATE account_financial_accounts SET archived_at = now() WHERE id = '".self::ACCOUNT."'");

        self::assertSame(['2026-01', '2026-02', '2026-03'], $this->months());
        self::assertSame([self::SECOND], $this->accountIds());
    }

    public function testMoreThanTwoHundredRemindersAreAllReturned(): void
    {
        $this->opened('2020-01-01');
        for ($index = 1; $index <= 17; ++$index) {
            $id = sprintf('00000000-0000-7000-8000-0000000e%04d', $index);
            $this->portfolio->account($id, label: sprintf('Compte %02d', $index));
            $this->opened('2020-01-01', $id);
        }

        $items = $this->reminders()['items'];
        self::assertCount(18 * 13, $items);
        self::assertCount(234, array_unique(array_map(static fn (array $item): string => $item['accountId'].$item['month'], $items)));
    }

    public function testACompleteValuationOfAnotherWorkspaceDoesNotSatisfyTheMonth(): void
    {
        $this->opened('2026-08-01');
        $this->opened('2026-08-01', self::FOREIGN);
        $this->valuation(['valuedOn' => '2026-08-20', 'detail' => 'TOTAL', 'totalValue' => $this->eur('100.00')]);
        $this->connection->executeStatement(
            'INSERT INTO portfolio_valuations (workspace_id, '.ValuationRow::COLUMNS.') SELECT :other, :id, :foreign, valued_on, asset_code, cash_value, cash_scale, derived_cash_value, derived_cash_scale, cash_source,'
            .' securities_value_value, securities_value_scale, total_value_value, total_value_scale, observed_total_value, observed_total_scale, detail, completeness, source, note, supersedes_id,'
            .' superseded_at, withdrawn, recorded_at, :owner, version FROM portfolio_valuations WHERE account_id = :own',
            ['other' => WorkspaceFixture::OTHER_WORKSPACE, 'id' => '00000000-0000-7000-8000-0000000e9001', 'foreign' => self::FOREIGN, 'owner' => WorkspaceFixture::OTHER_OWNER_ID, 'own' => self::ACCOUNT],
        );
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM portfolio_valuations WHERE workspace_id = '".WorkspaceFixture::OTHER_WORKSPACE."'"));

        self::assertSame(['2026-09'], $this->months(), 'own August is satisfied by its own valuation only');
        $this->connection->executeStatement("UPDATE portfolio_valuations SET superseded_at = now() WHERE workspace_id = '".WorkspaceFixture::OWN_WORKSPACE."'");
        self::assertSame(['2026-08', '2026-09'], $this->months(), 'the foreign valuation of August does not stand in for it');
    }

    public function testRemindersNeverListAnotherWorkspaceAccount(): void
    {
        $this->opened('2026-09-01');

        self::assertSame([self::ACCOUNT], $this->accountIds());
        $listed = array_column($this->portfolios(), 'accountId');
        self::assertNotContains(self::FOREIGN, $listed);
        self::assertNotContains(self::SNAPSHOTS, $listed);
    }

    public function testTheListCarriesDueMonthsAndRequiresASession(): void
    {
        $this->opened('2026-08-01');
        self::assertSame(['2026-08', '2026-09'], $this->portfolios()[0]['dueMonths']);

        $this->client->getCookieJar()->clear();
        $this->get('/api/v1/portfolio-valuation-reminders');
        self::assertResponseStatusCodeSame(401);
    }

    private function opened(string $day, string $account = self::ACCOUNT): void
    {
        $this->connection->executeStatement('UPDATE account_financial_accounts SET opened_on = :day, updated_at = now() WHERE id = :id', ['day' => $day, 'id' => $account]);
    }

    private function at(string $instant): void
    {
        $this->clock->modify($instant);
    }

    /** @return list<string> */
    private function months(): array
    {
        return array_map(static fn (array $item): string => $item['month'], $this->reminders()['items']);
    }

    /** @return list<string> */
    private function accountIds(): array
    {
        return array_values(array_unique(array_map(static fn (array $item): string => $item['accountId'], $this->reminders()['items'])));
    }

    /** @return list<array{accountId: string, dueMonths: list<string>}> */
    private function portfolios(): array
    {
        $this->get('/api/v1/portfolios');
        /** @var array{items: list<array{accountId: string, dueMonths: list<string>}>} $body */
        $body = $this->decode();

        return $body['items'];
    }

    /** @return array{today: string, items: list<array{accountId: string, accountLabel: string, month: string, valuationDate: string}>} */
    private function reminders(): array
    {
        $this->get('/api/v1/portfolio-valuation-reminders');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        /** @var array{today: string, items: list<array{accountId: string, accountLabel: string, month: string, valuationDate: string}>} $body */
        $body = $this->decode();

        return $body;
    }

    /** @return array{dueMonths: list<string>} */
    private function summary(): array
    {
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-summary');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        /** @var array{dueMonths: list<string>} $body */
        $body = $this->decode();

        return $body;
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
