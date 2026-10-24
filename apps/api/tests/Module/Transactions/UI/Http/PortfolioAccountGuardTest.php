<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** A portfolio-mode account is valued by its operations: income, expense, refund and fee bookings would count the same money twice. */
final class PortfolioAccountGuardTest extends WebTestCase
{
    private const string PORTFOLIO = PortfolioFixture::ACCOUNT;
    private const string CURRENT = '00000000-0000-7000-8000-0000000000d3';
    private const string SNAPSHOTS = '00000000-0000-7000-8000-0000000000d4';
    private const string EXPENSE_CATEGORY = '00000000-0000-7000-8000-0000000000c1';
    private const string INCOME_CATEGORY = '00000000-0000-7000-8000-0000000000c2';

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
        $portfolio = new PortfolioFixture($connection);
        $portfolio->account();
        $portfolio->account(self::CURRENT, label: 'Compte courant', kind: 'CURRENT', valuationMode: 'TRANSACTIONS');
        $portfolio->account(self::SNAPSHOTS, label: 'Titres en snapshots', valuationMode: 'SNAPSHOTS');
        foreach ([[self::EXPENSE_CATEGORY, 'EXPENSE', 'Courses'], [self::INCOME_CATEGORY, 'INCOME', 'Salaire']] as [$id, $type, $label]) {
            $this->connection->insert('category_categories', [
                'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'type' => $type, 'label' => $label, 'parent_id' => null,
                'icon' => null, 'color' => null, 'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1,
                'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
            ], ['budget_included' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
        }

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

    /** @return iterable<string, array{string, string, string}> */
    public static function refusedNatures(): iterable
    {
        yield 'income' => ['INCOME', '25.00', self::INCOME_CATEGORY];
        yield 'expense' => ['EXPENSE', '-25.00', self::EXPENSE_CATEGORY];
        yield 'fee' => ['FEE', '-2.00', self::EXPENSE_CATEGORY];
    }

    #[DataProvider('refusedNatures')]
    public function testEachRefusedNatureIsRefusedOnAPortfolioModeAccountButAcceptedOnATransactionsOne(string $nature, string $amount, string $category): void
    {
        $this->send('POST', '/api/v1/transactions', $this->transaction(self::PORTFOLIO, $nature, $amount, $category));
        $this->assertRefused();
        self::assertSame(0, $this->transactions(self::PORTFOLIO));

        foreach ([self::CURRENT, self::SNAPSHOTS] as $account) {
            $this->send('POST', '/api/v1/transactions', $this->transaction($account, $nature, $amount, $category));
            self::assertResponseStatusCodeSame(201, $account);
        }
    }

    public function testAnAdjustmentStaysAllowedOnAPortfolioModeAccount(): void
    {
        $this->send('POST', '/api/v1/transactions', $this->transaction(self::PORTFOLIO, 'ADJUSTMENT', '10.00', null));
        self::assertResponseStatusCodeSame(201);
    }

    public function testEditingDuplicatingAndRefundingOnAPortfolioModeAccountAreRefused(): void
    {
        $this->send('POST', '/api/v1/transactions', $this->transaction(self::SNAPSHOTS, 'EXPENSE', '-25.00', self::EXPENSE_CATEGORY));
        self::assertResponseStatusCodeSame(201);
        $legacy = $this->decode();
        $id = self::string($legacy['id']);
        // The account is switched to portfolio mode afterwards, as an old ledger would be.
        $this->connection->executeStatement("UPDATE account_financial_accounts SET valuation_mode = 'PORTFOLIO' WHERE id = ?", [self::SNAPSHOTS]);

        $body = $this->transaction(self::SNAPSHOTS, 'EXPENSE', '-26.00', self::EXPENSE_CATEGORY);
        $this->send('PUT', '/api/v1/transactions/'.$id, [...$body, 'userLabel' => null, 'version' => $legacy['version']]);
        $this->assertRefused();

        $this->send('POST', '/api/v1/transactions/'.$id.'/duplicate', new \stdClass());
        $this->assertRefused();

        $this->send('POST', '/api/v1/transactions/'.$id.'/refunds', [
            'accountId' => self::SNAPSHOTS, 'amount' => ['value' => '5.00', 'assetCode' => 'EUR'],
            'bookedOn' => '2026-03-15', 'rawLabel' => 'Remboursement', 'counterparty' => null, 'note' => null,
        ]);
        $this->assertRefused();
        self::assertSame(1, $this->transactions(self::SNAPSHOTS), 'the legacy row is kept untouched');
    }

    public function testATransferStaysAllowedButItsFeeLegOnAPortfolioSourceIsRefused(): void
    {
        $this->send('POST', '/api/v1/transfers', $this->transfer(self::CURRENT, self::PORTFOLIO, null));
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', '/api/v1/transfers', $this->transfer(self::PORTFOLIO, self::CURRENT, null));
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', '/api/v1/transfers', $this->transfer(self::CURRENT, self::PORTFOLIO, ['value' => '-1.50', 'assetCode' => 'EUR']));
        self::assertResponseStatusCodeSame(201, 'a fee leg on a non-portfolio source is fine');
        $before = $this->transactions(self::PORTFOLIO);

        $this->send('POST', '/api/v1/transfers', $this->transfer(self::PORTFOLIO, self::CURRENT, ['value' => '-1.50', 'assetCode' => 'EUR']));
        $this->assertRefused();
        self::assertSame($before, $this->transactions(self::PORTFOLIO));

        $created = $this->transfer(self::PORTFOLIO, self::CURRENT, null);
        $this->send('POST', '/api/v1/transfers', $created);
        self::assertResponseStatusCodeSame(201);
        $transfer = $this->decode();
        $this->send('PUT', '/api/v1/transfers/'.self::string($transfer['id']), [
            'sourceAccountId' => self::PORTFOLIO, 'targetAccountId' => self::CURRENT, 'state' => 'BOOKED', 'bookedOn' => '2026-03-14',
            'valueOn' => null, 'label' => 'Virement', 'note' => null, 'fee' => ['value' => '-1.50', 'assetCode' => 'EUR'], 'version' => $transfer['version'],
        ]);
        $this->assertRefused();
    }

    private function assertRefused(): void
    {
        self::assertResponseStatusCodeSame(422, (string) $this->client->getResponse()->getContent());
        $problem = $this->decode();
        self::assertSame('portfolio_account_requires_operations', $problem['code']);
        self::assertSame('/problems/portfolio-account-requires-operations', $problem['type']);
    }

    /** @return array<string, mixed> */
    private function transaction(string $account, string $nature, string $amount, ?string $category): array
    {
        return [
            'accountId' => $account, 'amount' => ['value' => $amount, 'assetCode' => 'EUR'], 'nature' => $nature, 'state' => 'BOOKED',
            'bookedOn' => '2026-03-14', 'valueOn' => null, 'authorizedOn' => null, 'rawLabel' => 'Libellé', 'counterparty' => null,
            'note' => null, 'paymentMethod' => null, 'mcc' => null, 'maskedCard' => null, 'bankReference' => null,
            'categoryId' => 'ADJUSTMENT' === $nature ? null : $category, 'splits' => null,
        ];
    }

    /**
     * @param array{value: string, assetCode: string}|null $fee
     *
     * @return array<string, mixed>
     */
    private function transfer(string $source, string $target, ?array $fee): array
    {
        return [
            'sourceAccountId' => $source, 'targetAccountId' => $target, 'sourceAmount' => ['value' => '500.00', 'assetCode' => 'EUR'],
            'targetAmount' => ['value' => '500.00', 'assetCode' => 'EUR'], 'state' => 'BOOKED', 'bookedOn' => '2026-03-14',
            'valueOn' => null, 'label' => 'Virement', 'note' => null, 'fee' => $fee,
        ];
    }

    private function transactions(string $account): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM transaction_transactions WHERE account_id = ?', [$account]);
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    /** @param array<string, mixed>|\stdClass $body */
    private function send(string $method, string $uri, array|\stdClass $body): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
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

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
