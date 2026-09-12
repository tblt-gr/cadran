<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TransferControllerTest extends WebTestCase
{
    private const string SOURCE_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string TARGET_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string CHF_ACCOUNT = '00000000-0000-7000-8000-0000000000d3';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d4';
    private const string OTHER_TARGET_ACCOUNT = '00000000-0000-7000-8000-0000000000d8';
    private const string FOREIGN_TRANSFER = '00000000-0000-7000-8000-0000000000e9';
    private const string FOREIGN_SOURCE_LEG = '00000000-0000-7000-8000-0000000000e7';
    private const string FOREIGN_TARGET_LEG = '00000000-0000-7000-8000-0000000000e8';
    private const string ARCHIVED_ACCOUNT = '00000000-0000-7000-8000-0000000000d5';
    private const string CLOSED_ACCOUNT = '00000000-0000-7000-8000-0000000000d6';
    private const string FUTURE_OPENED_ACCOUNT = '00000000-0000-7000-8000-0000000000d7';
    private const string INCOME_CATEGORY = '00000000-0000-7000-8000-0000000000c1';
    private const string EXPENSE_CATEGORY = '00000000-0000-7000-8000-0000000000c2';

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
        $this->resetLoginThrottling();

        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        $this->seedAccount(self::SOURCE_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant', 'EUR');
        $this->seedAccount(self::TARGET_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Épargne', 'EUR');
        $this->seedAccount(self::CHF_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Compte suisse', 'CHF');
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Compte voisin', 'EUR');
        $this->seedAccount(self::ARCHIVED_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Archivé', 'EUR', archived: true);
        $this->seedAccount(self::CLOSED_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Clôturé', 'EUR', closedOn: '2026-02-01');
        $this->seedAccount(self::FUTURE_OPENED_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Futur', 'EUR', openedOn: '2026-06-01');
        $this->seedCategory(self::INCOME_CATEGORY, 'INCOME', 'Salaire');
        $this->seedCategory(self::EXPENSE_CATEGORY, 'EXPENSE', 'Courses');

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->signIn();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testCreatingReadingEditingAndVoidingASameAssetTransfer(): void
    {
        $created = $this->createTransfer();
        self::assertSame([
            'id', 'source', 'target', 'fee', 'exchangeRate', 'version', 'createdAt', 'updatedAt', 'voidedAt',
        ], array_keys($created));
        self::assertIsArray($created['source']);
        self::assertIsArray($created['target']);
        $source = self::arrayValue($created, 'source');
        $target = self::arrayValue($created, 'target');
        self::assertSame(['value' => '-500.00', 'assetCode' => 'EUR'], $source['amount']);
        self::assertSame(['value' => '500.00', 'assetCode' => 'EUR'], $target['amount']);
        self::assertSame('TRANSFER', $source['nature']);
        self::assertSame('TRANSFER', $target['nature']);
        self::assertNull($source['originalAmount']);
        self::assertNull($created['exchangeRate']);
        self::assertNull($created['fee']);
        self::assertSame(1, $created['version']);
        self::assertSame([], $source['splits']);
        self::assertSame($created['id'], $source['transferId']);
        self::assertSame($created['id'], $target['transferId']);

        $id = self::stringValue($created, 'id');
        $this->client->request('GET', '/api/v1/transfers/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame($id, $this->decode()['id']);

        $this->requestUpdate($id, [
            'sourceAccountId' => self::SOURCE_ACCOUNT,
            'targetAccountId' => self::TARGET_ACCOUNT,
            'state' => 'BOOKED',
            'bookedOn' => '2026-03-15',
            'valueOn' => null,
            'label' => 'Virement épargne corrigé',
            'note' => 'Corrigé',
            'fee' => null,
            'version' => 1,
        ]);
        self::assertResponseIsSuccessful();
        $edited = $this->decode();
        $editedSource = self::arrayValue($edited, 'source');
        self::assertSame('2026-03-15', $editedSource['bookedOn']);
        self::assertSame('Virement épargne corrigé', $editedSource['rawLabel']);
        self::assertSame('Corrigé', $editedSource['note']);
        self::assertSame(2, $edited['version']);
        // Amounts are immutable on edit, exactly like a plain transaction's asset.
        self::assertSame(['value' => '-500.00', 'assetCode' => 'EUR'], $editedSource['amount']);

        $this->requestVoid($id, 2);
        self::assertResponseIsSuccessful();
        $voided = $this->decode();
        self::assertNotNull($voided['voidedAt']);
        self::assertSame('VOIDED', self::arrayValue($voided, 'source')['state']);
        self::assertSame('VOIDED', self::arrayValue($voided, 'target')['state']);

        $this->requestVoid($id, 3);
        self::assertResponseStatusCodeSame(409);
    }

    public function testACrossAssetTransferDerivesAndStoresTheExchangeRateOnBothLegs(): void
    {
        $created = $this->createTransfer(overrides: [
            'targetAccountId' => self::CHF_ACCOUNT,
            'sourceAmount' => ['value' => '500.00', 'assetCode' => 'EUR'],
            'targetAmount' => ['value' => '540.25', 'assetCode' => 'CHF'],
        ]);

        $source = self::arrayValue($created, 'source');
        $target = self::arrayValue($created, 'target');
        // The response is re-read from NUMERIC(50,24) rather than presented
        // from memory, so the transfer's own rate and each leg's copy go
        // through the same trimming and never disagree on trailing zeros —
        // including against a later GET of the same transfer.
        self::assertSame('1.0805', $created['exchangeRate']);
        self::assertSame(['value' => '-500.00', 'assetCode' => 'EUR'], $source['amount']);
        self::assertSame(['value' => '540.25', 'assetCode' => 'CHF'], $target['amount']);
        self::assertSame(['value' => '540.25', 'assetCode' => 'CHF'], $source['originalAmount']);
        self::assertSame(['value' => '500.00', 'assetCode' => 'EUR'], $target['originalAmount']);
        self::assertSame('1.0805', $source['exchangeRate']);
        self::assertSame('1.0805', $target['exchangeRate']);

        $this->client->request('GET', '/api/v1/transfers/'.self::stringValue($created, 'id'));
        self::assertResponseIsSuccessful();
        self::assertSame('1.0805', $this->decode()['exchangeRate']);
    }

    public function testAFeeProducesItsOwnFeeTransactionOnTheSourceAccount(): void
    {
        $created = $this->createTransfer(overrides: [
            'fee' => ['value' => '-2.50', 'assetCode' => 'EUR'],
        ]);

        $fee = self::arrayValue($created, 'fee');
        self::assertSame(['value' => '-2.50', 'assetCode' => 'EUR'], $fee['amount']);
        self::assertSame('FEE', $fee['nature']);
        self::assertSame(self::SOURCE_ACCOUNT, $fee['accountId']);
        self::assertSame($created['id'], $fee['transferId']);
    }

    public function testVoidingATransferWithAFeeVoidsAllThreeLegsAndRecordsOneAuditEventPerLeg(): void
    {
        $created = $this->createTransfer(overrides: [
            'fee' => ['value' => '-2.50', 'assetCode' => 'EUR'],
        ]);
        $transferId = self::stringValue($created, 'id');

        $this->requestVoid($transferId, 1);
        self::assertResponseIsSuccessful();
        $voided = $this->decode();
        self::assertSame('VOIDED', self::arrayValue($voided, 'source')['state']);
        self::assertSame('VOIDED', self::arrayValue($voided, 'target')['state']);
        self::assertSame('VOIDED', self::arrayValue($voided, 'fee')['state']);
        self::assertNotNull($voided['voidedAt']);

        self::assertSame(3, $this->countQuery(
            "SELECT count(*) FROM audit_events WHERE event_type = 'transaction.voided'",
        ));
        self::assertSame(1, $this->countQuery(
            "SELECT count(*) FROM audit_events WHERE event_type = 'transfer.voided' AND entity_id = ?",
            [$transferId],
        ));
    }

    /** @return iterable<string, array{array<string, mixed>, int, ?string}> */
    public static function refusalCases(): iterable
    {
        yield 'same account' => [['targetAccountId' => self::SOURCE_ACCOUNT], 422, '/problems/transfer.same_account'];
        yield 'account of another workspace' => [['targetAccountId' => self::OTHER_ACCOUNT], 404, null];
        yield 'archived account' => [['targetAccountId' => self::ARCHIVED_ACCOUNT], 422, null];
        yield 'closed account' => [['targetAccountId' => self::CLOSED_ACCOUNT], 422, null];
        yield 'date outside open period' => [['targetAccountId' => self::FUTURE_OPENED_ACCOUNT, 'bookedOn' => '2026-03-01'], 422, null];
        yield 'same asset unequal magnitudes' => [
            ['targetAmount' => ['value' => '500.01', 'assetCode' => 'EUR']], 422, '/problems/transfer.amount_mismatch',
        ];
        yield 'cross asset missing target amount' => [
            ['targetAccountId' => self::CHF_ACCOUNT, 'targetAmount' => null], 422, null,
        ];
        yield 'zero source amount' => [['sourceAmount' => ['value' => '0.00', 'assetCode' => 'EUR']], 422, null];
        yield 'fee in another asset' => [['fee' => ['value' => '-2.50', 'assetCode' => 'CHF']], 422, null];
        yield 'positive fee' => [['fee' => ['value' => '2.50', 'assetCode' => 'EUR']], 422, null];
    }

    /** @param array<string, mixed> $overrides */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusalCases')]
    public function testARefusedTransferWritesNothing(array $overrides, int $status, ?string $type): void
    {
        $this->requestCreate($this->payload(overrides: $overrides));
        self::assertResponseStatusCodeSame($status);
        if (null !== $type) {
            self::assertSame($type, $this->decode()['type']);
        }
        self::assertSame(0, $this->ownTransactionCount());
        self::assertSame(0, $this->ownTransferCount());
    }

    public function testAnotherWorkspacesTransferIsInvisibleToReadEditAndVoid(): void
    {
        $this->seedForeignTransfer();

        $this->client->request('GET', '/api/v1/transfers/'.self::FOREIGN_TRANSFER);
        self::assertResponseStatusCodeSame(404);

        $this->requestUpdate(self::FOREIGN_TRANSFER, [
            'sourceAccountId' => self::OTHER_ACCOUNT,
            'targetAccountId' => self::OTHER_TARGET_ACCOUNT,
            'state' => 'BOOKED',
            'bookedOn' => '2026-03-14',
            'valueOn' => null,
            'label' => 'Hijacked',
            'note' => null,
            'fee' => null,
            'version' => 1,
        ]);
        self::assertResponseStatusCodeSame(404);

        $this->requestVoid(self::FOREIGN_TRANSFER, 1);
        self::assertResponseStatusCodeSame(404);
    }

    public function testEditingStillWorksAfterAnAccountIsArchivedUnlikeCreation(): void
    {
        $created = $this->createTransfer();
        $id = self::stringValue($created, 'id');
        $this->connection->update(
            'account_financial_accounts',
            ['archived_at' => '2026-03-15 00:00:00+00', 'version' => 2],
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => self::TARGET_ACCOUNT],
        );

        $this->requestUpdate($id, [
            'sourceAccountId' => self::SOURCE_ACCOUNT,
            'targetAccountId' => self::TARGET_ACCOUNT,
            'state' => 'BOOKED',
            'bookedOn' => '2026-03-14',
            'valueOn' => null,
            'label' => 'Virement épargne corrigé',
            'note' => null,
            'fee' => null,
            'version' => 1,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('Virement épargne corrigé', self::arrayValue($this->decode(), 'source')['rawLabel']);
    }

    public function testEditingWithAStaleVersionIsRejected(): void
    {
        $created = $this->createTransfer();
        $id = self::stringValue($created, 'id');

        $this->requestUpdate($id, [
            'sourceAccountId' => self::SOURCE_ACCOUNT,
            'targetAccountId' => self::TARGET_ACCOUNT,
            'state' => 'BOOKED',
            'bookedOn' => '2026-03-14',
            'valueOn' => null,
            'label' => 'Virement épargne',
            'note' => null,
            'fee' => null,
            'version' => 99,
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testEditingOrVoidingALegThroughTheTransactionEndpointsIsRefused(): void
    {
        $created = $this->createTransfer();
        $legId = self::stringValue(self::arrayValue($created, 'source'), 'id');
        $transferId = self::stringValue($created, 'id');

        $this->client->request(
            'PUT',
            '/api/v1/transactions/'.$legId,
            server: self::jsonHeaders(),
            content: json_encode([
                'accountId' => self::SOURCE_ACCOUNT,
                'amount' => ['value' => '-500.00', 'assetCode' => 'EUR'],
                'nature' => 'TRANSFER',
                'state' => 'BOOKED',
                'bookedOn' => '2026-03-14',
                'valueOn' => null,
                'authorizedOn' => null,
                'rawLabel' => 'Virement épargne',
                'counterparty' => null,
                'note' => null,
                'paymentMethod' => null,
                'mcc' => null,
                'maskedCard' => null,
                'bankReference' => null,
                'categoryId' => null,
                'splits' => null,
                'version' => 1,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
        $problem = $this->decode();
        self::assertSame('/problems/transaction-belongs-to-transfer', $problem['type']);
        self::assertSame($transferId, $problem['transferId']);

        $this->client->request(
            'POST',
            '/api/v1/transactions/'.$legId.'/void',
            server: self::jsonHeaders(),
            content: json_encode(['version' => 1], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/transaction-belongs-to-transfer', $this->decode()['type']);

        $this->client->request(
            'PUT',
            '/api/v1/transactions/'.$legId.'/splits',
            server: self::jsonHeaders(),
            content: json_encode(['version' => 1, 'splits' => []], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
        $splitsProblem = $this->decode();
        self::assertSame('/problems/transaction-belongs-to-transfer', $splitsProblem['type']);
        self::assertSame($transferId, $splitsProblem['transferId']);
    }

    public function testTransfersNeverAppearAsIncomeOrExpenseAndThePairNetsToExactlyZero(): void
    {
        $this->client->request('POST', '/api/v1/transactions', server: self::jsonHeaders(), content: json_encode([
            'accountId' => self::SOURCE_ACCOUNT, 'amount' => ['value' => '2000.00', 'assetCode' => 'EUR'],
            'nature' => 'INCOME', 'state' => 'BOOKED', 'bookedOn' => '2026-03-14', 'valueOn' => null,
            'authorizedOn' => null, 'rawLabel' => 'Salaire', 'counterparty' => null, 'note' => null,
            'paymentMethod' => null, 'mcc' => null, 'maskedCard' => null, 'bankReference' => null,
            'categoryId' => self::INCOME_CATEGORY, 'splits' => null,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/v1/transactions', server: self::jsonHeaders(), content: json_encode([
            'accountId' => self::SOURCE_ACCOUNT, 'amount' => ['value' => '-89.90', 'assetCode' => 'EUR'],
            'nature' => 'EXPENSE', 'state' => 'BOOKED', 'bookedOn' => '2026-03-14', 'valueOn' => null,
            'authorizedOn' => null, 'rawLabel' => 'Courses', 'counterparty' => null, 'note' => null,
            'paymentMethod' => null, 'mcc' => null, 'maskedCard' => null, 'bankReference' => null,
            'categoryId' => self::EXPENSE_CATEGORY, 'splits' => null,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $this->createTransfer();

        $sums = $this->connection->fetchAllKeyValue(
            'SELECT nature, SUM(amount_value) FROM transaction_transactions WHERE workspace_id = ? GROUP BY nature',
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        // PostgreSQL sums a NUMERIC(50,24) column at its full stored scale.
        self::assertSame('2000.000000000000000000000000', $sums['INCOME']);
        self::assertSame('-89.900000000000000000000000', $sums['EXPENSE']);
        self::assertSame('0.000000000000000000000000', $sums['TRANSFER'] ?? null);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createTransfer(array $overrides = []): array
    {
        $this->requestCreate($this->payload(overrides: $overrides));
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /** @param array<string, mixed> $body */
    private function requestCreate(array $body): void
    {
        $this->client->request('POST', '/api/v1/transfers', server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $body */
    private function requestUpdate(string $id, array $body): void
    {
        $this->client->request('PUT', '/api/v1/transfers/'.$id, server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function requestVoid(string $id, int $version): void
    {
        $this->client->request(
            'POST',
            '/api/v1/transfers/'.$id.'/void',
            server: self::jsonHeaders(),
            content: json_encode(['version' => $version], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'sourceAccountId' => self::SOURCE_ACCOUNT,
            'targetAccountId' => self::TARGET_ACCOUNT,
            'sourceAmount' => ['value' => '500.00', 'assetCode' => 'EUR'],
            'targetAmount' => ['value' => '500.00', 'assetCode' => 'EUR'],
            'state' => 'BOOKED',
            'bookedOn' => '2026-03-14',
            'valueOn' => null,
            'label' => 'Virement épargne',
            'note' => null,
            'fee' => null,
            ...$overrides,
        ];
    }

    private function seedAccount(
        string $id,
        string $workspace,
        string $label,
        string $assetCode,
        bool $archived = false,
        ?string $closedOn = null,
        string $openedOn = '2026-01-01',
    ): void {
        // The account's own recorded timestamp is its notion of "now": it
        // cannot be opened after it, so a fixture opened later than the
        // shared default recording time must record itself later too.
        $recordedAt = $openedOn > '2026-03-14' ? $openedOn.' 09:12:04+00' : '2026-03-14 09:12:04+00';
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => $workspace,
            'label' => $label,
            'asset_code' => $assetCode,
            'kind' => 'CURRENT',
            'masked_identifier' => null,
            'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => false,
            'include_in_emergency_fund' => false,
            'opened_on' => $openedOn,
            'closed_on' => $closedOn,
            'archived_at' => $archived ? '2026-03-01 00:00:00+00' : null,
            'version' => 1,
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    private function seedForeignTransfer(): void
    {
        $this->seedAccount(self::OTHER_TARGET_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Épargne voisine', 'EUR');
        foreach ([
            [self::FOREIGN_SOURCE_LEG, self::OTHER_ACCOUNT, '-500.00'],
            [self::FOREIGN_TARGET_LEG, self::OTHER_TARGET_ACCOUNT, '500.00'],
        ] as [$id, $accountId, $amount]) {
            $this->connection->insert('transaction_transactions', [
                'id' => $id,
                'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE,
                'account_id' => $accountId,
                'asset_code' => 'EUR',
                'amount_value' => $amount,
                'amount_scale' => 2,
                'state' => 'BOOKED',
                'nature' => 'TRANSFER',
                'source' => 'MANUAL',
                'booked_on' => '2026-03-14',
                'raw_label' => 'Secret neighbour transfer',
                'version' => 1,
                'created_at' => '2026-03-14 09:12:04+00',
                'updated_at' => '2026-03-14 09:12:04+00',
            ]);
        }
        $this->connection->insert('transaction_transfers', [
            'id' => self::FOREIGN_TRANSFER,
            'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE,
            'source_transaction_id' => self::FOREIGN_SOURCE_LEG,
            'target_transaction_id' => self::FOREIGN_TARGET_LEG,
            'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
        ]);
    }

    private function seedCategory(string $id, string $type, string $label): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id,
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'type' => $type,
            'label' => $label,
            'parent_id' => null,
            'icon' => null,
            'color' => null,
            'default_analytic_axes' => '[]',
            'budget_included' => true,
            'sort_order' => 0,
            'depth' => 1,
            'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
        ]);
    }

    private function ownTransactionCount(): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM transaction_transactions WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    private function ownTransferCount(): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM transaction_transfers WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    /** @param list<mixed> $parameters */
    private function countQuery(string $sql, array $parameters = []): int
    {
        $count = $this->connection->fetchOne($sql, $parameters);
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    private function signIn(): void
    {
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
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

    /** @param array<string, mixed> $data */
    private static function stringValue(array $data, string $key): string
    {
        self::assertArrayHasKey($key, $data);
        self::assertIsString($data[$key]);

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function arrayValue(array $data, string $key): array
    {
        self::assertArrayHasKey($key, $data);
        self::assertIsArray($data[$key]);

        $typed = [];
        foreach ($data[$key] as $k => $v) {
            self::assertIsString($k);
            $typed[$k] = $v;
        }

        return $typed;
    }

    /** @return array<string, string> */
    private static function jsonHeaders(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }

    private function resetLoginThrottling(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }
}
