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
 * Eligible net worth is 1 000.00 EUR on 2026-09-05: A 600.00 in Épargne
 * (child of Liquidités), B 300.00 in Liquidités, C 100.00 ungrouped and a
 * closed D of 50.00 that stays outside it. Liquidités rolls up 900.00.
 */
final class AccountOverviewControllerTest extends WebTestCase
{
    private const string PARENT = '00000000-0000-7000-8000-0000000000c1';
    private const string CHILD = '00000000-0000-7000-8000-0000000000c2';
    private const string FOREIGN_GROUP = '00000000-0000-7000-8000-0000000000c9';
    private const string A = '00000000-0000-7000-8000-0000000000d1';
    private const string B = '00000000-0000-7000-8000-0000000000d2';
    private const string C = '00000000-0000-7000-8000-0000000000d3';
    private const string D = '00000000-0000-7000-8000-0000000000d4';
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000d9';

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
        $this->insertGroup(self::PARENT, 'Liquidités', null, 1, WorkspaceFixture::OWN_WORKSPACE);
        $this->insertGroup(self::CHILD, 'Épargne', self::PARENT, 2, WorkspaceFixture::OWN_WORKSPACE);
        $this->insertGroup(self::FOREIGN_GROUP, 'Voisin', null, 1, WorkspaceFixture::OTHER_WORKSPACE);
        $this->insertAccount(self::A, 'Alpha', self::CHILD);
        $this->insertAccount(self::B, 'Beta', self::PARENT);
        $this->insertAccount(self::C, 'Gamma', null);
        $this->insertAccount(self::D, 'Delta', self::PARENT, closedOn: '2026-09-01');
        $this->insertAccount(self::FOREIGN, 'Voisin', self::FOREIGN_GROUP, workspace: WorkspaceFixture::OTHER_WORKSPACE);

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
        $this->snapshot(self::A, '2026-09-05', '600.00');
        $this->snapshot(self::B, '2026-09-05', '300.00');
        $this->snapshot(self::C, '2026-09-05', '100.00');
        $this->snapshot(self::D, '2026-08-31', '50.00');
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testTheOverviewIsNotShadowedByTheAccountDetailRoute(): void
    {
        $this->client->request('GET', '/api/v1/accounts/overview?asOf=2026-09-05');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testHeadingsCarryExactTotalsSharesAndTheRollupMarker(): void
    {
        $overview = $this->get('/api/v1/accounts/overview?asOf=2026-09-05');

        self::assertSame(['asOf', 'accountCount', 'groups'], array_keys($overview));
        self::assertSame(4, $overview['accountCount']);
        $groups = $this->list($overview['groups']);
        self::assertSame([self::PARENT, self::CHILD, null], array_column($groups, 'groupId'));
        self::assertSame(['900.00', '600.00', '100.00'], array_map(fn (array $g): mixed => $this->map($g['total'])['value'], $groups));
        self::assertSame(['90.00', '60.00', '10.00'], array_map(fn (array $g): mixed => $this->map($g['share'])['percentDisplay'], $groups));
        self::assertSame([true, false, false], array_column($groups, 'isRollup'));
        self::assertSame([1, 1, 1], array_column($groups, 'memberCount'));
        self::assertNull($groups[2]['label']);
    }

    public function testClosedAccountsAppearOnDemandWithoutMovingTotalsOrShares(): void
    {
        $hidden = $this->list($this->get('/api/v1/accounts/overview?asOf=2026-09-05')['groups']);
        $shown = $this->list($this->get('/api/v1/accounts/overview?asOf=2026-09-05&includeClosed=true')['groups']);

        self::assertSame(array_column($hidden, 'total'), array_column($shown, 'total'));
        self::assertSame(array_column($hidden, 'share'), array_column($shown, 'share'));
        self::assertSame([2, 1, 1], array_column($shown, 'memberCount'));

        $rows = $this->list($this->get('/api/v1/accounts/overview/'.self::PARENT.'/accounts?asOf=2026-09-05&includeClosed=true')['items']);
        self::assertSame([self::B, self::D], array_column($rows, 'id'));
        self::assertSame('30.00', $this->map($rows[0]['share'])['percentDisplay']);
        self::assertSame('CLOSED', $rows[1]['status']);
        self::assertNull($this->map($rows[1]['share'])['percent']);
    }

    public function testTheUngroupedSectionHasItsOwnRowsEndpoint(): void
    {
        $page = $this->get('/api/v1/accounts/overview/ungrouped/accounts?asOf=2026-09-05');

        self::assertSame([self::C], array_column($this->list($page['items']), 'id'));
        self::assertSame(0, $page['orderVersion']);
        self::assertNull($page['nextCursor']);
    }

    public function testMoreThanFiftyMembersArePagedWithAGroupBoundCursor(): void
    {
        for ($index = 1; $index <= 55; ++$index) {
            $this->insertAccount(sprintf('00000000-0000-7000-8000-00000001%04d', $index), sprintf('Compte %02d', $index), self::CHILD);
        }

        $first = $this->get('/api/v1/accounts/overview/'.self::CHILD.'/accounts?asOf=2026-09-05');
        self::assertCount(50, $this->list($first['items']));
        self::assertIsString($first['nextCursor']);
        $second = $this->get('/api/v1/accounts/overview/'.self::CHILD.'/accounts?asOf=2026-09-05&cursor='.rawurlencode($first['nextCursor']));
        self::assertCount(6, $this->list($second['items']));
        self::assertNull($second['nextCursor']);

        $this->client->request('GET', '/api/v1/accounts/overview/'.self::PARENT.'/accounts?cursor='.rawurlencode($first['nextCursor']));
        self::assertResponseStatusCodeSame(400);
    }

    public function testQueriesAreValidated(): void
    {
        foreach ([
            '/api/v1/accounts/overview?asOf=2026-02-31',
            '/api/v1/accounts/overview?includeClosed=yes',
            '/api/v1/accounts/overview/'.self::PARENT.'/accounts?cursor=forged.cursor',
        ] as $uri) {
            $this->client->request('GET', $uri);
            self::assertResponseStatusCodeSame(400, $uri);
        }
        foreach ([
            '/api/v1/accounts/overview/'.self::FOREIGN_GROUP.'/accounts',
            '/api/v1/accounts/overview/not-a-uuid/accounts',
        ] as $uri) {
            $this->client->request('GET', $uri);
            self::assertResponseStatusCodeSame(404, $uri);
        }
    }

    public function testMovingPersistsAcrossReloadAndAdvancesTheVersion(): void
    {
        $this->insertAccount('00000000-0000-7000-8000-0000000000d5', 'Epsilon', self::PARENT);

        $this->move(self::B, self::PARENT, '00000000-0000-7000-8000-0000000000d5', 0);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['groupId' => self::PARENT, 'orderVersion' => 1, 'rank' => 2], $this->decode());

        // Alphabetical default was B, D, Epsilon; B now sits just before Epsilon.
        $page = $this->get('/api/v1/accounts/overview/'.self::PARENT.'/accounts?asOf=2026-09-05&includeClosed=true');
        self::assertSame([self::D, self::B, '00000000-0000-7000-8000-0000000000d5'], array_column($this->list($page['items']), 'id'));
        self::assertSame(1, $page['orderVersion']);
        $overview = $this->list($this->get('/api/v1/accounts/overview?asOf=2026-09-05')['groups']);
        self::assertSame(1, $overview[0]['orderVersion']);
        self::assertSame(0, $overview[1]['orderVersion']);
    }

