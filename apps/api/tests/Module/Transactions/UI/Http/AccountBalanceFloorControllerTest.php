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

/**
 * ACC-028. The reference account holds a declared balance of 500.00 on 2026-11-01, then a booked
 * -200.00 on 2026-11-05 and +100.00 on 2026-11-10: day-end 300.00 and 400.00.
 */
final class AccountBalanceFloorControllerTest extends WebTestCase
{
    private const string COURANT = '00000000-0000-7000-8000-0000000000d1';
    private const string EPARGNE = '00000000-0000-7000-8000-0000000000d4';
    private const string OTHER = '00000000-0000-7000-8000-0000000000d2';
    private const string PROBLEM = '/problems/account-balance-insufficient';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $databaseReady = false;
    private int $sequence = 0;
    private string $income = '';

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
        $this->account(self::COURANT, WorkspaceFixture::OWN_WORKSPACE, 'CURRENT');
        $this->account(self::EPARGNE, WorkspaceFixture::OWN_WORKSPACE, 'SAVINGS');
        $this->account(self::OTHER, WorkspaceFixture::OTHER_WORKSPACE, 'CURRENT');
        $this->snapshot(self::COURANT, WorkspaceFixture::OWN_WORKSPACE, '2026-11-01', '500.00');
        $this->row(self::COURANT, '-200.00', '2026-11-05', 'EXPENSE');
        $this->income = $this->row(self::COURANT, '100.00', '2026-11-10', 'INCOME');

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->client->request('POST', '/api/v1/session', server: self::headers(), content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testAnExpenseThatEmptiesALaterDayIsRefusedAndWritesNothing(): void
    {
        $before = $this->footprint();

        $this->create(self::COURANT, '-450.00', '2026-11-03');

        self::assertResponseStatusCodeSame(422);
        $body = $this->decode();
        self::assertSame(self::PROBLEM, $body['type']);
        self::assertSame(self::COURANT, $body['accountId']);
        self::assertSame('amount', $body['field']);
        self::assertSame('2026-11-05', $body['firstNegativeOn']);
        self::assertStringNotContainsString('450', (string) $this->client->getResponse()->getContent());
        self::assertSame($before, $this->footprint());
    }

    public function testAnExpenseOnTheLastDayIsRefusedAndExactlyZeroIsAccepted(): void
    {
        $this->create(self::COURANT, '-450.00', '2026-11-10');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('2026-11-10', $this->decode()['firstNegativeOn']);

        $this->create(self::COURANT, '-400.00', '2026-11-10');
        self::assertResponseStatusCodeSame(201);
    }

    public function testASameDayIncomeThenALargerExpenseIsJudgedOnTheDayEnd(): void
    {
        $this->create(self::COURANT, '100.00', '2026-11-03', 'INCOME');
        self::assertResponseStatusCodeSame(201);

        $this->create(self::COURANT, '-700.00', '2026-11-03');

        self::assertResponseStatusCodeSame(422);
    }

    public function testAPendingExpenseCountsLikeABookedOne(): void
    {
        $this->create(self::COURANT, '-450.00', '2026-11-12', 'EXPENSE', 'PENDING');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('2026-11-12', $this->decode()['firstNegativeOn']);
    }

    public function testVoidingAnIncomeThatFundedALaterExpenseIsRefused(): void
    {
        // Day-end 900.00 on 11-02, 700.00 on 11-05, 100.00 on 11-06, 200.00 on 11-10.
        $funding = $this->row(self::COURANT, '400.00', '2026-11-02', 'INCOME');
        $this->row(self::COURANT, '-600.00', '2026-11-06', 'EXPENSE');

        $this->voidTransaction($funding);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('2026-11-06', $this->decode()['firstNegativeOn']);
    }

    public function testVoidingTheLaterIncomeIsAccepted(): void
    {
        $this->voidTransaction($this->income);

        self::assertResponseStatusCodeSame(200);
    }

    public function testEditingAnExpenseDeeperIsRefusedAndShallowerIsAccepted(): void
    {
        $this->create(self::COURANT, '-50.00', '2026-11-04');
        self::assertResponseStatusCodeSame(201);
        $id = self::text($this->decode()['id']);

        $this->update($id, '-800.00', '2026-11-04');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::PROBLEM, $this->decode()['type']);
        self::assertSame('2026-11-04', $this->decode()['firstNegativeOn']);

        $this->update($id, '-100.00', '2026-11-04');
        self::assertResponseStatusCodeSame(200);
    }

    public function testAnAccountWithoutADeclaredBalanceIsNeverRefused(): void
    {
        $this->create(self::EPARGNE, '-9999999.00', '2026-11-03');

        self::assertResponseStatusCodeSame(201);
    }

