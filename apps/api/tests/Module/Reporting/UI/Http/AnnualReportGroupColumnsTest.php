<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\ClosesPeriods;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Group columns follow the current exclusive tree for every month. Hand-computed: Actions holds
 * 1000.00 and an apartment 500.00, so Investissement is 1500.00 whichever sub-group the shares sit in.
 */
final class AnnualReportGroupColumnsTest extends WebTestCase
{
    use ClosesPeriods;

    private const string INVEST = '00000000-0000-7000-8000-0000000000b1';
    private const string BOURSE = '00000000-0000-7000-8000-0000000000b2';
    private const string IMMO = '00000000-0000-7000-8000-0000000000b3';
    private const string SHARES = '00000000-0000-7000-8000-0000000000a1';
    private const string APARTMENT = '00000000-0000-7000-8000-0000000000a2';
    private const string FOREIGN_GROUP = '00000000-0000-7000-8000-0000000000b9';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private int $year;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($this->connection);
        $this->fixture->reset();
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->year = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y') - 1;

        $this->group(self::INVEST, 'Investissement', null, 1, WorkspaceFixture::OWN_WORKSPACE);
        $this->group(self::BOURSE, 'Bourse', self::INVEST, 2, WorkspaceFixture::OWN_WORKSPACE);
        $this->group(self::IMMO, 'Immobilier', self::INVEST, 2, WorkspaceFixture::OWN_WORKSPACE);
        $this->group(self::FOREIGN_GROUP, 'Etranger', null, 1, WorkspaceFixture::OTHER_WORKSPACE);
        $this->account(self::SHARES, 'Actions', self::INVEST, '1000.00');
        $this->account(self::APARTMENT, 'Appartement', self::IMMO, '500.00');
        $this->signIn();
        $this->call('PUT', '/api/v1/reports/annual/preferences', [
            'columns' => ['group:'.self::INVEST, 'group:'.self::BOURSE, 'group:'.self::IMMO, 'endNetWorth'],
            'incompleteMonths' => 'exclude',
            'version' => 0,
        ]);
        self::assertResponseIsSuccessful();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testMovingAnAccountToASubGroupRegroupsOpenAndClosedMonthsAlike(): void
    {
        $this->closeMonthInDatabase($this->connection, $this->year, 3);
        $before = $this->rows();
        self::assertTrue($before[2]['closed']);
        self::assertFalse($before[5]['closed']);
        foreach ([2, 5] as $index) {
            self::assertSame('1500', $this->cell($before[$index], 'group:'.self::INVEST));
            self::assertSame('0', $this->cell($before[$index], 'group:'.self::BOURSE));
        }

        $this->connection->executeStatement('UPDATE account_financial_accounts SET primary_group_id = ? WHERE id = ?', [self::BOURSE, self::SHARES]);
        $after = $this->rows();

        foreach ([2, 5] as $index) {
            self::assertSame('1000', $this->cell($after[$index], 'group:'.self::BOURSE), 'month '.($index + 1));
            self::assertSame('500', $this->cell($after[$index], 'group:'.self::IMMO));
            // Investissement equals the sum of its direct members, Bourse and Immobilier.
            self::assertSame('1500', $this->cell($after[$index], 'group:'.self::INVEST));
            self::assertSame($this->cell($before[$index], 'endNetWorth'), $this->cell($after[$index], 'endNetWorth'));
        }
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM reporting_month_snapshots'));
    }

    public function testAGroupMovedUnderAnotherParentChangesEveryMonthToo(): void
    {
        $this->closeMonthInDatabase($this->connection, $this->year, 3);
        $this->rows();

        $this->connection->executeStatement('UPDATE account_groups SET parent_id = NULL, depth = 1 WHERE id = ?', [self::IMMO]);
        $after = $this->rows();

        foreach ([2, 5] as $index) {
            self::assertSame('1000', $this->cell($after[$index], 'group:'.self::INVEST));
            self::assertSame('500', $this->cell($after[$index], 'group:'.self::IMMO));
        }
    }

    public function testASnapshotWithoutAccountWeightsIsRecapturedOnce(): void
    {
        $this->closeMonthInDatabase($this->connection, $this->year, 3);
        $this->rows();
        $this->connection->executeStatement("UPDATE reporting_month_snapshots SET payload = payload - 'accountWeights'");

        $this->rows();

        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM reporting_month_snapshots'));
        self::assertTrue((bool) $this->connection->fetchOne("SELECT jsonb_exists(payload, 'accountWeights') FROM reporting_month_snapshots"));
        self::assertSame(2, $this->connection->fetchOne("SELECT jsonb_array_length(payload->'accountWeights') FROM reporting_month_snapshots"));
    }

    public function testAnotherWorkspaceGroupIsNotACatalogueGroup(): void
    {
        $this->call('PUT', '/api/v1/reports/annual/preferences', [
            'columns' => ['group:'.self::FOREIGN_GROUP],
            'incompleteMonths' => 'exclude',
            'version' => 1,
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheColumnHeaderIsTheFullPathOfTheGroup(): void
    {
        $this->client->request('GET', '/api/v1/reports/annual/'.$this->year);
        $report = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertIsArray($report['columns']);

        self::assertSame(
            ['Investissement', 'Investissement › Bourse', 'Investissement › Immobilier', 'Patrimoine net'],
            array_column($report['columns'], 'label'),
        );
    }

    /** @return list<array<mixed>> */
    private function rows(): array
    {
        $this->client->request('GET', '/api/v1/reports/annual/'.$this->year);
        self::assertResponseIsSuccessful();
        $report = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        $rows = $report['rows'] ?? null;
        self::assertIsArray($rows);

        return array_values(array_map(static function (mixed $row): array {
            self::assertIsArray($row);

            return $row;
        }, $rows));
    }

    /** @param array<mixed> $row */
    private function cell(array $row, string $column): string
    {
        $cells = $row['cells'];
        self::assertIsArray($cells);
        $cell = $cells[$column];
        self::assertIsArray($cell);
        $value = $cell['value'];
        self::assertIsString($value);

        // Compare numbers, not scales: 1500, 1500.00 and 1500.000000 are one figure.
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }

    private function group(string $id, string $label, ?string $parent, int $depth, string $workspace): void
    {
        $this->connection->insert('account_groups', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => $label, 'parent_id' => $parent, 'sort_order' => 0,
            'depth' => $depth, 'version' => 1, 'created_at' => '2000-01-01 12:00:00+00', 'updated_at' => '2000-01-01 12:00:00+00',
        ]);
    }

    private function account(string $id, string $label, string $group, string $amount): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => $label, 'asset_code' => 'EUR', 'kind' => 'SAVINGS',
            'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true,
            'include_in_emergency_fund' => false, 'opened_on' => '2000-01-01', 'version' => 1,
            'created_at' => '2000-01-01 12:00:00+00', 'updated_at' => '2000-01-01 12:00:00+00', 'primary_group_id' => $group,
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
        $this->connection->insert('account_balance_snapshots', [
            'id' => substr(sha1($id), 0, 8).'-0000-7000-8000-000000000000', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'account_id' => $id, 'as_of' => '2000-01-02', 'amount_value' => $amount, 'amount_literal' => $amount,
            'amount_asset' => 'EUR', 'source' => 'MANUAL', 'reconciliation_status' => 'UNRECONCILED', 'comment' => null,
            'version' => 1, 'recorded_at' => '2000-01-02 12:00:00+00', 'recorded_by' => WorkspaceFixture::OWNER_ID, 'superseded_at' => null,
        ]);
    }

    private function signIn(): void
    {
        $this->client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
    }
}
