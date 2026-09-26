<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AnnualReportPreferencesControllerTest extends WebTestCase
{
    private const string URI = '/api/v1/reports/annual/preferences';
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000a1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000a9';
    private const string OWN_CATEGORY = '00000000-0000-7000-8000-0000000000c1';
    private const string OTHER_CATEGORY = '00000000-0000-7000-8000-0000000000c9';
    private const array DEFAULT_FIXED = ['cashIncome', 'budgetExpenses', 'budgetSurplus', 'cashSavingsRate', 'netSavingsTransfers', 'netSavingsRate', 'endNetWorth'];

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;

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
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testAWorkspaceWithoutARowReadsTheDefaultSelectionWithItsThreeCurrentAccountsByLabel(): void
    {
        $this->signIn();
        $ids = [];
        foreach ([1 => 'Delta', 2 => 'Alpha', 3 => 'Charlie', 4 => 'Bravo'] as $n => $label) {
            $ids[$label] = sprintf('00000000-0000-7000-8000-%012d', $n);
            $this->account($ids[$label], WorkspaceFixture::OWN_WORKSPACE, $label, 'CURRENT');
        }
        $this->account('00000000-0000-7000-8000-0000000000f1', WorkspaceFixture::OWN_WORKSPACE, 'Aaa Savings', 'SAVINGS');
        $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Aaa Foreign', 'CURRENT');

        $preferences = $this->read();

        self::assertSame(0, $preferences['version']);
        self::assertSame('exclude', $preferences['incompleteMonths']);
        self::assertSame([
            ...self::DEFAULT_FIXED,
            'account:'.$ids['Alpha'],
            'account:'.$ids['Bravo'],
            'account:'.$ids['Charlie'],
        ], $preferences['columns']);
    }

    public function testASavePersistsPerWorkspaceAndIncrementsTheVersion(): void
    {
        $this->signIn();
        $this->account(self::OWN_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->category(self::OWN_CATEGORY, WorkspaceFixture::OWN_WORKSPACE);

        $saved = $this->save(['cashIncome', 'axis:FIXED', 'category:'.self::OWN_CATEGORY, 'account:'.self::OWN_ACCOUNT], 'include', 0);

        self::assertSame(1, $saved['version']);
        self::assertSame('include', $saved['incompleteMonths']);
        self::assertSame($saved, $this->read());

        $again = $this->save(['cashIncome'], 'exclude', 1);
        self::assertSame(2, $again['version']);

        $this->client->request('DELETE', '/api/v1/session');
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $stranger = $this->read();
        self::assertSame(0, $stranger['version']);
        self::assertSame(self::DEFAULT_FIXED, $stranger['columns']);
    }

    public function testAStaleVersionIsAConflictAndLeavesTheStoredRowUntouched(): void
    {
        $this->signIn();
        $this->save(['cashIncome'], 'exclude', 0);

        $this->put(['budgetExpenses'], 'exclude', 0);
        self::assertResponseStatusCodeSame(409);
        $this->put(['budgetExpenses'], 'exclude', 7);
        self::assertResponseStatusCodeSame(409);

        self::assertSame(['cashIncome'], $this->read()['columns']);
    }

    public function testEveryInvalidSelectionIsRefusedWithoutStoringAnything(): void
    {
        $this->signIn();
        $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Secret', 'CURRENT');
        $this->category(self::OTHER_CATEGORY, WorkspaceFixture::OTHER_WORKSPACE);
        $tooMany = array_map(static fn (int $n): string => 'axis:FIXED'.$n, range(1, 41));

        foreach ([
            ['notAColumn'],
            ['axis:UNKNOWN'],
            ['cashIncome', 'cashIncome'],
            ['account:'.self::OTHER_ACCOUNT],
            ['category:'.self::OTHER_CATEGORY],
            ['group:00000000-0000-7000-8000-0000000000e9'],
            ['category:'],
            $tooMany,
        ] as $columns) {
            $this->put($columns, 'exclude', 0);
            self::assertResponseStatusCodeSame(422);
            self::assertResponseHeaderSame('content-type', 'application/problem+json');
        }
        $this->put(['cashIncome'], 'sometimes', 0);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('PUT', self::URI, server: ['CONTENT_TYPE' => 'application/json'], content: '{"columns":["cashIncome"],"incompleteMonths":"exclude","version":0,"extra":1}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM reporting_annual_report_preferences'));
        self::assertSame(0, $this->read()['version']);
    }

    public function testExactlyFortyColumnsAreAccepted(): void
    {
        $this->signIn();
        $columns = ['cashIncome', 'nonCashBenefits', 'budgetExpenses', 'benefitSpending', 'uncategorizedExpenses', 'budgetSurplus', 'cashSavingsRate', 'savingsInflows', 'savingsWithdrawals', 'netSavingsTransfers', 'netSavingsRate', 'netWorthDelta', 'endNetWorth', 'axis:DISCRETIONARY', 'axis:ESSENTIAL', 'axis:FIXED', 'axis:PERSONAL', 'axis:PROFESSIONAL', 'axis:VARIABLE'];
        for ($n = 1; $n <= 21; ++$n) {
            $id = sprintf('00000000-0000-7000-8000-%012d', $n);
            $this->category($id, WorkspaceFixture::OWN_WORKSPACE);
            $columns[] = 'category:'.$id;
        }

        $saved = $this->save($columns, 'exclude', 0);

        self::assertIsArray($saved['columns']);
        self::assertCount(40, $saved['columns']);
    }

    public function testASaveWithoutAValidCsrfTokenIsRefused(): void
    {
        $this->signIn();
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'forged');

        $this->put(['cashIncome'], 'exclude', 0);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM reporting_annual_report_preferences'));
    }

    public function testAnAnonymousCallerReadsAndWritesNothing(): void
    {
        $this->client->request('GET', self::URI);
        self::assertResponseStatusCodeSame(401);
        $this->put(['cashIncome'], 'exclude', 0);
        self::assertResponseStatusCodeSame(401);
    }

    private function signIn(string $email = WorkspaceFixture::OWNER_EMAIL): void
    {
        $this->client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $this->client->request('GET', self::URI);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>
     */
    private function save(array $columns, string $incomplete, int $version): array
    {
        $this->put($columns, $incomplete, $version);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    /** @param list<string> $columns */
    private function put(array $columns, string $incomplete, int $version): void
    {
        $this->client->request('PUT', self::URI, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'columns' => $columns,
            'incompleteMonths' => $incomplete,
            'version' => $version,
        ], JSON_THROW_ON_ERROR));
    }

    private function account(string $id, string $workspace, string $label, string $kind): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => $label, 'asset_code' => 'EUR', 'kind' => $kind,
            'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true,
            'include_in_emergency_fund' => false, 'opened_on' => '2020-01-01', 'version' => 1,
            'created_at' => '2020-01-01 12:00:00+00', 'updated_at' => '2020-01-01 12:00:00+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function category(string $id, string $workspace): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id, 'workspace_id' => $workspace, 'type' => 'EXPENSE', 'label' => 'Budget '.substr($id, -2),
            'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1,
            'version' => 1, 'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ], ['budget_included' => ParameterType::BOOLEAN]);
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $fields = [];
        foreach ($data as $key => $value) {
            self::assertIsString($key);
            $fields[$key] = $value;
        }

        return $fields;
    }
}