    public function testATransferOverdrawingItsSourceIsRefusedWholeOnTheSourceAmount(): void
    {
        $before = $this->footprint();

        $this->transfer('450.00', '2026-11-03');

        self::assertResponseStatusCodeSame(422);
        $body = $this->decode();
        self::assertSame(self::PROBLEM, $body['type']);
        self::assertSame('sourceAmount', $body['field']);
        self::assertSame(self::COURANT, $body['accountId']);
        self::assertSame($before, $this->footprint());

        $this->transfer('150.00', '2026-11-03');
        self::assertResponseStatusCodeSame(201);
    }

    public function testVoidingATransferThatFundedTheTargetIsRefused(): void
    {
        $this->snapshot(self::EPARGNE, WorkspaceFixture::OWN_WORKSPACE, '2026-11-01', '0.00');
        $this->transfer('100.00', '2026-11-02');
        self::assertResponseStatusCodeSame(201);
        $transfer = $this->decode();
        $this->row(self::EPARGNE, '-80.00', '2026-11-04', 'EXPENSE');

        $this->client->request('POST', '/api/v1/transfers/'.self::text($transfer['id']).'/void', server: self::headers(), content: '{"version":1}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::EPARGNE, $this->decode()['accountId']);
    }

    public function testABulkMoveThatOverdrawsItsSourceIsRefusedAsAWhole(): void
    {
        $funding = $this->row(self::COURANT, '400.00', '2026-11-02', 'INCOME');
        $this->row(self::COURANT, '-600.00', '2026-11-06', 'EXPENSE');
        $before = $this->footprint();
        $changes = ['accountId' => self::EPARGNE];

        $this->bulk($changes, [$funding, $this->income]);
        self::assertResponseStatusCodeSame(200);
        $token = self::text($this->decode()['previewToken']);
        $this->bulk($changes, [$funding, $this->income], $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::COURANT, $this->decode()['accountId']);
        self::assertSame($before, $this->footprint());
        self::assertSame(0, $this->rows("account_id = '".self::EPARGNE."'"));
    }

    public function testADeclaredBalanceBelowZeroIsRefusedOnItsAmount(): void
    {
        $this->record(self::COURANT, '2026-11-02', '-5.00');

        self::assertResponseStatusCodeSame(422);
        $body = $this->decode();
        self::assertSame(self::PROBLEM, $body['type']);
        self::assertSame('amount', $body['field']);
    }

    public function testReAnchoringThatMakesALaterDayNegativeIsRefused(): void
    {
        $this->record(self::COURANT, '2026-11-04', '100.00');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('2026-11-05', $this->decode()['firstNegativeOn']);

        $this->record(self::COURANT, '2026-11-04', '250.00');
        self::assertResponseStatusCodeSame(201);
    }

    public function testARefusedWriteDoesNotStoreItsIdempotencyOutcome(): void
    {
        $replayed = 'balance-floor-idem-0001';
        $this->create(self::COURANT, '-450.00', '2026-11-10', 'EXPENSE', 'BOOKED', $replayed);
        self::assertResponseStatusCodeSame(422);

        $this->create(self::COURANT, '1000.00', '2026-11-09', 'INCOME');
        self::assertResponseStatusCodeSame(201);

        $this->create(self::COURANT, '-450.00', '2026-11-10', 'EXPENSE', 'BOOKED', $replayed);
        self::assertResponseStatusCodeSame(201);
    }

    public function testAnotherWorkspaceNeitherSeesNorSharesTheFloor(): void
    {
        $this->snapshot(self::OTHER, WorkspaceFixture::OTHER_WORKSPACE, '2026-11-01', '10.00');

        $this->create(self::OTHER, '-5.00', '2026-11-03');
        self::assertResponseStatusCodeSame(404);

        $this->create(self::COURANT, '-5.00', '2026-11-03');
        self::assertResponseStatusCodeSame(201);
    }

    /** @return array{int, int} */
    private function footprint(): array
    {
        return [
            $this->rows('TRUE'),
            self::integer($this->connection->fetchOne('SELECT COUNT(*) FROM audit_events WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE])),
        ];
    }

    private function rows(string $where): int
    {
        return self::integer($this->connection->fetchOne('SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = ? AND '.$where, [WorkspaceFixture::OWN_WORKSPACE]));
    }

    private function create(string $account, string $amount, string $bookedOn, string $nature = 'EXPENSE', string $state = 'BOOKED', ?string $idempotency = null): void
    {
        $headers = self::headers();
        if (null !== $idempotency) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $idempotency;
        }
        $this->client->request('POST', '/api/v1/transactions', server: $headers, content: json_encode(
            $this->payload($account, $amount, $bookedOn, $nature, $state),
            JSON_THROW_ON_ERROR,
        ));
    }

    private function update(string $id, string $amount, string $bookedOn): void
    {
        $this->client->request('PUT', '/api/v1/transactions/'.$id, server: self::headers(), content: json_encode(
            [...$this->payload(self::COURANT, $amount, $bookedOn, 'EXPENSE', 'BOOKED'), 'userLabel' => null, 'version' => 1],
            JSON_THROW_ON_ERROR,
        ));
    }

    private function voidTransaction(string $id): void
    {
        $this->client->request('POST', '/api/v1/transactions/'.$id.'/void', server: self::headers(), content: '{"version":1}');
    }

    /** @return array<string, mixed> */
    private function payload(string $account, string $amount, string $bookedOn, string $nature, string $state): array
    {
        return [
            'accountId' => $account, 'amount' => ['value' => $amount, 'assetCode' => 'EUR'], 'nature' => $nature,
            'state' => $state, 'bookedOn' => $bookedOn, 'valueOn' => null, 'authorizedOn' => null,
            'rawLabel' => 'Test', 'counterparty' => null, 'note' => null, 'paymentMethod' => null,
            'mcc' => null, 'maskedCard' => null, 'bankReference' => null, 'categoryId' => null, 'splits' => null,
        ];
    }

    private function transfer(string $amount, string $bookedOn): void
    {
        $this->client->request('POST', '/api/v1/transfers', server: self::headers(), content: json_encode([
            'sourceAccountId' => self::COURANT, 'targetAccountId' => self::EPARGNE,
            'sourceAmount' => ['value' => $amount, 'assetCode' => 'EUR'], 'targetAmount' => ['value' => $amount, 'assetCode' => 'EUR'],
            'state' => 'BOOKED', 'bookedOn' => $bookedOn, 'valueOn' => null, 'label' => 'Epargne', 'note' => null, 'fee' => null,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $changes
     * @param list<string>         $ids
     */
    private function bulk(array $changes, array $ids, ?string $token = null): void
    {
        $body = ['changes' => $changes, 'scope' => ['type' => 'IDS', 'ids' => $ids]];
        if (null === $token) {
            $body['preview'] = true;
        } else {
            $body['previewToken'] = $token;
        }
        $headers = self::headers();
        if (null !== $token) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = 'balance-floor-bulk-0001';
        }
        $this->client->request('POST', '/api/v1/transactions/bulk-edit', server: $headers, content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function record(string $account, string $asOf, string $amount): void
    {
        $this->client->request('POST', '/api/v1/accounts/'.$account.'/balances', server: self::headers(), content: json_encode([
            'asOf' => $asOf, 'amount' => $amount, 'amountAssetCode' => 'EUR', 'comment' => null, 'version' => null,
        ], JSON_THROW_ON_ERROR));
    }

    private function row(string $account, string $amount, string $bookedOn, string $nature, string $source = 'MANUAL'): string
    {
        $id = sprintf('00000000-0000-7000-8000-%012d', 900000 + ++$this->sequence);
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => $account,
            'asset_code' => 'EUR', 'amount_value' => $amount, 'amount_scale' => 2,
            'state' => 'BOOKED', 'nature' => $nature, 'source' => $source, 'booked_on' => $bookedOn, 'value_on' => null,
            'raw_label' => 'Seed', 'review_reason' => null, 'voided_at' => null,
            'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ]);

        return $id;
    }

    private function account(string $id, string $workspace, string $kind): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => 'Compte '.$kind, 'asset_code' => 'EUR', 'kind' => $kind,
            'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => false, 'include_in_emergency_fund' => false,
            'opened_on' => '2026-01-01', 'closed_on' => null, 'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function snapshot(string $account, string $workspace, string $asOf, string $amount): void
    {
        $this->connection->insert('account_balance_snapshots', [
            'id' => sprintf('00000000-0000-7000-8000-%012d', 950000 + ++$this->sequence),
            'workspace_id' => $workspace, 'account_id' => $account, 'as_of' => $asOf,
            'amount_value' => $amount, 'amount_literal' => $amount, 'amount_asset' => 'EUR',
            'source' => 'MANUAL', 'reconciliation_status' => 'UNRECONCILED', 'comment' => null, 'version' => 1,
            'recorded_at' => '2026-11-02 00:00:00+00', 'recorded_by' => WorkspaceFixture::OWNER_ID, 'superseded_at' => null,
        ]);
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }

    private static function integer(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
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
