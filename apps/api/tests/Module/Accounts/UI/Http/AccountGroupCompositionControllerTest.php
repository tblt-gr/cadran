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
 * Investissement holds Compte espèces 100.00 itself, Bourse (child) holds PEA
 * 900.00 and Immobilier (child) holds SCPI 500.00 on 2026-09-05. The group
 * total is 1500.00: Bourse 60.00 %, Immobilier 33.33 %, Comptes directs 6.67 %.
 * A Compte courant of 5 000.00 outside the group never moves any figure.
 */
final class AccountGroupCompositionControllerTest extends WebTestCase
{
    private const string ROOT = '00000000-0000-7000-8000-0000000000c1';
    private const string BOURSE = '00000000-0000-7000-8000-0000000000c2';
    private const string IMMO = '00000000-0000-7000-8000-0000000000c3';
    private const string OTHER_ROOT = '00000000-0000-7000-8000-0000000000c4';
    private const string FOREIGN_GROUP = '00000000-0000-7000-8000-0000000000c9';
    private const string CASH = '00000000-0000-7000-8000-0000000000d1';
    private const string PEA = '00000000-0000-7000-8000-0000000000d2';
    private const string SCPI = '00000000-0000-7000-8000-0000000000d3';
    private const string OUTSIDE = '00000000-0000-7000-8000-0000000000d4';
    private const string LOAN = '00000000-0000-7000-8000-0000000000d5';
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-0000000000d9';
    private const string ASK = '/api/v1/account-groups/'.self::ROOT.'/composition?asOf=2026-09-05';

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
        $this->insertGroup(self::ROOT, 'Investissement', null, 1, WorkspaceFixture::OWN_WORKSPACE);
        $this->insertGroup(self::BOURSE, 'Bourse', self::ROOT, 2, WorkspaceFixture::OWN_WORKSPACE);
        $this->insertGroup(self::IMMO, 'Immobilier', self::ROOT, 2, WorkspaceFixture::OWN_WORKSPACE);
        $this->insertGroup(self::OTHER_ROOT, 'Liquidités', null, 1, WorkspaceFixture::OWN_WORKSPACE);
        $this->insertGroup(self::FOREIGN_GROUP, 'Voisin', null, 1, WorkspaceFixture::OTHER_WORKSPACE);
        $this->insertAccount(self::CASH, 'Compte espèces', self::ROOT);
        $this->insertAccount(self::PEA, 'PEA', self::BOURSE, institution: 'Banque');
        $this->insertAccount(self::SCPI, 'SCPI', self::IMMO);
        $this->insertAccount(self::OUTSIDE, 'Compte courant', self::OTHER_ROOT);
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
        $this->snapshot(self::CASH, '100.00');
        $this->snapshot(self::PEA, '900.00');
        $this->snapshot(self::SCPI, '500.00');
        $this->snapshot(self::OUTSIDE, '5000.00');
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testTheWorkedExampleSplitsBySubgroupAndDirectAccounts(): void
    {
        $composition = $this->get(self::ASK);

        self::assertSame(self::ROOT, $composition['groupId']);
        self::assertSame('Investissement', $composition['label']);
        self::assertSame('2026-09-05', $composition['asOf']);
        self::assertSame('1500.00', $this->map($composition['total'])['value']);
        self::assertNull($composition['totalReason']);
        self::assertNull($composition['chartReason']);

        $slices = $this->list($composition['bySubgroup']);
        self::assertSame(['SUBGROUP', 'SUBGROUP', 'DIRECT'], array_column($slices, 'kind'));
        self::assertSame([self::BOURSE, self::IMMO, self::ROOT], array_column($slices, 'id'));
        self::assertSame(['Bourse', 'Immobilier', 'Investissement'], array_column($slices, 'label'));
        self::assertSame(['900.00', '500.00', '100.00'], array_map(fn (array $s): mixed => $this->map($s['value'])['value'], $slices));
        self::assertSame(['60.00', '33.33', '6.67'], array_map(fn (array $s): mixed => $this->map($s['share'])['percentDisplay'], $slices));
        self::assertSame('0.600000000000000000000000', $this->map($slices[0]['share'])['ratio']);
        self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $this->text($slices[0]['color']));
    }

    public function testTheAccountViewFollowsTheDepthFirstOrderAndPartitionsTheTotal(): void
    {
        $composition = $this->get(self::ASK);

        $accounts = $this->list($composition['byAccount']);
        self::assertSame([self::CASH, self::PEA, self::SCPI], array_column($accounts, 'id'));
        self::assertSame([self::ROOT, self::BOURSE, self::IMMO], array_column($accounts, 'primaryGroupId'));
        self::assertSame([null, 'Banque', null], array_column($accounts, 'institution'));
        self::assertSame(['6.67', '60.00', '33.33'], array_map(fn (array $a): mixed => $this->map($a['share'])['percentDisplay'], $accounts));
    }

    public function testALeafGroupHasNoSubgroupEntryAndNoDirectEntry(): void
    {
        $composition = $this->get('/api/v1/account-groups/'.self::BOURSE.'/composition?asOf=2026-09-05');

        self::assertSame([], $composition['bySubgroup']);
        self::assertSame('900.00', $this->map($composition['total'])['value']);
        self::assertSame(['100.00'], array_map(fn (array $a): mixed => $this->map($a['share'])['percentDisplay'], $this->list($composition['byAccount'])));
    }

    public function testADirectEntryNeedsBothChildrenAndDirectAccounts(): void
    {
        $this->connection->update('account_financial_accounts', ['primary_group_id' => self::BOURSE], ['id' => self::CASH]);

        $composition = $this->get(self::ASK);

        self::assertSame([self::BOURSE, self::IMMO], array_column($this->list($composition['bySubgroup']), 'id'));
        self::assertSame('1500.00', $this->map($composition['total'])['value']);
    }

    public function testAnEmptySubgroupIsOmittedFromTheSlices(): void
    {
        $this->connection->update('account_financial_accounts', ['closed_on' => '2026-09-01'], ['id' => self::SCPI]);

        $composition = $this->get(self::ASK);

        self::assertSame([self::BOURSE, self::ROOT], array_column($this->list($composition['bySubgroup']), 'id'));
        self::assertSame('1000.00', $this->map($composition['total'])['value']);
        self::assertSame([self::CASH, self::PEA], array_column($this->list($composition['byAccount']), 'id'));
    }

    public function testALiabilityKeepsTheSharesButWarnsTheChart(): void
    {
        $this->insertAccount(self::LOAN, 'Prêt', self::IMMO, kind: 'LIABILITY');
        $this->snapshot(self::LOAN, '200.00');

        $composition = $this->get(self::ASK);

        self::assertSame('1300.00', $this->map($composition['total'])['value']);
        self::assertSame('NEGATIVE_COMPONENT', $composition['chartReason']);
        $immobilier = $this->list($composition['bySubgroup'])[1];
        self::assertSame('300.00', $this->map($immobilier['value'])['value']);
        $loan = $this->list($composition['byAccount'])[3];
        self::assertSame(self::LOAN, $loan['id']);
        self::assertSame('-200.00', $this->map($loan['value'])['value']);
        self::assertSame('-15.38', $this->map($loan['share'])['percentDisplay']);
    }

    public function testAMissingValuationInsideTheGroupBlocksItButNotOutside(): void
    {
        $this->insertAccount(self::LOAN, 'Sans relevé', self::BOURSE);

        $composition = $this->get(self::ASK);

        self::assertNull($composition['total']);
        self::assertSame('MISSING_VALUATION', $composition['totalReason']);
        self::assertSame('MISSING_VALUATION', $composition['chartReason']);
        self::assertSame('MISSING_VALUATION', $this->map($this->list($composition['byAccount'])[0]['share'])['reason']);
        self::assertNull($this->map($this->list($composition['byAccount'])[0]['share'])['percentDisplay']);
        self::assertSame('500.00', $this->map($this->list($composition['byAccount'])[3]['value'])['value']);

        $outside = $this->get('/api/v1/account-groups/'.self::IMMO.'/composition?asOf=2026-09-05');
        self::assertSame('500.00', $this->map($outside['total'])['value']);
        self::assertNull($outside['chartReason']);
    }

    public function testAMissingValuationOutsideTheGroupDoesNotBlockIt(): void
    {
        $this->insertAccount(self::LOAN, 'Sans relevé', self::OTHER_ROOT);

        $composition = $this->get(self::ASK);

        self::assertSame('1500.00', $this->map($composition['total'])['value']);
        self::assertNull($composition['chartReason']);
    }

    public function testAZeroTotalGivesNullSharesAndAReason(): void
    {
        $this->insertAccount(self::LOAN, 'Prêt', self::BOURSE, kind: 'LIABILITY');
        $this->snapshot(self::LOAN, '1500.00');

        $composition = $this->get(self::ASK);

        self::assertSame('0.00', $this->map($composition['total'])['value']);
        self::assertSame('ZERO_GROUP_TOTAL', $composition['chartReason']);
        $share = $this->map($this->list($composition['bySubgroup'])[0]['share']);
        self::assertNull($share['ratio']);
        self::assertSame('ZERO_GROUP_TOTAL', $share['reason']);
    }

    public function testANegativeTotalGivesNullSharesAndAReason(): void
    {
        $this->insertAccount(self::LOAN, 'Prêt', self::BOURSE, kind: 'LIABILITY');
        $this->snapshot(self::LOAN, '2500.00');

        $composition = $this->get(self::ASK);

        self::assertSame('-1000.00', $this->map($composition['total'])['value']);
        self::assertSame('NEGATIVE_GROUP_TOTAL', $composition['chartReason']);
        self::assertSame('NEGATIVE_GROUP_TOTAL', $this->map($this->list($composition['byAccount'])[0]['share'])['reason']);
    }

    public function testAsOfSelectsTheValuationDay(): void
    {
        $this->post('/api/v1/accounts/'.self::PEA.'/balances', ['asOf' => '2026-09-10', 'amount' => '1900.00', 'amountAssetCode' => 'EUR', 'comment' => null, 'version' => null]);

        $before = $this->get(self::ASK);
        $after = $this->get('/api/v1/account-groups/'.self::ROOT.'/composition?asOf=2026-09-10');

        self::assertSame('1500.00', $this->map($before['total'])['value']);
        self::assertSame('2500.00', $this->map($after['total'])['value']);
        self::assertSame('2026-09-10', $after['asOf']);
    }

    public function testAnInvalidDateIsRefused(): void
    {
        $this->client->request('GET', '/api/v1/account-groups/'.self::ROOT.'/composition?asOf=05-09-2026');

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testArchivedForeignUnknownAndMalformedGroupsAreIndistinguishable404s(): void
    {
        $this->connection->update('account_groups', ['archived_at' => '2026-09-01 12:00:00+00'], ['id' => self::OTHER_ROOT]);

        $statuses = [];
        $bodies = [];
        foreach ([self::OTHER_ROOT, self::FOREIGN_GROUP, '00000000-0000-7000-8000-0000000000ff', 'not-a-uuid'] as $id) {
            $this->client->request('GET', '/api/v1/account-groups/'.$id.'/composition');
            $statuses[] = $this->client->getResponse()->getStatusCode();
            $bodies[] = (string) $this->client->getResponse()->getContent();
        }

        self::assertSame([404, 404, 404, 404], $statuses);
        self::assertCount(1, array_unique($bodies));
        self::assertStringNotContainsString('Voisin', $bodies[1]);
    }

    public function testAnotherWorkspaceNeverContributes(): void
    {
        $composition = $this->get(self::ASK);

        self::assertNotContains(self::FOREIGN_ACCOUNT, array_column($this->list($composition['byAccount']), 'id'));
        self::assertSame('1500.00', $this->map($composition['total'])['value']);
    }

    /**
     * The workspace net-worth read caps eligible accounts far below 1000, so
     * the subtree bound is reached through closed accounts, which are read to
     * decide eligibility on the requested day.
     */
    public function testMoreThanAThousandAccountsInTheSubtreeIsRefused(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO account_financial_accounts (id, workspace_id, label, asset_code, kind, valuation_mode, liquidity_level, include_in_net_worth, include_in_emergency_fund, opened_on, closed_on, version, created_at, updated_at, primary_group_id)
             SELECT ('00000000-0000-7000-8000-' || lpad(n::text, 12, '0'))::uuid, :workspace, 'Bulk ' || n, 'EUR', 'CURRENT', 'TRANSACTIONS', 'IMMEDIATE', true, false, '2026-01-10', '2026-02-01', 1, now(), now(), :group_id
             FROM generate_series(100000, 100997) AS n",
            ['workspace' => WorkspaceFixture::OWN_WORKSPACE, 'group_id' => self::BOURSE],
        );

        $this->client->request('GET', self::ASK);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $problem = $this->decode();
        self::assertSame('/problems/composition-too-large', $problem['type']);
    }

    public function testAThousandAccountsAreStillRead(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO account_financial_accounts (id, workspace_id, label, asset_code, kind, valuation_mode, liquidity_level, include_in_net_worth, include_in_emergency_fund, opened_on, closed_on, version, created_at, updated_at, primary_group_id)
             SELECT ('00000000-0000-7000-8000-' || lpad(n::text, 12, '0'))::uuid, :workspace, 'Bulk ' || n, 'EUR', 'CURRENT', 'TRANSACTIONS', 'IMMEDIATE', true, false, '2026-01-10', '2026-02-01', 1, now(), now(), :group_id
             FROM generate_series(100000, 100996) AS n",
            ['workspace' => WorkspaceFixture::OWN_WORKSPACE, 'group_id' => self::BOURSE],
        );

        $this->client->request('GET', self::ASK);

        self::assertResponseStatusCodeSame(200);
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
