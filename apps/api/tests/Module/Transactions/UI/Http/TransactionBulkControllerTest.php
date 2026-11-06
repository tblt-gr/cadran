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
        self::assertSame(['categoryId'], $result['fieldsChanged']);
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
            "SELECT event_type, entity_type, before_json, after_json FROM audit_events WHERE workspace_id = ? AND event_type LIKE 'transactions.bulk%'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertCount(1, $events);
        $json = json_encode($events, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('42.90', $json);
        self::assertStringNotContainsString('CARREFOUR', $json);
        self::assertStringContainsString('categoryId', $json);
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

    public function testTheBatchAuditRowHoldsOnlyFieldNamesAndCounts(): void
    {
        $changed = $this->expense('-42.90', self::EXPENSE_A, label: 'CB AMAZON PRIME');
        $skipped = $this->expense('50.00', self::INCOME, 'INCOME', label: 'VIREMENT SALAIRE');

        $this->edit(['categoryId' => self::EXPENSE_B, 'note' => 'Secret memo'], $this->ids([$changed, $skipped]), 'bulk-audit-batch-00001');
        self::assertResponseStatusCodeSame(200);

        $row = $this->connection->fetchAssociative(
            "SELECT before_json, after_json FROM audit_events WHERE workspace_id = ? AND event_type = 'transactions.bulk_edited'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertIsArray($row);
        $after = json_decode(self::text($row['after_json']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            ['categoryId' => self::EXPENSE_B, 'changed' => '2', 'fields' => 'note,categoryId', 'requested' => '2', 'scope' => 'IDS', 'skips' => 'SIGN_MISMATCH:1'],
            \is_array($after) ? $this->sortedKeys($after) : null,
        );
        self::assertContains($row['before_json'], ['{}', '[]', null]);
        self::assertStringNotContainsString('Secret memo', self::text($row['after_json']));
        self::assertStringNotContainsString('AMAZON', self::text($row['after_json']));

        $rows = $this->connection->fetchAllAssociative(
            "SELECT entity_id, before_json, after_json FROM audit_events WHERE workspace_id = ? AND event_type = 'transaction.bulk_row_edited'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertEqualsCanonicalizing([$changed, $skipped], array_column($rows, 'entity_id'));
        $json = json_encode($rows, JSON_THROW_ON_ERROR);
        foreach (['Secret memo', 'AMAZON', 'SALAIRE', '42.90', '42,90'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json);
        }
        self::assertStringContainsString('version', $json);
    }

    public function testASameLabelIsTheDisplayLabelOfImportedRowsAndTheBankLabelIsKept(): void
    {
        $ids = [];
        foreach (['PRLV SEPA FREE MOBILE XP1', 'PRLV SEPA FREE MOBILE XP2', 'PRLV SEPA FREE MOBILE XP3'] as $index => $bank) {
            $ids[] = $this->insertRow('IMPORT', $bank, '2026-03-1'.$index, userLabel: 0 === $index ? 'Ancien nom' : null);
        }

        $this->edit(['label' => ['mode' => 'SAME', 'value' => '  Free Mobile  ']], $this->ids($ids), 'bulk-label-same-00001');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(3, $this->decode()['changed']);
        self::assertSame(['label'], $this->decode()['fieldsChanged']);
        foreach ($ids as $index => $id) {
            self::assertSame(['Free Mobile', 'PRLV SEPA FREE MOBILE XP'.($index + 1)], $this->labelsOf($id));
            self::assertSame(2, $this->versionOf($id));
        }

        $this->edit(['label' => ['mode' => 'SAME', 'value' => '   ']], $this->ids([$ids[0]]), 'bulk-label-clear-00001');
        self::assertSame([null, 'PRLV SEPA FREE MOBILE XP1'], $this->labelsOf($ids[0]));
    }

    public function testALabelOnAManualRowReplacesItsOnlyLabelAndAnEmptyOneIsRefusedForThatRowAlone(): void
    {
        $first = $this->expense('-10.00', self::EXPENSE_A, label: 'CB UN');
        $second = $this->expense('-11.00', self::EXPENSE_A, label: 'CB DEUX');
        $this->connection->update('transaction_transactions', ['user_label' => 'Reste'], ['id' => $first]);

        $this->edit(['label' => ['mode' => 'EACH', 'values' => [
            ['id' => $first, 'value' => 'Premier'],
            ['id' => $second, 'value' => ''],
        ]]], $this->ids([$first, $second]), 'bulk-label-each-00001');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->decode()['changed']);
        self::assertSame([$second => 'LABEL_REQUIRED'], $this->skippedReasons());
        self::assertSame([null, 'Premier'], $this->labelsOf($first));
        self::assertSame([null, 'CB DEUX'], $this->labelsOf($second));
        self::assertSame(1, $this->versionOf($second));
    }

    public function testEachLabelGivesEveryExternalRowItsOwnValue(): void
    {
        $first = $this->insertRow('IMPORT', 'BANQUE A', '2026-03-10');
        $second = $this->insertRow('PROVIDER', 'BANQUE B', '2026-03-11');

        $this->edit(['label' => ['mode' => 'EACH', 'values' => [
            ['id' => $second, 'value' => 'Deux'],
            ['id' => $first, 'value' => 'Un'],
        ]]], $this->ids([$first, $second]), 'bulk-label-each-00002');

        self::assertSame(2, $this->decode()['changed']);
        self::assertSame(['Un', 'BANQUE A'], $this->labelsOf($first));
        self::assertSame(['Deux', 'BANQUE B'], $this->labelsOf($second));
    }

    public function testPaymentMethodAloneChangesEligibleRowsAndLeavesEverythingElseUntouched(): void
    {
        $ids = [];
        foreach (['-1.00', '-0.0001', '-9999999999999999999999.9999', '-3.50'] as $amount) {
            $ids[] = $this->expense($amount, self::EXPENSE_A, label: 'CB '.$amount);
        }
        $closed = $this->expense('-7.00', self::EXPENSE_A, bookedOn: '2026-02-10');
        $this->closeMonthInDatabase($this->connection, 2026, 2);

        $this->edit(['paymentMethod' => 'CASH'], $this->ids([...$ids, $closed]), 'bulk-payment-method-01');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(4, $this->decode()['changed']);
        self::assertSame(['paymentMethod'], $this->decode()['fieldsChanged']);
        self::assertSame([$closed => 'CLOSED_PERIOD'], $this->skippedReasons());
        foreach ($ids as $id) {
            self::assertSame('CASH', $this->column($id, 'payment_method'));
            self::assertSame([self::EXPENSE_A], $this->categoriesOf($id));
            self::assertTrue($this->splitEqualsTransactionAmount($id));
        }
        self::assertTrue((bool) $this->connection->fetchOne('SELECT amount_value = -9999999999999999999999.9999 FROM transaction_transactions WHERE id = ?', [$ids[2]]));
        self::assertSame('CARD', $this->column($closed, 'payment_method'));
        self::assertSame(1, $this->versionOf($closed));

        $this->edit(['paymentMethod' => null], $this->ids([$ids[0]]), 'bulk-payment-method-02');
        self::assertNull($this->column($ids[0], 'payment_method'));
    }

    public function testNoteAndCounterpartyAreSetTrimmedAndClearedByEmptyOrNull(): void
    {
        $first = $this->expense('-10.00', self::EXPENSE_A);
        $second = $this->expense('-11.00', self::EXPENSE_A);

        $this->edit(['note' => ' A noter ', 'counterparty' => ' Free '], $this->ids([$first, $second]), 'bulk-note-counterparty-1');
        self::assertSame(['note', 'counterparty'], $this->decode()['fieldsChanged']);
        self::assertSame('A noter', $this->column($first, 'note'));
        self::assertSame('Free', $this->column($second, 'counterparty'));
        self::assertSame('CB CARREFOUR 1234', $this->column($first, 'raw_label'));

        $this->edit(['note' => ''], $this->ids([$first, $second]), 'bulk-note-clear-0000001');
        self::assertNull($this->column($first, 'note'));
        self::assertSame('Free', $this->column($first, 'counterparty'));

        $this->edit(['counterparty' => null], $this->ids([$first]), 'bulk-counterparty-clear-1');
        self::assertNull($this->column($first, 'counterparty'));
        self::assertSame('Free', $this->column($second, 'counterparty'));
    }

    public function testEveryFieldTogetherIsAppliedToARowInOneVersion(): void
    {
        $id = $this->expense('-42.90', null, label: 'CB AMAZON');

        $this->edit([
            'label' => ['mode' => 'SAME', 'value' => 'Amazon'], 'paymentMethod' => 'TRANSFER',
            'note' => 'n', 'counterparty' => 'c', 'categoryId' => self::EXPENSE_B,
        ], $this->ids([$id]), 'bulk-everything-000001');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['label', 'paymentMethod', 'note', 'counterparty', 'categoryId'], $this->decode()['fieldsChanged']);
        self::assertSame([null, 'Amazon'], $this->labelsOf($id));
        self::assertSame('TRANSFER', $this->column($id, 'payment_method'));
        self::assertSame([self::EXPENSE_B], $this->categoriesOf($id));
        self::assertTrue($this->splitEqualsTransactionAmount($id));
        self::assertSame(2, $this->versionOf($id));
    }

    public function testACategoryRefusalAloneKeepsTheOtherFieldsWrittenAndReportsTheReason(): void
    {
        $wrongSign = $this->expense('1200.00', self::INCOME, 'INCOME');
        $already = $this->expense('-5.00', self::EXPENSE_B);

        $this->edit(['note' => 'ok', 'categoryId' => self::EXPENSE_B], $this->ids([$wrongSign, $already]), 'bulk-category-only-skip');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->decode()['changed']);
        self::assertSame(['note'], $this->decode()['fieldsChanged']);
        $expected = [$wrongSign => 'SIGN_MISMATCH', $already => 'ALREADY_IN_CATEGORY'];
        ksort($expected);
        self::assertSame($expected, $this->skippedReasons());
        self::assertSame('ok', $this->column($wrongSign, 'note'));
        self::assertSame([self::INCOME], $this->categoriesOf($wrongSign));
        self::assertSame(2, $this->versionOf($wrongSign));

        $this->edit(['note' => 'ok', 'categoryId' => self::EXPENSE_B], $this->ids([$wrongSign]), 'bulk-category-only-skip2');
        self::assertSame(1, $this->decode()['changed']);
        self::assertSame(['note'], $this->decode()['fieldsChanged']);
    }

    public function testTransferLegsRefundsAndOriginalsAreHandledLikeASingleEdit(): void
    {
        $original = $this->expense('-80.00', self::EXPENSE_A);
        $this->client->request('POST', '/api/v1/transactions/'.$original.'/refunds', server: self::jsonHeaders(), content: json_encode([
            'accountId' => self::OWN_ACCOUNT, 'amount' => ['value' => '30.00', 'assetCode' => 'EUR'],
            'bookedOn' => '2026-03-14', 'rawLabel' => 'Remboursement', 'counterparty' => null, 'note' => null,
        ], JSON_THROW_ON_ERROR));
        $refund = self::text($this->decode()['id']);
        $this->client->request('POST', '/api/v1/transfers', server: self::jsonHeaders(), content: json_encode([
            'sourceAccountId' => self::OWN_ACCOUNT, 'targetAccountId' => self::SAVINGS_ACCOUNT,
            'sourceAmount' => ['value' => '100.00', 'assetCode' => 'EUR'], 'targetAmount' => ['value' => '100.00', 'assetCode' => 'EUR'],
            'state' => 'BOOKED', 'bookedOn' => '2026-03-14', 'valueOn' => null, 'label' => 'Epargne', 'note' => null, 'fee' => null,
        ], JSON_THROW_ON_ERROR));
        $leg = self::text($this->connection->fetchOne(
            'SELECT source_transaction_id FROM transaction_transfers WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE],
        ));
        $voided = $this->expense('-6.00', self::EXPENSE_A);
        $this->client->request('POST', '/api/v1/transactions/'.$voided.'/void', server: self::jsonHeaders(), content: '{"version":1}');

        $this->edit(['note' => 'nouveau'], $this->ids([$original, $refund, $leg, $voided]), 'bulk-single-edit-rules');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->decode()['changed']);
        $expected = [$leg => 'TRANSFER_LEG', $original => 'HAS_REFUNDS', $voided => 'TERMINAL'];
        ksort($expected);
        self::assertSame($expected, $this->skippedReasons());
        self::assertSame('nouveau', $this->column($refund, 'note'));
        self::assertNull($this->column($leg, 'note'));
        self::assertNull($this->column($original, 'note'));
    }

    public function testAPaymentMethodChangeIsWrittenOnARefundRowAndItsAmountAndSplitsStayUntouched(): void
    {
        $original = $this->expense('-80.00', self::EXPENSE_A);
        $this->client->request('POST', '/api/v1/transactions/'.$original.'/refunds', server: self::jsonHeaders(), content: json_encode([
            'accountId' => self::OWN_ACCOUNT, 'amount' => ['value' => '30.00', 'assetCode' => 'EUR'],
            'bookedOn' => '2026-03-14', 'rawLabel' => 'Remboursement', 'counterparty' => null, 'note' => null,
        ], JSON_THROW_ON_ERROR));
        $refund = self::text($this->decode()['id']);
        $categories = $this->categoriesOf($refund);

        $this->edit(['paymentMethod' => 'CASH'], $this->ids([$refund]), 'bulk-refund-payment-001');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->decode()['changed']);
        self::assertSame([], $this->decode()['skipped']);
        self::assertSame('CASH', $this->column($refund, 'payment_method'));
        self::assertTrue((bool) $this->connection->fetchOne('SELECT amount_value = 30.00 FROM transaction_transactions WHERE id = ?', [$refund]));
        self::assertSame($categories, $this->categoriesOf($refund));
        self::assertTrue($this->splitEqualsTransactionAmount($refund));
    }

    public function testABulkEditSpanningManyMonthsSucceedsAndItsReplayReturnsTheSameResult(): void
    {
        $ids = [];
        for ($month = 1; $month <= 10; ++$month) {
            $ids[] = $this->expense('-10.00', self::EXPENSE_A, bookedOn: sprintf('2026-%02d-15', $month));
        }

        $this->edit(['note' => 'sur dix mois'], $this->ids($ids), 'bulk-many-months-0001');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(10, $this->decode()['changed']);
        $first = $this->decode();

        $this->edit(['note' => 'sur dix mois'], $this->ids($ids), 'bulk-many-months-0001');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertEquals($first, $this->decode());
    }

    public function testAFilterScopeEditsTheMatchingSetOnly(): void
    {
        $match = $this->expense('-10.00', self::EXPENSE_A, label: 'CB AMAZON PRIME');
        $other = $this->expense('-11.00', self::EXPENSE_A, label: 'CB CARREFOUR');

        $this->editFilter(['note' => 'amazon'], ['q' => 'amazon'], 2, 'bulk-edit-filter-stale');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->decode()['actualCount']);

        $this->editFilter(['label' => ['mode' => 'SAME', 'value' => 'Amazon']], ['q' => 'amazon'], 1, 'bulk-edit-filter-exact');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([null, 'Amazon'], $this->labelsOf($match));
        self::assertSame([null, 'CB CARREFOUR'], $this->labelsOf($other));
    }

    public function testALostOptimisticUpdateAbortsTheWholeOperationWithAConflict(): void
    {
        $first = $this->expense('-10.00', self::EXPENSE_A);
        $second = $this->expense('-11.00', self::EXPENSE_A);
        [$low, $high] = $first < $second ? [$first, $second] : [$second, $first];

        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $hooked = new BeforeLockTransactionRepository(new DbalTransactionRepository($connection));
        $hooked->staleId = $high;
        self::getContainer()->set(DbalTransactionRepository::class, $hooked);

        $this->edit(['note' => 'x'], $this->ids([$first, $second]), 'bulk-stale-version-001');

        self::assertResponseStatusCodeSame(409);
        self::assertNull($this->column($low, 'note'));
        self::assertSame(1, $this->versionOf($low));
        self::assertSame(0, self::number($this->connection->fetchOne("SELECT count(*) FROM audit_events WHERE event_type = 'transactions.bulk_edited'")));
    }

    public function testAForeignTransactionIsNeverEditedNorDisclosed(): void
    {
        $own = $this->expense('-10.00', self::EXPENSE_A);
        $this->seedForeignTransaction();

        $this->edit(['label' => ['mode' => 'SAME', 'value' => 'Pirate'], 'note' => 'x'], $this->ids([$own, self::FOREIGN_TRANSACTION]), 'bulk-foreign-edit-0001');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->decode()['changed']);
        self::assertSame([self::FOREIGN_TRANSACTION => 'NOT_FOUND'], $this->skippedReasons());
        self::assertSame([null, 'Secret neighbour label'], $this->labelsOf(self::FOREIGN_TRANSACTION));
        self::assertSame(1, $this->versionOf(self::FOREIGN_TRANSACTION));
    }

    public function testASecondWorkspaceCannotReplayTheOwnerResult(): void
    {
        $id = $this->expense('-10.00', self::EXPENSE_A);
        $this->edit(['note' => 'x'], $this->ids([$id]), 'bulk-isolation-key-001');
        self::assertResponseStatusCodeSame(200);

        $this->client->restart();
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $this->edit(['note' => 'x'], $this->ids([$id]), 'bulk-isolation-key-001');

        self::assertResponseStatusCodeSame(200);
        self::assertNotSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertSame([$id => 'NOT_FOUND'], $this->skippedReasons());
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

    public function testTheCountEndpointAppliesAbsoluteBoundsDirectionAndAmountSearch(): void
    {
        $this->expense('-42.10', self::EXPENSE_A);
        $this->expense('-60.00', self::EXPENSE_A);
        $this->expense('-10.50', null, label: 'CB BOULANGERIE');
        $this->seedForeignTransaction();

        $this->client->request('GET', '/api/v1/transactions/count?minAmount=10&maxAmount=50&assetCode=EUR');
        self::assertSame(['count' => 2], $this->decode());

        $this->client->request('GET', '/api/v1/transactions/count?minAmount=10&maxAmount=50&assetCode=EUR&direction=INFLOW');
        self::assertSame(['count' => 0], $this->decode());

        $this->client->request('GET', '/api/v1/transactions/count?minAmount=10&maxAmount=50&assetCode=EUR&direction=OUTFLOW');
        self::assertSame(['count' => 2], $this->decode());

        $this->client->request('GET', '/api/v1/transactions/count?q=10,50%20%E2%82%AC');
        self::assertSame(['count' => 1], $this->decode());

        foreach (['?minAmount=-10&assetCode=EUR', '?direction=SIDEWAYS', '?categoryType=SAVINGS'] as $query) {
            $this->client->request('GET', '/api/v1/transactions/count'.$query);
            self::assertResponseStatusCodeSame(400, $query);
        }
    }

    public function testAFilterScopeResolvesAbsoluteBoundsDirectionAndAmountSearchLikeTheListing(): void
    {
        $inBounds = $this->expense('-42.10', self::EXPENSE_A);
        $tooBig = $this->expense('-60.00', self::EXPENSE_A);
        $byAmount = $this->expense('-10.50', self::EXPENSE_A, label: 'CB BOULANGERIE');

        $this->bulkFilter(self::EXPENSE_B, ['minAmount' => '10', 'maxAmount' => '50', 'assetCode' => 'EUR', 'direction' => 'OUTFLOW'], 2, 'bulk-filter-bounds-1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::EXPENSE_B], $this->categoriesOf($inBounds));
        self::assertSame([self::EXPENSE_B], $this->categoriesOf($byAmount));
        self::assertSame([self::EXPENSE_A], $this->categoriesOf($tooBig));

        $this->bulkFilter(self::EXPENSE_C, ['q' => '-60'], 1, 'bulk-filter-qamount-1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::EXPENSE_C], $this->categoriesOf($tooBig));
        self::assertSame([self::EXPENSE_B], $this->categoriesOf($inBounds));

        foreach ([['minAmount' => '-10', 'assetCode' => 'EUR'], ['direction' => 'SIDEWAYS']] as $index => $filters) {
            $this->bulkFilter(self::EXPENSE_B, $filters, 0, 'bulk-filter-refused-'.$index);
            self::assertResponseStatusCodeSame(422);
        }
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

        self::assertResponseStatusCodeSame(404);
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

    public function testMalformedBodiesAreValidationErrors(): void
    {
        $scope = ['type' => 'IDS', 'ids' => [self::UNKNOWN]];
        $note = ['note' => 'x'];
        $each = static fn (array $values): array => ['label' => ['mode' => 'EACH', 'values' => $values]];
        $other = '00000000-0000-7000-8000-0000000000e8';
        foreach ([
            ['changes' => $note, 'scope' => ['type' => 'IDS', 'ids' => []]],
            ['changes' => $note, 'scope' => ['type' => 'IDS', 'ids' => ['nope']]],
            ['changes' => $note, 'scope' => ['type' => 'ALL']],
            ['changes' => $note, 'scope' => ['type' => 'FILTER', 'filters' => ['bogus' => 1], 'expectedCount' => 1]],
            ['changes' => $note, 'scope' => ['type' => 'FILTER', 'filters' => ['state' => 'BOOKED'], 'expectedCount' => 1]],
            ['changes' => $note, 'scope' => ['type' => 'FILTER', 'filters' => [], 'expectedCount' => '1']],
            ['changes' => ['categoryId' => 'nope'], 'scope' => $scope],
            ['changes' => $note, 'scope' => $scope, 'extra' => true],
            ['scope' => $scope],
            ['changes' => [], 'scope' => $scope],
            ['changes' => ['version' => 3], 'scope' => $scope],
            ['changes' => ['paymentMethod' => 'BITCOIN'], 'scope' => $scope],
            ['changes' => ['paymentMethod' => 3], 'scope' => $scope],
            ['changes' => ['note' => 12], 'scope' => $scope],
            ['changes' => ['note' => str_repeat('n', 501)], 'scope' => $scope],
            ['changes' => ['counterparty' => str_repeat('c', 81)], 'scope' => $scope],
            ['changes' => ['label' => ['mode' => 'SAME', 'value' => str_repeat('l', 141)]], 'scope' => $scope],
            ['changes' => ['label' => ['mode' => 'SAME']], 'scope' => $scope],
            ['changes' => ['label' => ['mode' => 'SAME', 'value' => null]], 'scope' => $scope],
            ['changes' => ['label' => ['mode' => 'OTHER', 'value' => 'x']], 'scope' => $scope],
            ['changes' => $each([]), 'scope' => $scope],
            ['changes' => $each([['id' => self::UNKNOWN, 'value' => 'a']]), 'scope' => ['type' => 'FILTER', 'filters' => [], 'expectedCount' => 1]],
            ['changes' => $each([['id' => $other, 'value' => 'a']]), 'scope' => $scope],
            ['changes' => $each([['id' => self::UNKNOWN, 'value' => 'a'], ['id' => $other, 'value' => 'b']]), 'scope' => $scope],
            ['changes' => $each([['id' => self::UNKNOWN, 'value' => 'a'], ['id' => self::UNKNOWN, 'value' => 'b']]), 'scope' => $scope],
            ['changes' => $each([['id' => self::UNKNOWN, 'value' => 'a', 'extra' => 1]]), 'scope' => $scope],
        ] as $index => $body) {
            $this->client->request('POST', '/api/v1/transactions/bulk-edit', server: [...self::jsonHeaders(), 'HTTP_IDEMPOTENCY_KEY' => 'bulk-malformed-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT)], content: json_encode($body, JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, 'case '.$index);
        }
    }

    public function testMoreThanOneHundredLabelValuesAreRefused(): void
    {
        $ids = [];
        $values = [];
        for ($index = 0; $index < 101; ++$index) {
            $id = sprintf('00000000-0000-7000-8000-%012d', 900000 + $index);
            $ids[] = $id;
            $values[] = ['id' => $id, 'value' => 'x'];
        }

        $this->edit(['label' => ['mode' => 'EACH', 'values' => $values]], $this->ids($ids), 'bulk-each-over-100-01');

        self::assertResponseStatusCodeSame(422);
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
        $this->edit(['categoryId' => $categoryId], $scope, $key);
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $scope
     */
    private function edit(array $changes, array $scope, ?string $key): void
    {
        $headers = self::jsonHeaders();
        if (null !== $key) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        $this->client->request('POST', '/api/v1/transactions/bulk-edit', server: $headers, content: json_encode(['changes' => $changes, 'scope' => $scope], JSON_THROW_ON_ERROR));
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

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $filters
     */
    private function editFilter(array $changes, array $filters, int $expectedCount, string $key): void
    {
        $this->edit($changes, ['type' => 'FILTER', 'filters' => $filters, 'expectedCount' => $expectedCount], $key);
    }

    private function insertRow(string $source, string $rawLabel, string $bookedOn, ?string $userLabel = null): string
    {
        $id = sprintf('00000000-0000-7000-8000-%012d', random_int(100000, 899999));
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => self::OWN_ACCOUNT,
            'asset_code' => 'EUR', 'amount_value' => '-12.34', 'amount_scale' => 2, 'state' => 'BOOKED', 'nature' => 'EXPENSE',
            'source' => $source, 'source_ref' => 'ref-'.$id, 'booked_on' => $bookedOn, 'raw_label' => $rawLabel, 'user_label' => $userLabel,
            'payment_method' => 'CARD', 'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ]);

        return $id;
    }

    /** @return array{0: ?string, 1: string} user label, then raw label */
    private function labelsOf(string $transactionId): array
    {
        $row = $this->connection->fetchAssociative('SELECT user_label, raw_label FROM transaction_transactions WHERE id = ?', [$transactionId]);
        self::assertIsArray($row);

        return [null === $row['user_label'] ? null : self::text($row['user_label']), self::text($row['raw_label'])];
    }

    private function column(string $transactionId, string $column): ?string
    {
        $value = $this->connection->fetchOne(sprintf('SELECT %s FROM transaction_transactions WHERE id = ?', $column), [$transactionId]);

        return false === $value || null === $value ? null : self::text(is_scalar($value) ? (string) $value : null);
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
            $reasons[self::text($row['id'])] = self::text($row['reason']);
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
