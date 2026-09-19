<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

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
 * Worked example: opening 1000.00 (2026-03-31), booked +250.50 and -80.25 in
 * April give 1170.25; the observed closing of 1165.00 leaves -5.25.
 */
final class AccountReconciliationControllerTest extends WebTestCase
{
    use ClosesPeriods;

    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string OPENING = '00000000-0000-7000-8000-0000000000b1';
    private const string CLOSING = '00000000-0000-7000-8000-0000000000b2';
    private const string OTHER_CLOSING = '00000000-0000-7000-8000-0000000000b9';

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
        $this->seedAccount(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);
        $this->seedSnapshot(self::OPENING, '2026-03-31', '1000.00');
        $this->seedSnapshot(self::CLOSING, '2026-04-30', '1165.00');
        $this->seedSnapshot(self::OTHER_CLOSING, '2026-04-30', '5.00', self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, WorkspaceFixture::OTHER_OWNER_ID);
        $this->seedTransaction('00000000-0000-7000-8000-00000000f001', '250.50', 2, 'INCOME', 'BOOKED', '2026-04-10');
        $this->seedTransaction('00000000-0000-7000-8000-00000000f002', '-80.25', 2, 'EXPENSE', 'BOOKED', '2026-04-20');
        $this->seedTransaction('00000000-0000-7000-8000-00000000f003', '-12.00', 2, 'EXPENSE', 'PENDING', '2026-04-22');

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

