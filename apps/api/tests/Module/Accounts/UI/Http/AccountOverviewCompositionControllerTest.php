<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Courant 1 000.00 and Livret 1 000.00 sit in Liquidités, PEA 3 000.00 in
 * Investissement on 2026-09-05. The eligible net worth is 5 000.00, so the
 * shares are PEA 60.00 %, Courant 20.00 %, Livret 20.00 %. The equal Courant
 * and Livret fall back to the label order. An account of another workspace
 * never appears.
 */
final class AccountOverviewCompositionControllerTest extends WebTestCase
{
    private const string LIQUID = '00000000-0000-7000-8000-0000000000c1';
    private const string INVEST = '00000000-0000-7000-8000-0000000000c2';
    private const string FOREIGN_GROUP = '00000000-0000-7000-8000-0000000000c9';
    private const string COURANT = '00000000-0000-7000-8000-0000000000d1';
    private const string LIVRET = '00000000-0000-7000-8000-0000000000d2';
    private const string PEA = '00000000-0000-7000-8000-0000000000d3';
    private const string EXTRA = '00000000-0000-7000-8000-0000000000d5';
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-0000000000d9';
    private const string ASK = '/api/v1/accounts/overview/composition?asOf=2026-09-05';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
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
        $this->insertGroup(self::LIQUID, 'Liquidités', null, 1, WorkspaceFixture::OWN_WORKSPACE);
        $this->insertGroup(self::INVEST, 'Investissement', null, 1, WorkspaceFixture::OWN_WORKSPACE);
        $this->insertGroup(self::FOREIGN_GROUP, 'Voisin', null, 1, WorkspaceFixture::OTHER_WORKSPACE);
        $this->insertAccount(self::LIVRET, 'Livret', self::LIQUID);
        $this->insertAccount(self::COURANT, 'Courant', self::LIQUID);
        $this->insertAccount(self::PEA, 'PEA', self::INVEST, institution: 'Banque');
        $this->insertAccount(self::FOREIGN_ACCOUNT, 'Voisin', self::FOREIGN_GROUP, workspace: WorkspaceFixture::OTHER_WORKSPACE);

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
        $this->snapshot(self::COURANT, '1000.00');
        $this->snapshot(self::LIVRET, '1000.00');
        $this->snapshot(self::PEA, '3000.00');
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testTheWorkedExampleListsEveryAccountByValueThenLabel(): void
    {
        $composition = $this->get(self::ASK);

        self::assertSame('2026-09-05', $composition['asOf']);
        self::assertSame('5000.00', $this->map($composition['total'])['value']);
        self::assertNull($composition['totalReason']);
        self::assertNull($composition['chartReason']);
        $accounts = $this->list($composition['accounts']);
        self::assertSame([self::PEA, self::COURANT, self::LIVRET], array_column($accounts, 'id'));
        self::assertSame(['PEA', 'Courant', 'Livret'], array_column($accounts, 'label'));
        self::assertSame(['Banque', null, null], array_column($accounts, 'institution'));
        self::assertSame([self::INVEST, self::LIQUID, self::LIQUID], array_column($accounts, 'primaryGroupId'));
        self::assertSame(['3000.00', '1000.00', '1000.00'], array_map(fn (array $a): mixed => $this->map($a['value'])['value'], $accounts));
        self::assertSame(['60.00', '20.00', '20.00'], array_map(fn (array $a): mixed => $this->map($a['share'])['percentDisplay'], $accounts));
        self::assertSame('0.600000000000000000000000', $this->map($accounts[0]['share'])['ratio']);
        self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $this->text($accounts[0]['color']));
        self::assertSame($accounts[1]['color'], $accounts[2]['color']);
        self::assertNotSame($accounts[0]['color'], $accounts[1]['color']);
    }

    public function testEverySliceShareEqualsTheShareOfTheOverviewRow(): void
    {
        $composition = $this->list($this->get(self::ASK)['accounts']);
        $shares = [];
        foreach ([self::LIQUID, self::INVEST] as $group) {
            foreach ($this->list($this->get('/api/v1/accounts/overview/'.$group.'/accounts?asOf=2026-09-05')['items']) as $row) {
                $shares[$this->text($row['id'])] = $row['share'];
            }
        }

        self::assertCount(3, $shares);
        foreach ($composition as $slice) {
            self::assertSame($shares[$this->text($slice['id'])], $slice['share']);
        }
    }

    public function testALiabilityKeepsTheTableButWarnsTheChart(): void
    {
        $this->insertAccount(self::EXTRA, 'Prêt', self::LIQUID, kind: 'LIABILITY');
        $this->snapshot(self::EXTRA, '200.00');

        $composition = $this->get(self::ASK);

        self::assertSame('4800.00', $this->map($composition['total'])['value']);
        self::assertSame('NEGATIVE_COMPONENT', $composition['chartReason']);
        $accounts = $this->list($composition['accounts']);
        self::assertSame(self::EXTRA, $accounts[3]['id']);
        self::assertSame('-200.00', $this->map($accounts[3]['value'])['value']);
    }

    public function testAMissingValuationListsTheAccountWithoutValueLast(): void
    {
        $this->insertAccount(self::EXTRA, 'Sans relevé', self::LIQUID);

        $composition = $this->get(self::ASK);

        self::assertNull($composition['total']);
        self::assertSame('MISSING_VALUATION', $composition['totalReason']);
        self::assertSame('MISSING_VALUATION', $composition['chartReason']);
        $accounts = $this->list($composition['accounts']);
        self::assertCount(4, $accounts);
        self::assertSame(self::EXTRA, $accounts[3]['id']);
        self::assertNull($accounts[3]['value']);
        self::assertSame('MISSING_VALUATION', $this->map($accounts[3]['share'])['reason']);
    }

    public function testAZeroTotalGivesNullSharesAndAReason(): void
    {
        $this->insertAccount(self::EXTRA, 'Prêt', self::LIQUID, kind: 'LIABILITY');
        $this->snapshot(self::EXTRA, '5000.00');

        $composition = $this->get(self::ASK);

        self::assertSame('0.00', $this->map($composition['total'])['value']);
        self::assertSame('ZERO_TOTAL', $composition['chartReason']);
        self::assertNull($this->map($this->list($composition['accounts'])[0]['share'])['ratio']);
    }

    public function testANegativeTotalGivesAReason(): void
    {
        $this->insertAccount(self::EXTRA, 'Prêt', self::LIQUID, kind: 'LIABILITY');
        $this->snapshot(self::EXTRA, '6000.00');

        $composition = $this->get(self::ASK);

        self::assertSame('-1000.00', $this->map($composition['total'])['value']);
        self::assertSame('NEGATIVE_TOTAL', $composition['chartReason']);
    }

    public function testAnAccountClosedBeforeTheDayIsNotListed(): void
    {
        $this->connection->update('account_financial_accounts', ['closed_on' => '2026-09-01'], ['id' => self::LIVRET]);

        $composition = $this->get(self::ASK);

        self::assertSame([self::PEA, self::COURANT], array_column($this->list($composition['accounts']), 'id'));
        self::assertSame('4000.00', $this->map($composition['total'])['value']);
    }

    public function testAsOfSelectsTheValuationDay(): void
    {
        $this->post('/api/v1/accounts/'.self::PEA.'/balances', ['asOf' => '2026-09-10', 'amount' => '4000.00', 'amountAssetCode' => 'EUR', 'comment' => null, 'version' => null]);

        $after = $this->get('/api/v1/accounts/overview/composition?asOf=2026-09-10');

        self::assertSame('6000.00', $this->map($after['total'])['value']);
        self::assertSame('2026-09-10', $after['asOf']);
    }

    public function testAnEmptyWorkspaceListsNothing(): void
    {
        $this->connection->executeStatement('UPDATE account_financial_accounts SET closed_on = :day WHERE workspace_id = :workspace', ['day' => '2026-09-01', 'workspace' => WorkspaceFixture::OWN_WORKSPACE]);

        $composition = $this->get(self::ASK);

        self::assertSame([], $composition['accounts']);
        self::assertNull($composition['total']);
        self::assertSame('NO_ELIGIBLE_ACCOUNT', $composition['totalReason']);
        self::assertNull($composition['chartReason']);
    }

    public function testAnInvalidDateIsRefused(): void
    {
        $this->client->request('GET', '/api/v1/accounts/overview/composition?asOf=05-09-2026');

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testAnotherWorkspaceNeverContributes(): void
    {
        $composition = $this->get(self::ASK);

        self::assertNotContains(self::FOREIGN_ACCOUNT, array_column($this->list($composition['accounts']), 'id'));
        self::assertStringNotContainsString('Voisin', (string) $this->client->getResponse()->getContent());
        self::assertSame('5000.00', $this->map($composition['total'])['value']);
    }

    public function testAnAnonymousVisitorIsRefused(): void
    {
        $this->client->request('DELETE', '/api/v1/session');
        $this->client->request('GET', self::ASK);

        self::assertResponseStatusCodeSame(401);
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body): void
    {
        $this->client->request('POST', $uri, server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
    }

    /** @return array<string, mixed> */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(200, $uri);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        return $this->map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        self::assertIsArray($value);

        $typed = [];
        foreach ($value as $key => $item) {
            $typed[(string) $key] = $item;
        }

        return $typed;
    }

    private function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $value): array
    {
        self::assertIsList($value);

        return array_map($this->map(...), $value);
    }

    private function insertGroup(string $id, string $label, ?string $parentId, int $depth, string $workspace): void
    {
        $this->connection->insert('account_groups', [
            'id' => $id,
            'workspace_id' => $workspace,
            'label' => $label,
            'parent_id' => $parentId,
            'sort_order' => 0,
            'depth' => $depth,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
        ]);
    }

    private function insertAccount(
        string $id,
        string $label,
        ?string $groupId,
        string $kind = 'CURRENT',
        ?string $institution = null,
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
    ): void {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => $workspace,
            'label' => $label,
            'asset_code' => 'EUR',
            'kind' => $kind,
            'product_code' => null,
            'product_model_id' => null,
            'institution' => $institution,
            'masked_identifier' => null,
            'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => true,
            'include_in_emergency_fund' => false,
            'opened_on' => '2026-01-10',
            'closed_on' => null,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
            'primary_group_id' => $groupId,
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    private function snapshot(string $accountId, string $amount): void
    {
        $this->post('/api/v1/accounts/'.$accountId.'/balances', ['asOf' => '2026-09-05', 'amount' => $amount, 'amountAssetCode' => 'EUR', 'comment' => null, 'version' => null]);
    }

    /** @return array<string, string> */
    private static function jsonHeaders(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }
}
