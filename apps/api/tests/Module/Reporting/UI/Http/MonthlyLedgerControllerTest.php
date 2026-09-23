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

final class MonthlyLedgerControllerTest extends WebTestCase
{
    private const string CURRENT = '00000000-0000-7000-8000-0000000000a1';
    private const string SAVINGS = '00000000-0000-7000-8000-0000000000a2';
    private const string EMPTY_ACCOUNT = '00000000-0000-7000-8000-0000000000a3';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000a9';
    private const string SALARY = '00000000-0000-7000-8000-0000000000c1';
    private const string FOOD = '00000000-0000-7000-8000-0000000000c2';
    private const string TRANSPORT = '00000000-0000-7000-8000-0000000000c3';
    private const string EMPTY = '00000000-0000-7000-8000-0000000000c4';
    private const string OTHER_CATEGORY = '00000000-0000-7000-8000-0000000000c9';

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
        $this->fixture = new WorkspaceFixture($connection);
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

    public function testLedgerAttributesSplitsRefundsAxesAndTransfersWithoutWorkspaceLeakage(): void
    {
        $this->signIn();
        $this->account(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->account(self::SAVINGS, WorkspaceFixture::OWN_WORKSPACE, 'Épargne', 'SAVINGS');
        $this->account(self::EMPTY_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Sans virement', 'CURRENT');
        $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Secret', 'CURRENT');
        $this->category(self::SALARY, WorkspaceFixture::OWN_WORKSPACE, 'INCOME', 'Salaire', true, 'briefcase', '#BB7733');
        $this->category(self::FOOD, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Alimentation', true, 'utensils', '#AA5500');
        $this->category(self::TRANSPORT, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Transport', false, 'train', '#336699');
        $this->category(self::EMPTY, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Vide', true, null, null);
        $this->category(self::OTHER_CATEGORY, WorkspaceFixture::OTHER_WORKSPACE, 'EXPENSE', 'Secret', true, null, null);

        $this->transaction('01', self::CURRENT, '5000', 'INCOME', splits: [[self::SALARY, '5000', []]]);
        $this->transaction('02', self::CURRENT, '-100', 'EXPENSE', splits: [
            [self::FOOD, '-60', ['ESSENTIAL']],
            [self::TRANSPORT, '-40', ['VARIABLE']],
        ]);
        $this->transaction('03', self::CURRENT, '20', 'REFUND', splits: [[self::FOOD, '20', ['ESSENTIAL']]]);
        $this->transaction('04', self::CURRENT, '-99', 'EXPENSE', state: 'PENDING', splits: [[self::FOOD, '-99', ['ESSENTIAL']]]);
        $this->transaction('09', self::OTHER_ACCOUNT, '-999999', 'EXPENSE', workspace: WorkspaceFixture::OTHER_WORKSPACE, splits: [[self::OTHER_CATEGORY, '-999999', ['ESSENTIAL']]]);
        $this->transfer('a', self::CURRENT, self::SAVINGS, '300');

        $ledger = $this->read('/api/v1/reports/monthly/ledger?month=2026-09&axis=ESSENTIAL');

        self::assertSame('PENDING', $ledger['state']);
        self::assertSame('CURRENT', $ledger['quality']);
        self::assertSame('Europe/Paris', $ledger['timezone']);
        self::assertSame(1, $ledger['pendingCount']);
        self::assertFalse($ledger['closed']);
        self::assertTrue($ledger['actionsAllowed']);
        self::assertNull($ledger['actionReason']);
        $salary = self::row($ledger, 'incomeCategories', self::SALARY);
        self::assertSame('5000.00', self::fields($salary['total'] ?? null)['value']);
        $food = self::row($ledger, 'expenseCategories', self::FOOD);
        self::assertSame('40.00', self::fields($food['total'] ?? null)['value']);
        self::assertSame(2, $food['movementCount']);
        self::assertTrue($food['hasMovements']);
        self::assertSame('#AA5500', $food['color']);
        $transport = self::row($ledger, 'expenseCategories', self::TRANSPORT);
        self::assertFalse($transport['budgetIncluded']);
        $transportTotal = self::fields($transport['total'] ?? null);
        self::assertNull($transportTotal['value']);
        self::assertSame('NO_MOVEMENTS', $transportTotal['reason']);
        self::assertFalse(self::row($ledger, 'expenseCategories', self::EMPTY)['hasMovements']);
        $current = self::row($ledger, 'accounts', self::CURRENT);
        $savings = self::row($ledger, 'accounts', self::SAVINGS);
        $emptyAccount = self::row($ledger, 'accounts', self::EMPTY_ACCOUNT);
        self::assertSame('-300.00', self::fields($current['total'] ?? null)['value']);
        self::assertSame('300.00', self::fields($savings['total'] ?? null)['value']);
        self::assertNull(self::fields($emptyAccount['total'] ?? null)['value']);
        self::assertFalse($emptyAccount['hasMovements']);
        self::assertStringNotContainsString('999999', (string) $this->client->getResponse()->getContent());

        $detail = $this->read('/api/v1/reports/monthly/ledger/expense/'.self::FOOD.'/movements?month=2026-09&axis=ESSENTIAL');
        $detailItems = self::rows($detail, 'items');
        self::assertSame(['20.00', '-60.00'], array_map(
            static fn (array $item): mixed => self::fields($item['amount'] ?? null)['value'],
            $detailItems,
        ));
        self::assertSame(['00000000-0000-7000-8000-000000000103', '00000000-0000-7000-8000-000000000102'], array_column($detailItems, 'transactionId'));

        $accountDetail = $this->read('/api/v1/reports/monthly/ledger/account/'.self::CURRENT.'/movements?month=2026-09');
        $accountItems = self::rows($accountDetail, 'items');
        $accountItem = $accountItems[0] ?? self::fail('Missing account movement.');
        self::assertSame('OUT', $accountItem['direction']);
        self::assertSame(self::SAVINGS, $accountItem['counterpartAccountId']);
        self::assertSame('Épargne', $accountItem['label']);
        self::assertSame('Épargne', $accountItem['counterpartAccountLabel']);
        self::assertSame('-300.00', self::fields($accountItem['amount'] ?? null)['value']);

        $foreign = $this->read('/api/v1/reports/monthly/ledger/expense/'.self::OTHER_CATEGORY.'/movements?month=2026-09');
        self::assertSame([], self::rows($foreign, 'items'));
        $foreignAccount = $this->read('/api/v1/reports/monthly/ledger/account/'.self::OTHER_ACCOUNT.'/movements?month=2026-09');
        self::assertSame([], self::rows($foreignAccount, 'items'));
    }

    public function testMovementPagingIsStableBoundedAndCursorBoundToItsQuery(): void
    {
        $this->signIn();
        $this->account(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->category(self::FOOD, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Alimentation', true, null, null);
        for ($index = 1; $index <= 51; ++$index) {
            $suffix = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $day = sprintf('2026-09-%02d', 1 + (($index - 1) % 28));
            $this->transaction($suffix, self::CURRENT, '-1', 'EXPENSE', bookedOn: $day, splits: [[self::FOOD, '-1', ['ESSENTIAL']]]);
        }

        $first = $this->read('/api/v1/reports/monthly/ledger/expense/'.self::FOOD.'/movements?month=2026-09&axis=ESSENTIAL');
        $firstItems = self::rows($first, 'items');
        self::assertCount(50, $firstItems);
        self::assertTrue($first['hasMore']);
        $cursor = $first['nextCursor'] ?? null;
        self::assertIsString($cursor);
        self::assertSame(50, $first['pageSize']);

        $second = $this->read('/api/v1/reports/monthly/ledger/expense/'.self::FOOD.'/movements?month=2026-09&axis=ESSENTIAL&cursor='.rawurlencode($cursor));
        $secondItems = self::rows($second, 'items');
        self::assertCount(1, $secondItems);
        self::assertFalse($second['hasMore']);
        self::assertNull($second['nextCursor']);
        self::assertSame([], array_intersect(self::ids($firstItems), self::ids($secondItems)));

        $this->client->request('GET', '/api/v1/reports/monthly/ledger/expense/'.self::FOOD.'/movements?month=2026-08&axis=ESSENTIAL&cursor='.rawurlencode($cursor));
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/v1/reports/monthly/ledger?month=2026-09&axis=NOPE');
        self::assertResponseStatusCodeSame(400);
    }

    public function testClosedPeriodDisablesActionsAndAnonymousCallersReadNothing(): void
    {
        $this->signIn();
        $this->account(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->connection->insert('account_period_closures', [
            'id' => '00000000-0000-7000-8000-0000000005c1',
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'year' => 2026,
            'month' => 9,
            'closed_at' => '2026-10-01 10:00:00+00',
            'closed_by' => WorkspaceFixture::OWNER_ID,
            'version' => 1,
        ]);

        $ledger = $this->read('/api/v1/reports/monthly/ledger?month=2026-09');
        self::assertTrue($ledger['closed']);
        self::assertFalse($ledger['actionsAllowed']);
        self::assertSame('PERIOD_CLOSED', $ledger['actionReason']);

        $this->client->request('DELETE', '/api/v1/session');
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/reports/monthly/ledger?month=2026-09');
        self::assertResponseStatusCodeSame(401);
    }

    private function signIn(): void
    {
        $this->client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /** @return array<string, mixed> */
    private function read(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();
        $value = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        return self::fields($value);
    }

    private function account(string $id, string $workspace, string $label, string $kind): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => $label, 'asset_code' => 'EUR', 'kind' => $kind,
            'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true,
            'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01', 'version' => 1,
            'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function category(string $id, string $workspace, string $type, string $label, bool $included, ?string $icon, ?string $color): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id, 'workspace_id' => $workspace, 'type' => $type, 'label' => $label,
            'icon' => $icon, 'color' => $color, 'default_analytic_axes' => '[]', 'budget_included' => $included,
            'sort_order' => 0, 'depth' => 1, 'version' => 1,
            'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ], ['budget_included' => ParameterType::BOOLEAN]);
    }

    /** @param list<array{string, string, list<string>}> $splits */
    private function transaction(
        string $suffix,
        string $account,
        string $amount,
        string $nature,
        string $state = 'BOOKED',
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
        string $bookedOn = '2026-09-15',
        array $splits = [],
    ): void {
        $id = '00000000-0000-7000-8000-0000000001'.$suffix;
        $this->connection->transactional(function () use ($id, $workspace, $account, $amount, $state, $nature, $bookedOn, $suffix, $splits): void {
            $this->connection->insert('transaction_transactions', [
                'id' => $id, 'workspace_id' => $workspace, 'account_id' => $account, 'asset_code' => 'EUR',
                'amount_value' => $amount, 'amount_scale' => 2, 'state' => $state, 'nature' => $nature,
                'source' => 'MANUAL', 'booked_on' => $bookedOn, 'raw_label' => 'Ledger '.$suffix,
                'version' => 1, 'created_at' => $bookedOn.' 12:00:00+00', 'updated_at' => $bookedOn.' 12:00:00+00',
            ]);
            foreach ($splits as $index => [$category, $splitAmount, $axes]) {
                $this->connection->insert('transaction_splits', [
                    'id' => sprintf('00000000-0000-7000-8000-%012d', ((int) $suffix * 10) + $index),
                    'workspace_id' => $workspace, 'transaction_id' => $id, 'category_id' => $category,
                    'amount_value' => $splitAmount, 'amount_scale' => 2, 'asset_code' => 'EUR',
                    'analytic_axes' => json_encode($axes, JSON_THROW_ON_ERROR), 'position' => $index,
                    'created_at' => $bookedOn.' 12:00:00+00',
                ]);
            }
        });
    }

    private function transfer(string $suffix, string $source, string $target, string $amount): void
    {
        $this->transaction($suffix.'1', $source, '-'.$amount, 'TRANSFER');
        $this->transaction($suffix.'2', $target, $amount, 'TRANSFER');
        $this->connection->insert('transaction_transfers', [
            'id' => '00000000-0000-7000-8000-0000000004'.$suffix.'3',
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'source_transaction_id' => '00000000-0000-7000-8000-0000000001'.$suffix.'1',
            'target_transaction_id' => '00000000-0000-7000-8000-0000000001'.$suffix.'2',
            'version' => 1, 'created_at' => '2026-09-15 12:00:00+00', 'updated_at' => '2026-09-15 12:00:00+00',
        ]);
    }

    /**
     * @param array<string, mixed> $ledger
     *
     * @return array<string, mixed>
     */
    private static function row(array $ledger, string $collection, string $id): array
    {
        foreach (self::rows($ledger, $collection) as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        self::fail(sprintf('Missing row %s in %s.', $id, $collection));
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(array $value, string $key): array
    {
        $rawRows = $value[$key] ?? null;
        self::assertIsArray($rawRows);
        $rows = [];
        foreach ($rawRows as $row) {
            $rows[] = self::fields($row);
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function ids(array $rows): array
    {
        return array_map(static function (array $row): string {
            $id = $row['id'] ?? null;
            self::assertIsString($id);

            return $id;
        }, $rows);
    }

    /** @return array<string, mixed> */
    private static function fields(mixed $value): array
    {
        self::assertIsArray($value);
        $fields = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $fields[$key] = $item;
        }

        return $fields;
    }
}