    public function testTheViewCarriesExactStringDecimalsAndThePendingRows(): void
    {
        $body = $this->get(self::ACCOUNT, self::CLOSING);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['value' => '1000.00', 'assetCode' => 'EUR'], $body['openingBalance']);
        self::assertSame(['value' => '170.25', 'assetCode' => 'EUR'], $body['movementsTotal']);
        self::assertSame(['value' => '1165.00', 'assetCode' => 'EUR'], $body['closingBalance']);
        self::assertSame(['value' => '-5.25', 'assetCode' => 'EUR'], $body['discrepancy']);
        self::assertNull($body['nonCalculableReason']);
        self::assertSame(1, $body['pendingCount']);
        self::assertSame(['OVERRIDE', 'ADJUST'], $body['availableResolutions']);
        self::assertSame(1, $body['snapshotVersion']);
    }

    public function testAMissingOpeningIsAnExplainedNullNotAZero(): void
    {
        $body = $this->get(self::ACCOUNT, self::CLOSING, '2026-03-01');

        self::assertResponseStatusCodeSame(200);
        self::assertNull($body['discrepancy']);
        self::assertNull($body['openingBalance']);
        self::assertSame('MISSING_OPENING_BALANCE', $body['nonCalculableReason']);
        self::assertSame([], $body['availableResolutions']);
    }

    public function testAnotherWorkspaceCannotBeReadOrResolved(): void
    {
        $this->get(self::OTHER_ACCOUNT, self::OTHER_CLOSING);
        self::assertResponseStatusCodeSame(404);
        $this->get(self::ACCOUNT, self::OTHER_CLOSING);
        self::assertResponseStatusCodeSame(404);

        $this->post(self::OTHER_ACCOUNT, ['snapshotId' => self::OTHER_CLOSING, 'snapshotVersion' => 1, 'periodStart' => '2026-04-01', 'resolution' => 'OVERRIDE']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('UNRECONCILED', $this->snapshotStatus(self::OTHER_CLOSING));
    }

    public function testAnonymousCallersAreRefused(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/accounts/'.self::ACCOUNT.'/reconciliation?snapshotId='.self::CLOSING.'&periodStart=2026-04-01');

        self::assertResponseStatusCodeSame(401);
    }

    public function testMalformedQueriesAreUnprocessable(): void
    {
        foreach (['', '?snapshotId=nope&periodStart=2026-04-01', '?snapshotId='.self::CLOSING.'&periodStart=2026-4-1', '?snapshotId='.self::CLOSING.'&periodStart=2026-04-01&x=1', '?snapshotId='.self::CLOSING.'&periodStart=2026-05-01'] as $query) {
            $this->client->request('GET', '/api/v1/accounts/'.self::ACCOUNT.'/reconciliation'.$query);
            self::assertResponseStatusCodeSame(422, $query);
        }
    }

    public function testAdjustCreatesOneAdjustmentAndReconcilesWithoutIncomeOrExpense(): void
    {
        $body = $this->post(self::ACCOUNT, $this->resolution('ADJUST'));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('RECONCILED', $body['reconciliationStatus']);
        self::assertSame(2, $body['snapshotVersion']);
        self::assertSame([], $body['availableResolutions']);
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM transaction_transactions WHERE nature = 'ADJUSTMENT' AND state = 'BOOKED'"));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_events WHERE event_type = 'account.reconciled'"));
    }

    public function testAnAdjustmentIntoAClosedMonthIsRefusedAndTheSnapshotStaysUnreconciled(): void
    {
        $this->closeMonthInDatabase($this->connection, 2026, 4);
        $before = (int) $this->scalar('SELECT count(*) FROM transaction_transactions');

        $body = $this->post(self::ACCOUNT, $this->resolution('ADJUST'));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $body['type']);
        self::assertSame($before, (int) $this->scalar('SELECT count(*) FROM transaction_transactions'));
        self::assertSame('UNRECONCILED', $this->snapshotStatus(self::CLOSING));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM audit_events WHERE event_type = 'account.reconciled'"));
    }

    public function testOverrideAndMatchIntoAClosedMonthAreRefusedToo(): void
    {
        $this->closeMonthInDatabase($this->connection, 2026, 4);

        foreach (['OVERRIDE', 'MATCH'] as $resolution) {
            $body = $this->post(self::ACCOUNT, $this->resolution($resolution));

            self::assertResponseStatusCodeSame(409, $resolution);
            self::assertSame('/problems/period-closed', $body['type']);
        }
        self::assertSame('UNRECONCILED', $this->snapshotStatus(self::CLOSING));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM audit_events WHERE event_type IN ('account.reconciled', 'account.reconciliation_overridden')"));
    }

    public function testAReconciliationReplayIsRefusedOnceItsMonthHasClosed(): void
    {
        $key = 'reconcile-closed-replay-0001';
        $this->post(self::ACCOUNT, $this->resolution('ADJUST'), $key);
        self::assertResponseStatusCodeSame(200);
        $this->closeMonthInDatabase($this->connection, 2026, 4);

        $body = $this->post(self::ACCOUNT, $this->resolution('ADJUST'), $key);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $body['type']);
        self::assertNull($this->client->getResponse()->headers->get('Idempotency-Replayed'));
    }

    public function testTheViewOfAClosedMonthStaysReadable(): void
    {
        $this->closeMonthInDatabase($this->connection, 2026, 4);

        $body = $this->get(self::ACCOUNT, self::CLOSING);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['value' => '-5.25', 'assetCode' => 'EUR'], $body['discrepancy']);
    }

    public function testAReplayedKeyReturnsTheStoredResponseWithoutASecondAdjustment(): void
    {
        $key = 'reconcile-idempotency-key-0001';
        $first = $this->post(self::ACCOUNT, $this->resolution('ADJUST'), $key);
        $second = $this->post(self::ACCOUNT, $this->resolution('ADJUST'), $key);

        self::assertEqualsCanonicalizing($first, $second);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM transaction_transactions WHERE nature = 'ADJUSTMENT'"));
    }

    public function testASecondResolutionWithoutKeyIsAConflict(): void
    {
        $this->post(self::ACCOUNT, $this->resolution('ADJUST'));
        $this->post(self::ACCOUNT, [...$this->resolution('ADJUST'), 'snapshotVersion' => 2]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM transaction_transactions WHERE nature = 'ADJUSTMENT'"));
    }

    public function testAStaleVersionIsAStaleVersionProblem(): void
    {
        $body = $this->post(self::ACCOUNT, [...$this->resolution('ADJUST'), 'snapshotVersion' => 5]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/stale-version', $body['type']);
        self::assertSame('UNRECONCILED', $this->snapshotStatus(self::CLOSING));
    }

    public function testOverrideNeedsAnExplicitResolutionAndWritesNoTransaction(): void
    {
        $before = (int) $this->scalar('SELECT count(*) FROM transaction_transactions');
        $this->post(self::ACCOUNT, $this->resolution('OVERRIDE'));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($before, (int) $this->scalar('SELECT count(*) FROM transaction_transactions'));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_events WHERE event_type = 'account.reconciliation_overridden'"));
    }

    public function testMatchOnANonZeroDiscrepancyIsUnprocessable(): void
    {
        $this->post(self::ACCOUNT, $this->resolution('MATCH'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('UNRECONCILED', $this->snapshotStatus(self::CLOSING));
    }

    public function testUnknownFieldsOrResolutionsAreUnprocessable(): void
    {
        $this->post(self::ACCOUNT, [...$this->resolution('ADJUST'), 'amount' => '1']);
        self::assertResponseStatusCodeSame(422);
        $this->post(self::ACCOUNT, $this->resolution('SILENT'));
        self::assertResponseStatusCodeSame(422);
        $this->post(self::ACCOUNT, [...$this->resolution('ADJUST'), 'snapshotVersion' => '1']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAMutationWithoutTheCsrfHeaderIsRefused(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');
        $this->post(self::ACCOUNT, $this->resolution('OVERRIDE'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('UNRECONCILED', $this->snapshotStatus(self::CLOSING));
    }

    /** @return array<string, mixed> */
    private function resolution(string $resolution): array
    {
        return ['snapshotId' => self::CLOSING, 'snapshotVersion' => 1, 'periodStart' => '2026-04-01', 'resolution' => $resolution];
    }

    /** @return array<string, mixed> */
    private function get(string $account, string $snapshot, string $periodStart = '2026-04-01'): array
    {
        $this->client->request('GET', sprintf('/api/v1/accounts/%s/reconciliation?snapshotId=%s&periodStart=%s', $account, $snapshot, $periodStart), server: self::headers());

        return $this->decode();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $account, array $body, ?string $key = null): array
    {
        $server = self::headers();
        if (null !== $key) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        $this->client->request('POST', '/api/v1/accounts/'.$account.'/reconciliation', server: $server, content: json_encode($body, JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        if (!is_array($decoded)) {
            return [];
        }
        $typed = [];
        foreach ($decoded as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    private function snapshotStatus(string $snapshotId): string
    {
        return $this->scalar('SELECT reconciliation_status FROM account_balance_snapshots WHERE id = ?', [$snapshotId]);
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }

    private function seedAccount(string $id, string $workspace): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => 'Compte '.$id, 'asset_code' => 'EUR',
            'kind' => 'CURRENT', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => false,
            'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01', 'closed_on' => null,
            'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function seedSnapshot(string $id, string $asOf, string $amount, string $account = self::ACCOUNT, string $workspace = WorkspaceFixture::OWN_WORKSPACE, string $author = WorkspaceFixture::OWNER_ID): void
    {
        $this->connection->insert('account_balance_snapshots', [
            'id' => $id, 'workspace_id' => $workspace, 'account_id' => $account, 'as_of' => $asOf,
            'amount_value' => $amount, 'amount_literal' => $amount, 'amount_asset' => 'EUR', 'source' => 'MANUAL',
            'reconciliation_status' => 'UNRECONCILED', 'comment' => null, 'version' => 1,
            'recorded_at' => '2026-05-01 10:00:00+00', 'recorded_by' => $author, 'superseded_at' => null,
        ]);
    }

    private function seedTransaction(string $id, string $amount, int $scale, string $nature, string $state, string $bookedOn): void
    {
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => self::ACCOUNT,
            'asset_code' => 'EUR', 'amount_value' => $amount, 'amount_scale' => $scale, 'state' => $state, 'nature' => $nature,
            'source' => 'MANUAL', 'booked_on' => $bookedOn, 'raw_label' => 'CB TEST', 'version' => 1,
            'created_at' => '2026-05-01 10:00:00+00', 'updated_at' => '2026-05-01 10:00:00+00', 'last_editor_id' => WorkspaceFixture::OWNER_ID,
        ]);
    }

    /** @param list<string> $params */
    private function scalar(string $sql, array $params = []): string
    {
        $value = $this->connection->fetchOne($sql, $params);
        self::assertTrue(is_scalar($value), 'Expected a scalar database value.');

        return (string) $value;
    }
}