    public function testRegroupingAMemberMakesAnOlderCursorStale(): void
    {
        for ($index = 1; $index <= 60; ++$index) {
            $this->insertAccount(sprintf('00000000-0000-7000-8000-00000001%04d', $index), sprintf('Compte %02d', $index), self::CHILD);
        }
        $first = $this->get('/api/v1/accounts/overview/'.self::CHILD.'/accounts?asOf=2026-09-05');
        $items = $this->list($first['items']);
        self::assertIsString($first['nextCursor']);
        $movedId = $this->text($items[9]['id']);
        $account = $this->get('/api/v1/accounts/'.$movedId);
        $account['primaryGroupId'] = self::PARENT;
        $this->client->request('PUT', '/api/v1/accounts/'.$movedId, server: self::jsonHeaders(), content: json_encode(array_intersect_key($account, array_flip([
            'label', 'kind', 'productCode', 'productModelId', 'institution', 'maskedIdentifier', 'valuationMode', 'liquidityLevel',
            'includeInNetWorth', 'includeInEmergencyFund', 'openedOn', 'closedOn', 'version', 'primaryGroupId', 'tagGroupIds',
        ])), JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/v1/accounts/overview/'.self::CHILD.'/accounts?asOf=2026-09-05&cursor='.rawurlencode($first['nextCursor']));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/stale-account-order', $this->decode()['type'] ?? null);
        $fresh = $this->list($this->get('/api/v1/accounts/overview/'.self::CHILD.'/accounts?asOf=2026-09-05')['items']);
        self::assertNotContains($items[9]['id'], array_column($fresh, 'id'));
        $overview = $this->list($this->get('/api/v1/accounts/overview?asOf=2026-09-05')['groups']);
        self::assertSame([1, 1], array_column(array_slice($overview, 0, 2), 'orderVersion'));
    }

    public function testAStaleVersionAnswersAConflictAndKeepsTheSavedOrder(): void
    {
        $this->move(self::D, self::PARENT, self::B, 0);
        self::assertResponseStatusCodeSame(200);

        $this->move(self::B, self::PARENT, null, 0);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/stale-account-order', $this->decode()['type'] ?? null);
        $page = $this->get('/api/v1/accounts/overview/'.self::PARENT.'/accounts?asOf=2026-09-05&includeClosed=true');
        self::assertSame([self::D, self::B], array_column($this->list($page['items']), 'id'));
    }

    public function testForeignWrongGroupAndMalformedMovesAreRefusedWithoutLeak(): void
    {
        $this->move(self::FOREIGN, self::FOREIGN_GROUP, null, 0);
        self::assertResponseStatusCodeSame(404);
        $this->move(self::B, self::CHILD, null, 0);
        self::assertResponseStatusCodeSame(422);
        $this->move(self::B, self::PARENT, self::A, 0);
        self::assertResponseStatusCodeSame(422);
        $this->move(self::B, self::PARENT, self::B, 0);
        self::assertResponseStatusCodeSame(422);
        $this->move(self::B, self::PARENT, self::FOREIGN, 0);
        self::assertResponseStatusCodeSame(422);
        $this->move(self::C, self::PARENT, null, 0);
        self::assertResponseStatusCodeSame(422);
        $this->move(self::B, 'not-a-uuid', null, 0);
        self::assertResponseStatusCodeSame(422);
        $this->post('/api/v1/accounts/'.self::B.'/move', ['groupId' => self::PARENT, 'beforeAccountId' => null, 'orderVersion' => '0']);
        self::assertResponseStatusCodeSame(422);
        $this->post('/api/v1/accounts/'.self::B.'/move', ['groupId' => self::PARENT, 'beforeAccountId' => null, 'orderVersion' => -1]);
        self::assertResponseStatusCodeSame(422);
        $this->post('/api/v1/accounts/'.self::B.'/move', ['groupId' => self::PARENT, 'beforeAccountId' => null, 'orderVersion' => 0, 'accountIds' => [self::A]]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/api/v1/accounts/'.self::B.'/move', server: self::jsonHeaders(), content: str_repeat('x', 20000));
        self::assertResponseStatusCodeSame(413);
        $this->client->request('POST', '/api/v1/accounts/'.self::B.'/move', server: self::jsonHeaders(), content: '{not json');
        self::assertResponseStatusCodeSame(400);
        $this->client->request('POST', '/api/v1/accounts/not-a-uuid/move', server: self::jsonHeaders(), content: '{}');
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, $this->countRows('SELECT count(*) FROM account_overview_orders WHERE version > 0'));
        self::assertSame(0, $this->countRows("SELECT count(*) FROM audit_events WHERE event_type = 'account.moved'"));
    }

    public function testAMoveIsAuditedWithoutLabelOrAmount(): void
    {
        $this->move(self::D, self::PARENT, self::B, 0);

        $event = $this->connection->fetchAssociative("SELECT entity_id, before_json::text AS before_state, after_json::text AS after_state FROM audit_events WHERE event_type = 'account.moved'");
        self::assertIsArray($event);
        self::assertSame(self::D, $event['entity_id']);
        self::assertStringNotContainsString('Delta', json_encode($event, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('50', $this->text($event['after_state']));
    }

    public function testAMoveWithoutTheCsrfTokenIsRefused(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'forged');

        $this->move(self::D, self::PARENT, self::B, 0);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countRows('SELECT count(*) FROM account_overview_orders WHERE version > 0'));
    }

    public function testAnAnonymousVisitorIsRefused(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/accounts/overview');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/v1/accounts/overview/ungrouped/accounts');
        self::assertResponseStatusCodeSame(401);
    }

