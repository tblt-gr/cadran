<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Tests\Support\BeforeLockTransactionRepository;
use App\Tests\Support\ClosesPeriods;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TransactionBulkControllerTest extends WebTestCase
{
    use ClosesPeriods;

    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string SAVINGS_ACCOUNT = '00000000-0000-7000-8000-0000000000d4';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string EXPENSE_A = '00000000-0000-7000-8000-0000000000c1';
    private const string EXPENSE_B = '00000000-0000-7000-8000-0000000000c4';
    private const string EXPENSE_C = '00000000-0000-7000-8000-0000000000c6';
    private const string INCOME = '00000000-0000-7000-8000-0000000000c2';
    private const string OTHER_CATEGORY = '00000000-0000-7000-8000-0000000000c3';
    private const string UNKNOWN = '00000000-0000-7000-8000-0000000000e9';
    private const string FOREIGN_TRANSACTION = '00000000-0000-7000-8000-0000000000f9';

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
        $this->seedAccount(self::OWN_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant');
        $this->seedAccount(self::SAVINGS_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Epargne');
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Compte voisin');
        $this->seedCategory(self::EXPENSE_A, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Courses');
        $this->seedCategory(self::EXPENSE_B, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Divers');
        $this->seedCategory(self::EXPENSE_C, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Extras');
        $this->seedCategory(self::INCOME, WorkspaceFixture::OWN_WORKSPACE, 'INCOME', 'Salaire');
        $this->seedCategory(self::OTHER_CATEGORY, WorkspaceFixture::OTHER_WORKSPACE, 'EXPENSE', 'Prive voisin');

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

    public function testEligibleTransactionsAreRecategorisedInOneCallAndAuditedWithoutAmounts(): void
    {
        $first = $this->expense('-42.90', self::EXPENSE_A);
        $second = $this->expense('-0.0001', null);
        $third = $this->expense('-9999999999999999999999.9999', self::EXPENSE_A);

        $this->bulk(self::EXPENSE_B, $this->ids([$first, $second, $third]), 'bulk-happy-path-0001');

        self::assertResponseStatusCodeSame(200);
        $result = $this->decode();
        self::assertSame(3, $result['requestedCount']);
        self::assertSame(3, $result['changed']);
        self::assertSame([], $result['skipped']);
        self::assertSame(500, $result['maximum']);
        foreach ([$first, $second, $third] as $id) {
            self::assertSame([self::EXPENSE_B], $this->categoriesOf($id));
        }
        self::assertSame(2, $this->versionOf($first));
        self::assertSame(2, $this->versionOf($second));
        // The split carries the transaction amount to the last digit, whatever its size.
        foreach ([$first, $second, $third] as $id) {
            self::assertTrue($this->splitEqualsTransactionAmount($id));
        }

        $events = $this->connection->fetchAllAssociative(
            "SELECT event_type, entity_type, before_json, after_json FROM audit_events WHERE workspace_id = ? AND event_type LIKE 'transaction.bulk%'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertCount(4, $events);
        $json = json_encode($events, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('42.90', $json);
        self::assertStringNotContainsString('CARREFOUR', $json);
        self::assertStringContainsString(self::EXPENSE_B, $json);
    }

    public function testIneligibleTransactionsAreSkippedWithTheirReasonAndTheRestIsWritten(): void
    {
        $eligible = $this->expense('-10.00', self::EXPENSE_A);
        $wrongSign = $this->expense('1200.00', self::INCOME, 'INCOME');
        $already = $this->expense('-5.00', self::EXPENSE_B);
        $voided = $this->expense('-6.00', self::EXPENSE_A);
        $this->client->request('POST', '/api/v1/transactions/'.$voided.'/void', server: self::jsonHeaders(), content: '{"version":1}');
        self::assertResponseIsSuccessful();
        $split = $this->expense('-30.00', self::EXPENSE_A);
        $this->client->request('PUT', '/api/v1/transactions/'.$split.'/splits', server: self::jsonHeaders(), content: json_encode([
            'version' => 1,
            'splits' => [
                ['categoryId' => self::EXPENSE_A, 'amount' => ['value' => '-10.00', 'assetCode' => 'EUR'], 'analyticAxes' => [], 'note' => null],
                ['categoryId' => self::EXPENSE_C, 'amount' => ['value' => '-20.00', 'assetCode' => 'EUR'], 'analyticAxes' => [], 'note' => null],
            ],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $original = $this->expense('-80.00', self::EXPENSE_A);
        $this->client->request('POST', '/api/v1/transactions/'.$original.'/refunds', server: self::jsonHeaders(), content: json_encode([
            'accountId' => self::OWN_ACCOUNT, 'amount' => ['value' => '30.00', 'assetCode' => 'EUR'],
            'bookedOn' => '2026-03-14', 'rawLabel' => 'Remboursement', 'counterparty' => null, 'note' => null,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $refund = self::text($this->decode()['id']);
        $this->client->request('POST', '/api/v1/transfers', server: self::jsonHeaders(), content: json_encode([
            'sourceAccountId' => self::OWN_ACCOUNT, 'targetAccountId' => self::SAVINGS_ACCOUNT,
            'sourceAmount' => ['value' => '100.00', 'assetCode' => 'EUR'], 'targetAmount' => ['value' => '100.00', 'assetCode' => 'EUR'],
            'state' => 'BOOKED', 'bookedOn' => '2026-03-14', 'valueOn' => null, 'label' => 'Epargne', 'note' => null, 'fee' => null,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $leg = self::text($this->connection->fetchOne(
            'SELECT source_transaction_id FROM transaction_transfers WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE],
        ));
        $closed = $this->expense('-7.00', self::EXPENSE_A, bookedOn: '2026-02-10');
        $this->closeMonthInDatabase($this->connection, 2026, 2);

        $this->bulk(self::EXPENSE_B, $this->ids([
            $eligible, $wrongSign, $already, $voided, $split, $original, $refund, $leg, $closed, self::UNKNOWN, self::FOREIGN_TRANSACTION,
        ]), 'bulk-partial-refusal-01');

        self::assertResponseStatusCodeSame(200);
        $result = $this->decode();
        self::assertSame(11, $result['requestedCount']);
        self::assertSame(1, $result['changed']);
        $reasons = $this->skippedReasons();
        ksort($reasons);
        $expected = [
            $wrongSign => 'SIGN_MISMATCH',
            $already => 'ALREADY_IN_CATEGORY',
            $voided => 'TERMINAL',
            $split => 'SPLIT',
            $original => 'HAS_REFUNDS',
            $refund => 'REFUND',
            $leg => 'TRANSFER_LEG',
            $closed => 'CLOSED_PERIOD',
            self::UNKNOWN => 'NOT_FOUND',
            self::FOREIGN_TRANSACTION => 'NOT_FOUND',
        ];
        ksort($expected);
        self::assertSame($expected, $reasons);
        self::assertSame([self::EXPENSE_B], $this->categoriesOf($eligible));
        self::assertSame([self::INCOME], $this->categoriesOf($wrongSign));
        self::assertSame([self::EXPENSE_A], $this->categoriesOf($closed));
        self::assertSame([self::EXPENSE_A], $this->categoriesOf($original));
        // Version 2 is the split replacement above: the bulk run did not touch it again.
        self::assertSame(2, $this->versionOf($split));
    }

    public function testNothingIsWrittenWhenNoTransactionIsEligible(): void
    {
        $income = $this->expense('50.00', self::INCOME, 'INCOME');

        $this->bulk(self::EXPENSE_B, $this->ids([$income]), 'bulk-nothing-eligible-1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->decode()['changed']);
        self::assertSame(1, $this->versionOf($income));
        self::assertSame(0, self::number($this->connection->fetchOne("SELECT count(*) FROM audit_events WHERE event_type LIKE 'transaction.bulk%'")));
    }

    public function testMoreThanFiveHundredIdentifiersAreRefusedBeforeAnyWrite(): void
    {
        $eligible = $this->expense('-10.00', self::EXPENSE_A);
        $ids = [$eligible];
        for ($index = 0; $index < 500; ++$index) {
            $ids[] = sprintf('00000000-0000-7000-8000-%012d', 900000 + $index);
        }

        $this->bulk(self::EXPENSE_B, $this->ids($ids), 'bulk-over-the-cap-0001');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/transactions.bulk-scope-too-large', $this->decode()['type']);
        self::assertSame(500, $this->decode()['limit']);
        self::assertSame([self::EXPENSE_A], $this->categoriesOf($eligible));
    }

    public function testExactlyFiveHundredIdentifiersAreAccepted(): void
    {
        $ids = [];
        for ($index = 0; $index < 500; ++$index) {
            $ids[] = sprintf('00000000-0000-7000-8000-%012d', 900000 + $index);
        }

        $this->bulk(self::EXPENSE_B, $this->ids($ids), 'bulk-at-the-cap-000001');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(500, $this->decode()['requestedCount']);
        self::assertCount(500, $this->skippedReasons());
    }

    public function testAFilterScopeMatchingMoreThanTheCapIsRefusedAndNothingIsWritten(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO transaction_transactions (id, workspace_id, account_id, asset_code, amount_value, amount_scale, state, nature, source, booked_on, raw_label, version, created_at, updated_at)
             SELECT ('00000000-0000-7000-8000-' || lpad(n::text, 12, '0'))::uuid, ?, ?, 'EUR', -1.00, 2, 'BOOKED', 'EXPENSE', 'MANUAL', '2026-03-14', 'BULK', 1, now(), now()
             FROM generate_series(1, 501) AS n",
            [WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT],
        );

        $this->bulkFilter(self::EXPENSE_B, ['q' => 'bulk'], 501, 'bulk-filter-over-cap-1');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/transactions.bulk-scope-too-large', $this->decode()['type']);
        self::assertSame(0, self::number($this->connection->fetchOne('SELECT count(*) FROM transaction_splits')));
    }

    public function testAFilterScopeAppliesToTheMatchingSetAndRefusesAStaleCount(): void
    {
        $match = $this->expense('-10.00', self::EXPENSE_A, label: 'CB AMAZON PRIME');
        $other = $this->expense('-11.00', self::EXPENSE_A, label: 'CB CARREFOUR');

        $this->bulkFilter(self::EXPENSE_B, ['q' => 'amazon'], 2, 'bulk-filter-stale-count');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/transactions.bulk-scope-changed', $this->decode()['type']);
        self::assertSame(1, $this->decode()['actualCount']);
        self::assertSame([self::EXPENSE_A], $this->categoriesOf($match));

        $this->bulkFilter(self::EXPENSE_B, ['q' => 'amazon'], 1, 'bulk-filter-exact-count');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->decode()['changed']);
        self::assertSame([self::EXPENSE_B], $this->categoriesOf($match));
        self::assertSame([self::EXPENSE_A], $this->categoriesOf($other));
    }

    public function testARowEditedOutOfTheFilterBetweenResolutionAndLockIsNotRecategorised(): void
    {
        $stays = $this->expense('-10.00', self::EXPENSE_A, label: 'CB AMAZON PRIME');
        $leaves = $this->expense('-11.00', self::EXPENSE_A, label: 'CB AMAZON MARKETPLACE');

        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->client->disableReboot();
        // Same connection as the request, or the repository would write outside its transaction.
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $hooked = new BeforeLockTransactionRepository(new DbalTransactionRepository($connection));
        // Runs after the filter scope was resolved and counted, before its rows are locked.
        $hooked->beforeLock = static function () use ($connection, $leaves): void {
            $connection->update('transaction_transactions', ['raw_label' => 'CB CARREFOUR'], ['id' => $leaves]);
        };
        self::getContainer()->set(DbalTransactionRepository::class, $hooked);

        $this->bulkFilter(self::EXPENSE_B, ['q' => 'amazon'], 2, 'bulk-filter-concurrent-1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->decode()['changed']);
        self::assertSame([$leaves => 'NO_LONGER_MATCHING'], $this->skippedReasons());
        self::assertSame([self::EXPENSE_B], $this->categoriesOf($stays));
        self::assertSame([self::EXPENSE_A], $this->categoriesOf($leaves));
    }

    public function testTheBatchAuditRowHoldsOnlyTheCategoryAndCounts(): void
    {
        $changed = $this->expense('-42.90', self::EXPENSE_A, label: 'CB AMAZON PRIME');
        $skipped = $this->expense('50.00', self::INCOME, 'INCOME', label: 'VIREMENT SALAIRE');

        $this->bulk(self::EXPENSE_B, $this->ids([$changed, $skipped]), 'bulk-audit-batch-00001');
        self::assertResponseStatusCodeSame(200);

        $row = $this->connection->fetchAssociative(
            "SELECT before_json, after_json FROM audit_events WHERE workspace_id = ? AND event_type = 'transaction.bulk_categorization_run'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertIsArray($row);
        $after = json_decode(self::text($row['after_json']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            ['categoryId' => self::EXPENSE_B, 'changed' => '1', 'requested' => '2', 'scope' => 'IDS', 'skipped' => '1'],
            \is_array($after) ? $this->sortedKeys($after) : null,
        );
        self::assertContains($row['before_json'], ['{}', '[]', null]);
    }

    public function testTheCountEndpointReturnsTheExactSizeOfTheFilteredSetWithinTheWorkspace(): void
    {
        $this->expense('-10.00', self::EXPENSE_A, label: 'CB AMAZON PRIME');
        $this->expense('-11.00', null, label: 'CB CARREFOUR');
        $this->seedForeignTransaction();

        $this->client->request('GET', '/api/v1/transactions/count');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['count' => 2], $this->decode());

        $this->client->request('GET', '/api/v1/transactions/count?q=amazon');
        self::assertSame(['count' => 1], $this->decode());

        $this->client->request('GET', '/api/v1/transactions/count?categorization=NONE');
        self::assertSame(['count' => 1], $this->decode());

        $this->client->request('GET', '/api/v1/transactions/count?pageSize=10');
        self::assertResponseStatusCodeSame(400);
    }

    public function testAForeignWorkspaceTransactionIsReportedNotFoundAndLeftUntouched(): void
    {
        $this->seedForeignTransaction();

        $this->bulk(self::EXPENSE_B, $this->ids([self::FOREIGN_TRANSACTION]), 'bulk-foreign-tx-000001');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->decode()['changed']);
        self::assertSame([self::FOREIGN_TRANSACTION => 'NOT_FOUND'], $this->skippedReasons());
        self::assertSame(1, $this->versionOf(self::FOREIGN_TRANSACTION));
        self::assertStringNotContainsString('Secret neighbour label', (string) $this->client->getResponse()->getContent());
    }

    public function testAForeignWorkspaceCategoryIsNotFound(): void
    {
        $id = $this->expense('-10.00', self::EXPENSE_A);

        $this->bulk(self::OTHER_CATEGORY, $this->ids([$id]), 'bulk-foreign-cat-00001');

        self::assertResponseStatusCodeSame(404);
        self::assertSame([self::EXPENSE_A], $this->categoriesOf($id));
    }

    public function testAnArchivedCategoryIsRefused(): void
    {
        $id = $this->expense('-10.00', self::EXPENSE_A);
        $this->connection->update('category_categories', ['archived_at' => '2026-03-15 10:00:00+00'], ['id' => self::EXPENSE_B]);

        $this->bulk(self::EXPENSE_B, $this->ids([$id]), 'bulk-archived-cat-00001');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([self::EXPENSE_A], $this->categoriesOf($id));
    }

    public function testReplayingTheSameKeyReturnsTheStoredResultWithoutWritingAgain(): void
    {
        $id = $this->expense('-10.00', self::EXPENSE_A);
        $body = $this->ids([$id]);

        $this->bulk(self::EXPENSE_B, $body, 'bulk-replay-key-000001');
        $first = $this->decode();
        $this->bulk(self::EXPENSE_B, $body, 'bulk-replay-key-000001');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertEquals($first, $this->decode());
        self::assertSame(2, $this->versionOf($id));
    }

    public function testReusingAKeyForADifferentBodyIsAConflictAndTheKeyIsRequired(): void
    {
        $id = $this->expense('-10.00', self::EXPENSE_A);

        $this->bulk(self::EXPENSE_B, $this->ids([$id]), 'bulk-key-reuse-0000001');
        $this->bulk(self::EXPENSE_C, $this->ids([$id]), 'bulk-key-reuse-0000001');
        self::assertResponseStatusCodeSame(409);
        self::assertSame([self::EXPENSE_B], $this->categoriesOf($id));

        $this->bulk(self::EXPENSE_C, $this->ids([$id]), null);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAReplayIsRefusedOnceTheMonthHasBeenClosed(): void
    {
        $id = $this->expense('-10.00', self::EXPENSE_A);
        $this->bulk(self::EXPENSE_B, $this->ids([$id]), 'bulk-replay-closed-0001');
        self::assertResponseStatusCodeSame(200);
        $this->closeMonthInDatabase($this->connection, 2026, 3);

        $this->bulk(self::EXPENSE_B, $this->ids([$id]), 'bulk-replay-closed-0001');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $this->decode()['type']);
    }

    public function testMalformedScopesAreValidationErrors(): void
    {
        foreach ([
            ['categoryId' => self::EXPENSE_B, 'scope' => ['type' => 'IDS', 'ids' => []]],
            ['categoryId' => self::EXPENSE_B, 'scope' => ['type' => 'IDS', 'ids' => ['nope']]],
            ['categoryId' => self::EXPENSE_B, 'scope' => ['type' => 'ALL']],
            ['categoryId' => self::EXPENSE_B, 'scope' => ['type' => 'FILTER', 'filters' => ['bogus' => 1], 'expectedCount' => 1]],
            ['categoryId' => self::EXPENSE_B, 'scope' => ['type' => 'FILTER', 'filters' => ['state' => 'BOOKED'], 'expectedCount' => 1]],
            ['categoryId' => self::EXPENSE_B, 'scope' => ['type' => 'FILTER', 'filters' => [], 'expectedCount' => '1']],
            ['categoryId' => 'nope', 'scope' => ['type' => 'IDS', 'ids' => [self::UNKNOWN]]],
            ['categoryId' => self::EXPENSE_B, 'scope' => ['type' => 'IDS', 'ids' => [self::UNKNOWN]], 'extra' => true],
        ] as $index => $body) {
            $this->client->request('POST', '/api/v1/transactions/bulk-category', server: [...self::jsonHeaders(), 'HTTP_IDEMPOTENCY_KEY' => 'bulk-malformed-0000'.$index], content: json_encode($body, JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, 'case '.$index);
        }
    }

    public function testTheMutationRequiresCsrfAndASession(): void
    {
        $id = $this->expense('-10.00', self::EXPENSE_A);

        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');
        $this->bulk(self::EXPENSE_B, $this->ids([$id]), 'bulk-csrf-0000000001');
        self::assertResponseStatusCodeSame(403);
        self::assertSame([self::EXPENSE_A], $this->categoriesOf($id));

        $this->client->restart();
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->bulk(self::EXPENSE_B, $this->ids([$id]), 'bulk-anonymous-000001');
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, mixed>
     */
    private function ids(array $ids): array
    {
        return ['type' => 'IDS', 'ids' => $ids];
    }

    /** @param array<string, mixed> $scope */
    private function bulk(string $categoryId, array $scope, ?string $key): void
    {
        $headers = self::jsonHeaders();
        if (null !== $key) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        $this->client->request('POST', '/api/v1/transactions/bulk-category', server: $headers, content: json_encode(['categoryId' => $categoryId, 'scope' => $scope], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $filters */
    private function bulkFilter(string $categoryId, array $filters, int $expectedCount, string $key): void
    {
        $this->bulk($categoryId, ['type' => 'FILTER', 'filters' => $filters, 'expectedCount' => $expectedCount], $key);
    }

    private function expense(string $amount, ?string $categoryId, string $nature = 'EXPENSE', string $bookedOn = '2026-03-14', string $label = 'CB CARREFOUR 1234'): string
    {
        $this->client->request('POST', '/api/v1/transactions', server: self::jsonHeaders(), content: json_encode([
            'accountId' => self::OWN_ACCOUNT,
            'amount' => ['value' => $amount, 'assetCode' => 'EUR'],
            'nature' => $nature,
            'state' => 'BOOKED',
            'bookedOn' => $bookedOn,
            'valueOn' => null, 'authorizedOn' => null,
            'rawLabel' => $label, 'counterparty' => null, 'note' => null, 'paymentMethod' => 'CARD',
            'mcc' => null, 'maskedCard' => null, 'bankReference' => null,
            'categoryId' => $categoryId, 'splits' => null,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return self::text($this->decode()['id']);
    }

    /** @return list<string> */
    private function categoriesOf(string $transactionId): array
    {
        return array_map(self::text(...), $this->connection->fetchFirstColumn(
            'SELECT category_id FROM transaction_splits WHERE transaction_id = ? ORDER BY position', [$transactionId],
        ));
    }

    /** @return array<string, string> reason per skipped transaction identifier, ordered by identifier */
    private function skippedReasons(): array
    {
        $skipped = $this->decode()['skipped'];
        self::assertIsArray($skipped);
        $reasons = [];
        foreach ($skipped as $row) {
            self::assertIsArray($row);
            $reasons[self::text($row['transactionId'])] = self::text($row['reason']);
        }
        ksort($reasons);

        return $reasons;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private function sortedKeys(array $values): array
    {
        ksort($values);

        return $values;
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function number(mixed $value): int
    {
        self::assertTrue(is_int($value) || is_string($value));

        return (int) $value;
    }

    private function splitEqualsTransactionAmount(string $transactionId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT s.amount_value = t.amount_value FROM transaction_splits s JOIN transaction_transactions t ON t.id = s.transaction_id WHERE s.transaction_id = ?',
            [$transactionId],
        );
    }

    private function versionOf(string $transactionId): int
    {
        return self::number($this->connection->fetchOne('SELECT version FROM transaction_transactions WHERE id = ?', [$transactionId]));
    }

    private function seedForeignTransaction(): void
    {
        $accountId = '00000000-0000-7000-8000-0000000000d9';
        $this->seedAccount($accountId, WorkspaceFixture::OTHER_WORKSPACE, 'Etranger');
        $this->connection->insert('transaction_transactions', [
            'id' => self::FOREIGN_TRANSACTION,
            'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE,
            'account_id' => $accountId,
            'asset_code' => 'EUR',
            'amount_value' => '-10.00',
            'amount_scale' => 2,
            'state' => 'BOOKED',
            'nature' => 'EXPENSE',
            'source' => 'MANUAL',
            'booked_on' => '2026-03-14',
            'raw_label' => 'Secret neighbour label',
            'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
        ]);
    }

    /** @return array<string, string> */
    private static function jsonHeaders(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }

    private function seedAccount(string $id, string $workspace, string $label, string $assetCode = 'EUR'): void
    {
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
            'opened_on' => '2026-01-01',
            'closed_on' => null,
            'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    /** @param list<string> $defaultAnalyticAxes */
    private function seedCategory(
        string $id,
        string $workspace,
        string $type,
        string $label,
        ?string $icon = null,
        ?string $color = null,
        array $defaultAnalyticAxes = [],
    ): void {
        $this->connection->insert('category_categories', [
            'id' => $id,
            'workspace_id' => $workspace,
            'type' => $type,
            'label' => $label,
            'parent_id' => null,
            'icon' => $icon,
            'color' => $color,
            'default_analytic_axes' => json_encode($defaultAnalyticAxes, JSON_THROW_ON_ERROR),
            'budget_included' => true,
            'sort_order' => 0,
            'depth' => 1,
            'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
        ]);
    }

    private function signIn(string $email = WorkspaceFixture::OWNER_EMAIL): void
    {
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: json_encode([
            'email' => $email,
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

    private function resetLoginThrottling(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }
}
