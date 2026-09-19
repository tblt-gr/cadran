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
 * Worked example, April 2026 on one account opened on 2026-01-01: opening
 * 1000.00 (2026-03-31), booked +250.50 and -80.25 give 1170.25 against an
 * observed closing of 1165.00, so the discrepancy is -5.25. One -12.00 row is
 * still PENDING. The closing snapshot is unreconciled. April therefore fails
 * all three checks once each; March, whose only snapshot is reconciled and
 * whose rows are all booked, fails none.
 */
final class PeriodClosureControllerTest extends WebTestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string OPENING = '00000000-0000-7000-8000-0000000000b1';
    private const string CLOSING = '00000000-0000-7000-8000-0000000000b2';

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
        $this->seedSnapshot(self::OPENING, '2026-03-31', '1000.00', 'RECONCILED');
        $this->seedSnapshot(self::CLOSING, '2026-04-30', '1165.00', 'UNRECONCILED');
        $this->seedTransaction('00000000-0000-7000-8000-00000000f001', '250.50', 2, 'INCOME', 'BOOKED', '2026-04-10');
        $this->seedTransaction('00000000-0000-7000-8000-00000000f002', '-80.25', 2, 'EXPENSE', 'BOOKED', '2026-04-20');
        $this->seedTransaction('00000000-0000-7000-8000-00000000f003', '-12.00', 2, 'EXPENSE', 'PENDING', '2026-04-22');

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testAnUnverifiedMonthListsEachFailingConditionOnceWithoutAnyAmount(): void
    {
        $body = $this->call('GET', '/api/v1/periods/2026-04');

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($body['closed']);
        self::assertTrue($body['ended']);
        self::assertSame([
            ['condition' => 'UNRECONCILED_ACCOUNT', 'count' => 1],
            ['condition' => 'UNEXPLAINED_DISCREPANCY', 'count' => 1],
            ['condition' => 'PENDING_TRANSACTIONS', 'count' => 1],
        ], $body['blockers']);
    }

    public function testAVerifiedMonthClosesWithoutAnyConfirmation(): void
    {
        $body = $this->call('PUT', '/api/v1/periods/2026-03/closure', []);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('2026-03', $body['period']);
        self::assertTrue($body['active']);
        self::assertSame(1, $body['version']);
        self::assertSame(WorkspaceFixture::OWNER_ID, $body['closedBy']);
        self::assertSame(1, $this->rows('SELECT count(*) FROM audit_events WHERE event_type = ?', ['account_period_closure.closed']));

        $status = $this->call('GET', '/api/v1/periods/2026-03');
        self::assertTrue($status['closed']);
        self::assertSame([], $status['blockers']);
    }

    public function testClosingRefusesEachFailingConditionUntilItIsConfirmed(): void
    {
        $body = $this->call('PUT', '/api/v1/periods/2026-04/closure', []);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closing-blocked', $body['type']);
        self::assertCount(3, self::list($body, 'blockers'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM account_period_closures'));

        // A confirmation of two conditions leaves the third one blocking.
        $partial = $this->call('PUT', '/api/v1/periods/2026-04/closure', ['overrides' => [
            'UNRECONCILED_ACCOUNT' => 'Statement not received yet',
            'PENDING_TRANSACTIONS' => 'Card hold expires next week',
        ]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([['condition' => 'UNEXPLAINED_DISCREPANCY', 'count' => 1]], $partial['blockers']);
        self::assertSame(0, $this->rows('SELECT count(*) FROM account_period_closures'));
    }

    public function testAnOverrideIsAuditedWithItsConditionsAndReasonsButNoAmountOrLabel(): void
    {
        $body = $this->call('PUT', '/api/v1/periods/2026-04/closure', ['overrides' => [
            'UNRECONCILED_ACCOUNT' => 'Statement not received yet',
            'UNEXPLAINED_DISCREPANCY' => 'Bank fee to identify',
            'PENDING_TRANSACTIONS' => 'Card hold expires next week',
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->rows('SELECT count(*) FROM account_period_closures WHERE reopened_at IS NULL'));
        $event = $this->connection->fetchAssociative(
            'SELECT event_type, entity_id, after_json::text AS after_state FROM audit_events WHERE event_type = ?',
            ['account_period_closure.closed'],
        );
        self::assertIsArray($event);
        self::assertSame($body['id'], $event['entity_id']);
        $trail = $event['after_state'];
        self::assertIsString($trail);
        self::assertStringContainsString('UNRECONCILED_ACCOUNT,UNEXPLAINED_DISCREPANCY,PENDING_TRANSACTIONS', $trail);
        self::assertStringContainsString('Bank fee to identify', $trail);
        foreach (['5.25', '250.50', '80.25', '12.00', '1165', 'CB TEST'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $trail);
        }
    }

    public function testAConfirmationMustNameARealBlockingConditionWithAReason(): void
    {
        $this->call('PUT', '/api/v1/periods/2026-04/closure', ['overrides' => ['UNKNOWN' => 'why']]);
        self::assertResponseStatusCodeSame(422);
        $this->call('PUT', '/api/v1/periods/2026-04/closure', ['overrides' => ['UNRECONCILED_ACCOUNT' => '  ']]);
        self::assertResponseStatusCodeSame(422);
        $this->call('PUT', '/api/v1/periods/2026-04/closure', ['overrides' => ['UNRECONCILED_ACCOUNT' => str_repeat('x', 201)]]);
        self::assertResponseStatusCodeSame(422);
        // A blanket flag is not a confirmation.
        $this->call('PUT', '/api/v1/periods/2026-04/closure', ['force' => true]);
        self::assertResponseStatusCodeSame(422);
        // March blocks on nothing: confirming a condition it does not fail is refused.
        $this->call('PUT', '/api/v1/periods/2026-03/closure', ['overrides' => ['PENDING_TRANSACTIONS' => 'Just in case']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->rows('SELECT count(*) FROM account_period_closures'));
    }

    public function testAMonthThatHasNotEndedCannotBeClosed(): void
    {
        $current = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m');
        $this->call('PUT', '/api/v1/periods/'.$current.'/closure', []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->rows('SELECT count(*) FROM account_period_closures'));
    }

    public function testAClosedMonthCannotBeClosedTwice(): void
    {
        $this->call('PUT', '/api/v1/periods/2026-03/closure', []);
        $body = $this->call('PUT', '/api/v1/periods/2026-03/closure', []);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closure-conflict', $body['type']);
    }

    public function testReopeningNeedsAReasonAndTheCurrentVersionAndLeavesTheHistory(): void
    {
        $closed = $this->call('PUT', '/api/v1/periods/2026-03/closure', []);
        self::assertResponseStatusCodeSame(201);

        $this->call('POST', '/api/v1/periods/2026-03/reopening', ['version' => 1, 'reason' => '']);
        self::assertResponseStatusCodeSame(422);
        $stale = $this->call('POST', '/api/v1/periods/2026-03/reopening', ['version' => 7, 'reason' => 'Late invoice']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/stale-version', $stale['type']);
        self::assertSame(1, $this->rows('SELECT count(*) FROM account_period_closures WHERE reopened_at IS NULL'));

        $reopened = $this->call('POST', '/api/v1/periods/2026-03/reopening', ['version' => 1, 'reason' => 'Late invoice']);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($reopened['active']);
        self::assertSame('Late invoice', $reopened['reopenReason']);
        self::assertSame(2, $reopened['version']);
        self::assertSame($closed['id'], $reopened['id']);

        $audit = $this->connection->fetchOne('SELECT after_json::text FROM audit_events WHERE event_type = ?', ['account_period_closure.reopened']);
        self::assertIsString($audit);
        self::assertStringContainsString('Late invoice', $audit);

        // A second reopening finds nothing to reopen; the month closes again as a new row.
        $this->call('POST', '/api/v1/periods/2026-03/reopening', ['version' => 2, 'reason' => 'Again']);
        self::assertResponseStatusCodeSame(404);
        $this->call('PUT', '/api/v1/periods/2026-03/closure', []);
        self::assertResponseStatusCodeSame(201);
        $history = $this->call('GET', '/api/v1/period-closures?year=2026');
        self::assertSame([true, false], array_column(self::list($history, 'items'), 'active'));
    }

    public function testAMonthWithoutAnySnapshotIsUnreconciledButItsDiscrepancyIsNotCalculableRatherThanZero(): void
    {
        $body = $this->call('GET', '/api/v1/periods/2026-02');

        self::assertSame([['condition' => 'UNRECONCILED_ACCOUNT', 'count' => 1]], $body['blockers']);
    }

    public function testAZeroDiscrepancyIsNotBlockingButAnExactVeryLargeOneIs(): void
    {
        // May: 1165.00 observed against the 1165.00 of the previous snapshot and no movement, so exactly zero.
        $this->seedSnapshot('00000000-0000-7000-8000-0000000000b3', '2026-05-31', '1165.00', 'UNRECONCILED');
        // June: a 26-digit balance against 1165.00 cannot be rounded away into a match.
        $this->seedSnapshot('00000000-0000-7000-8000-0000000000b4', '2026-06-30', '12345678901234567890123456.123456789012345678901234', 'UNRECONCILED');

        $may = $this->call('GET', '/api/v1/periods/2026-05');
        $june = $this->call('GET', '/api/v1/periods/2026-06');

        self::assertSame([['condition' => 'UNRECONCILED_ACCOUNT', 'count' => 1]], $may['blockers']);
        self::assertSame([
            ['condition' => 'UNRECONCILED_ACCOUNT', 'count' => 1],
            ['condition' => 'UNEXPLAINED_DISCREPANCY', 'count' => 1],
        ], $june['blockers']);
    }

    public function testAClosedMonthStaysFullyReadable(): void
    {
        $this->call('PUT', '/api/v1/periods/2026-04/closure', ['overrides' => [
            'UNRECONCILED_ACCOUNT' => 'Statement not received yet',
            'UNEXPLAINED_DISCREPANCY' => 'Bank fee to identify',
            'PENDING_TRANSACTIONS' => 'Card hold expires next week',
        ]]);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/v1/transactions?from=2026-04-01&to=2026-04-30');
        self::assertResponseStatusCodeSame(200);
        $this->client->request('GET', '/api/v1/accounts/'.self::ACCOUNT.'/reconciliation?snapshotId='.self::CLOSING.'&periodStart=2026-04-01');
        self::assertResponseStatusCodeSame(200);
        $view = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($view);
        self::assertSame(['value' => '-5.25', 'assetCode' => 'EUR'], $view['discrepancy']);
    }

    public function testAnotherWorkspaceNeitherSeesNorReopensAClosure(): void
    {
        $this->call('PUT', '/api/v1/periods/2026-03/closure', []);
        self::assertResponseStatusCodeSame(201);

        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $status = $this->call('GET', '/api/v1/periods/2026-03');
        self::assertFalse($status['closed']);
        $list = $this->call('GET', '/api/v1/period-closures?year=2026');
        self::assertSame([], $list['items']);
        $this->call('POST', '/api/v1/periods/2026-03/reopening', ['version' => 1, 'reason' => 'Intrusion']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->rows('SELECT count(*) FROM account_period_closures WHERE reopened_at IS NULL'));
    }

    public function testAnonymousCallersAreRefused(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/periods/2026-03');
        self::assertResponseStatusCodeSame(401);
    }

    public function testMalformedPeriodsAndQueriesAreRejected(): void
    {
        $this->call('GET', '/api/v1/periods/2026-13');
        self::assertResponseStatusCodeSame(404);
        $this->call('GET', '/api/v1/period-closures?year=26');
        self::assertResponseStatusCodeSame(400);
        $this->call('GET', '/api/v1/period-closures');
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $decoded = json_decode((string) $this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $typed = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $typed[$key] = $value;
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private static function list(array $body, string $key): array
    {
        $items = $body[$key] ?? null;
        self::assertIsList($items);

        return array_map(static function (mixed $item): array {
            self::assertIsArray($item);

            $typed = [];
            foreach ($item as $field => $value) {
                self::assertIsString($field);
                $typed[$field] = $value;
            }

            return $typed;
        }, $items);
    }

    private function signIn(string $email): void
    {
        $this->client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode([
            'email' => $email, 'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /** @param list<string> $params */
    private function rows(string $sql, array $params = []): int
    {
        $value = $this->connection->fetchOne($sql, $params);
        self::assertTrue(is_int($value) || is_string($value));

        return (int) $value;
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

    private function seedSnapshot(string $id, string $asOf, string $amount, string $status): void
    {
        $this->connection->insert('account_balance_snapshots', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => self::ACCOUNT, 'as_of' => $asOf,
            'amount_value' => $amount, 'amount_literal' => $amount, 'amount_asset' => 'EUR', 'source' => 'MANUAL',
            'reconciliation_status' => $status, 'comment' => null, 'version' => 1,
            'recorded_at' => '2026-09-01 10:00:00+00', 'recorded_by' => WorkspaceFixture::OWNER_ID, 'superseded_at' => null,
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
}