    private function move(string $id, string $groupId, ?string $before, int $version): void
    {
        $this->post('/api/v1/accounts/'.$id.'/move', ['groupId' => $groupId, 'beforeAccountId' => $before, 'orderVersion' => $version]);
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body): void
    {
        $this->client->request('POST', $uri, server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(200, $uri);

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

    private function countRows(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
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

    private function insertAccount(string $id, string $label, ?string $groupId, ?string $closedOn = null, string $workspace = WorkspaceFixture::OWN_WORKSPACE): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => $workspace,
            'label' => $label,
            'asset_code' => 'EUR',
            'kind' => 'CURRENT',
            'product_code' => null,
            'product_model_id' => null,
            'institution' => null,
            'masked_identifier' => null,
            'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => true,
            'include_in_emergency_fund' => false,
            'opened_on' => '2026-01-10',
            'closed_on' => $closedOn,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
            'primary_group_id' => $groupId,
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    private function snapshot(string $accountId, string $asOf, string $amount): void
    {
        $this->post('/api/v1/accounts/'.$accountId.'/balances', ['asOf' => $asOf, 'amount' => $amount, 'amountAssetCode' => 'EUR', 'comment' => null, 'version' => null]);
        self::assertResponseStatusCodeSame(201);
    }

    /** @return array<string, string> */
    private static function jsonHeaders(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }
}
