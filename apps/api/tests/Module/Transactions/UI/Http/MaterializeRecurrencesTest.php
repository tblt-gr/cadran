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
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * TX-016: a recurrence with the setting on creates the current month's
 * transactions itself. The rent of the ticket's worked examples is -850.00 on
 * the 5th of each month, on 2026-11-03 unless a test moves the clock.
 */
final class MaterializeRecurrencesTest extends WebTestCase
{
    use ClosesPeriods;

    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string EXPENSE_CATEGORY = '00000000-0000-7000-8000-0000000000c1';
    private const string INCOME_CATEGORY = '00000000-0000-7000-8000-0000000000c2';
    private const string OTHER_CATEGORY = '00000000-0000-7000-8000-0000000000c3';
    private const string NOVEMBER_THIRD = '2026-11-03T09:00:00+00:00';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private MockClock $clock;
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
        $this->seedAccount(self::OWN_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);
        $this->seedCategory(self::EXPENSE_CATEGORY, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Logement');
        $this->seedCategory(self::INCOME_CATEGORY, WorkspaceFixture::OWN_WORKSPACE, 'INCOME', 'Salaire');
        $this->seedCategory(self::OTHER_CATEGORY, WorkspaceFixture::OTHER_WORKSPACE, 'EXPENSE', 'Voisin');

        $this->client->request('GET', '/api/v1/session');
        $this->signIn();
        $this->clock = new MockClock(new \DateTimeImmutable(self::NOVEMBER_THIRD));
        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->client->disableReboot();
        self::getContainer()->set(ClockInterface::class, $this->clock);
        $this->renewCsrf();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testASaveWithTheSettingOnCreatesAPendingTransactionFromTheInstalment(): void
    {
        $recurrence = $this->createRecurrence(['autoCreate' => true]);

        self::assertTrue($recurrence['autoCreate']);
        $transactions = $this->transactions();
        self::assertCount(1, $transactions);
        self::assertSame('PENDING', $transactions[0]['state']);
        self::assertSame('EXPENSE', $transactions[0]['nature']);
        self::assertSame('-850.00', $transactions[0]['amount']);
        self::assertSame('2026-11-05', $transactions[0]['booked_on']);
        self::assertSame('RECURRENCE', $transactions[0]['source']);
        self::assertSame(1, (int) $transactions[0]['version']);
        $instalment = $this->instalment('2026-11-05');
        self::assertSame($transactions[0]['source_ref'], $instalment['id']);
        self::assertSame($transactions[0]['id'], $instalment['generated_transaction_id']);
        self::assertSame(1, (int) $instalment['generated_version']);
        self::assertSame('EXPECTED', $instalment['status']);
        self::assertNull($this->instalment('2026-12-05')['generated_transaction_id']);
    }

    public function testARerunCreatesAndBooksNothing(): void
    {
        $this->createRecurrence(['autoCreate' => true]);

        $first = $this->materialize();
        $second = $this->materialize();

        self::assertSame(0, $first['transactionsCreated']);
        self::assertSame(0, $second['transactionsCreated']);
        self::assertSame(0, $second['transactionsBooked']);
        self::assertSame(1, $second['recurrencesExamined']);
        self::assertSame(['2026-11-01', '2026-11-30'], [$second['monthStart'], $second['monthEnd']]);
        self::assertCount(1, $this->transactions());
    }

    public function testADuePendingTransactionIsBookedAndSettlesItsInstalment(): void
    {
        $this->createRecurrence(['autoCreate' => true]);
        $this->travel('2026-11-05T08:00:00+00:00');

        $result = $this->materialize();

        self::assertSame(0, $result['transactionsCreated']);
        self::assertSame(1, $result['transactionsBooked']);
        $transaction = $this->transactions()[0];
        self::assertSame('BOOKED', $transaction['state']);
        self::assertSame(2, (int) $transaction['version']);
        $instalment = $this->instalment('2026-11-05');
        self::assertSame('RECEIVED', $instalment['status']);
        self::assertSame($transaction['id'], $instalment['matched_transaction_id']);
        self::assertSame(['transactionsCreated' => 0, 'transactionsBooked' => 0], $this->counts($this->materialize()));
    }

    public function testAFirstRunAfterTheDateCreatesTheTransactionDirectlyBooked(): void
    {
        $this->travel('2026-11-20T08:00:00+00:00');
        $this->createRecurrence();
        $this->switchAutoCreateOn();

        $result = $this->materialize();

        self::assertSame(1, $result['transactionsCreated']);
        self::assertSame(0, $result['transactionsBooked']);
        $transaction = $this->transactions()[0];
        self::assertSame('BOOKED', $transaction['state']);
        self::assertSame('2026-11-05', $transaction['booked_on']);
        self::assertSame('RECEIVED', $this->instalment('2026-11-05')['status']);
    }

    public function testTheNextMonthIsCreatedOnTheFirstRunOfThatMonthAndNothingBeyond(): void
    {
        $this->createRecurrence(['autoCreate' => true]);
        $this->travel('2026-12-01T00:30:00+00:00');

        $result = $this->materialize();

        self::assertSame(1, $result['transactionsCreated']);
        $dates = array_map(static fn (array $row): string => $row['booked_on'], $this->transactions());
        self::assertSame(['2026-11-05', '2026-12-05'], $dates);
        self::assertSame('PENDING', $this->transactions()[1]['state']);
        self::assertNull($this->instalment('2027-01-05')['generated_transaction_id']);
    }

    public function testAWeeklyRecurrenceCreatesEveryMondayOfTheMonth(): void
    {
        $this->createRecurrence([
            'intervalKind' => 'WEEKLY', 'dayOfPeriod' => 1, 'expectedAmount' => '-12.50',
            'firstExpectedOn' => '2026-11-02', 'label' => 'Courses',
        ]);
        $this->switchAutoCreateOn();

        $result = $this->materialize();

        self::assertSame(5, $result['transactionsCreated']);
        $rows = $this->transactions();
        self::assertSame(
            ['2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23', '2026-11-30'],
            array_map(static fn (array $row): string => $row['booked_on'], $rows),
        );
        self::assertSame(['BOOKED', 'PENDING', 'PENDING', 'PENDING', 'PENDING'], array_map(static fn (array $row): string => $row['state'], $rows));
    }

    public function testEditingRewritesAnUntouchedPendingTransactionFromTheEditedSchedule(): void
    {
        $recurrence = $this->createRecurrence(['autoCreate' => true]);
        $original = $this->transactions()[0];

        $this->requestUpdate($recurrence['id'], [...$this->updateBody($recurrence), 'expectedAmount' => '-870.00']);
        self::assertResponseIsSuccessful();

        $rows = $this->transactions();
        self::assertCount(2, $rows);
        self::assertSame(['REJECTED', $original['id']], [$rows[0]['state'], $rows[0]['id']]);
        self::assertSame(['PENDING', '-870.00', '2026-11-05'], [$rows[1]['state'], $rows[1]['amount'], $rows[1]['booked_on']]);
        self::assertSame($rows[1]['id'], $this->instalment('2026-11-05')['generated_transaction_id']);
    }

    public function testEditingLeavesATouchedTransactionAndItsInstalmentAlone(): void
    {
        $recurrence = $this->createRecurrence(['autoCreate' => true]);
        $original = $this->transactions()[0];
        $this->putTransaction($original['id'], ['note' => 'retouché']);
        self::assertResponseStatusCodeSame(200);

        $this->requestUpdate($recurrence['id'], [...$this->updateBody($recurrence), 'expectedAmount' => '-870.00']);
        self::assertResponseIsSuccessful();

        $rows = $this->transactions();
        self::assertCount(1, $rows);
        self::assertSame(['PENDING', '-850.00'], [$rows[0]['state'], $rows[0]['amount']]);
        self::assertSame($original['id'], $this->instalment('2026-11-05')['generated_transaction_id']);
    }

    public function testEditingNeverRewritesABookedTransaction(): void
    {
        $recurrence = $this->createRecurrence(['autoCreate' => true]);
        $this->travel('2026-11-05T08:00:00+00:00');
        $this->materialize();
        $recurrence = $this->readRecurrence($recurrence['id']);

        $this->requestUpdate($recurrence['id'], [...$this->updateBody($recurrence), 'expectedAmount' => '-870.00']);
        self::assertResponseIsSuccessful();

        $rows = $this->transactions();
        self::assertCount(1, $rows);
        self::assertSame(['BOOKED', '-850.00'], [$rows[0]['state'], $rows[0]['amount']]);
    }

    public function testSwitchingTheSettingOffRejectsUntouchedPendingRowsAndKeepsBookedOnes(): void
    {
        $recurrence = $this->createRecurrence(['autoCreate' => true]);
        $this->travel('2026-12-01T00:30:00+00:00');
        $this->materialize();
        $this->connection->executeStatement("UPDATE transaction_transactions SET state = 'BOOKED', version = 2 WHERE booked_on = '2026-11-05'");
        $recurrence = $this->readRecurrence($recurrence['id']);

        $this->requestUpdate($recurrence['id'], [...$this->updateBody($recurrence), 'autoCreate' => false]);
        self::assertResponseIsSuccessful();

        self::assertSame(['BOOKED', 'REJECTED'], array_map(static fn (array $row): string => $row['state'], $this->transactions()));
        self::assertSame(0, $this->materialize()['recurrencesExamined']);
    }

    public function testArchivingRejectsUntouchedPendingRowsAndCreatesNothingMore(): void
    {
        $recurrence = $this->createRecurrence(['autoCreate' => true]);

        $this->requestArchive($recurrence['id'], $recurrence['version']);
        self::assertResponseIsSuccessful();
        $this->travel('2026-12-01T00:30:00+00:00');
        $result = $this->materialize();

        self::assertSame(['REJECTED'], array_map(static fn (array $row): string => $row['state'], $this->transactions()));
        self::assertSame(0, $result['recurrencesExamined']);
        self::assertSame(0, $result['transactionsCreated']);
    }

    public function testRestoringAnArchivedRecurrenceCreatesItsTransactionsAgain(): void
    {
        $recurrence = $this->createRecurrence(['autoCreate' => true]);
        $this->requestArchive($recurrence['id'], $recurrence['version']);
        $archived = $this->decode();

        $this->client->request('POST', '/api/v1/recurrences/'.$recurrence['id'].'/restore', server: self::jsonHeaders(), content: json_encode(['version' => $archived['version']], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertSame(['REJECTED', 'PENDING'], array_map(static fn (array $row): string => $row['state'], $this->transactions()));
    }

    public function testAVoidedGeneratedTransactionIsNeverCreatedAgain(): void
    {
        $this->createRecurrence(['autoCreate' => true]);
        $transaction = $this->transactions()[0];
        $this->client->request('POST', '/api/v1/transactions/'.$transaction['id'].'/void', server: self::jsonHeaders(), content: json_encode(['version' => 1], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $result = $this->materialize();

        self::assertSame(0, $result['transactionsCreated']);
        self::assertCount(1, $this->transactions());
    }

    public function testAnInstalmentInAClosedPeriodIsSkippedAndCounted(): void
    {
        $this->createRecurrence();
        $this->switchAutoCreateOn();
        $this->closeMonthInDatabase($this->connection, 2026, 11);

        $result = $this->materialize();

        self::assertSame(1, $result['skippedClosedPeriod']);
        self::assertSame(0, $result['transactionsCreated']);
        self::assertSame([], $this->transactions());
    }

    public function testAnArchivedAccountRefusesTheInstalment(): void
    {
        $this->createRecurrence();
        $this->switchAutoCreateOn();
        $this->connection->executeStatement("UPDATE account_financial_accounts SET archived_at = '2026-11-01 10:00:00+00' WHERE id = ?", [self::OWN_ACCOUNT]);

        $result = $this->materialize();

        self::assertSame(1, $result['skippedRefused']);
        self::assertSame([], $this->transactions());
    }

    public function testASettingLeftOffCreatesNothing(): void
    {
        $this->createRecurrence();

        $result = $this->materialize();

        self::assertSame(0, $result['recurrencesExamined']);
        self::assertSame([], $this->transactions());
    }

    public function testTheCategoryNoteAndPaymentMethodAreCarriedOntoTheTransaction(): void
    {
        $this->createRecurrence([
            'autoCreate' => true, 'categoryId' => self::EXPENSE_CATEGORY, 'note' => 'Loyer appartement',
            'paymentMethod' => 'DIRECT_DEBIT',
        ]);

        $transaction = $this->transactions()[0];

        self::assertSame('Loyer appartement', $transaction['note']);
        self::assertSame('DIRECT_DEBIT', $transaction['payment_method']);
        self::assertSame('Loyer', $transaction['raw_label']);
        $splits = $this->connection->fetchAllAssociative('SELECT category_id, round(amount_value, amount_scale::int)::text AS amount_value FROM transaction_splits WHERE transaction_id = ?', [$transaction['id']]);
        self::assertCount(1, $splits);
        self::assertSame(self::EXPENSE_CATEGORY, $splits[0]['category_id']);
        self::assertSame('-850.00', $splits[0]['amount_value']);
    }

    public function testACategoryOfTheWrongTypeIsRefusedOnSave(): void
    {
        $this->requestCreate([...$this->payload(), 'categoryId' => self::INCOME_CATEGORY]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->recurrenceCount());
    }

    public function testACategoryOfAnotherWorkspaceIsRefusedOnSave(): void
    {
        $this->requestCreate([...$this->payload(), 'categoryId' => self::OTHER_CATEGORY]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->recurrenceCount());
    }

    public function testACategoryArchivedAfterTheSaveMakesTheNextInstalmentRefused(): void
    {
        $this->createRecurrence(['autoCreate' => true, 'categoryId' => self::EXPENSE_CATEGORY]);
        $this->connection->executeStatement("UPDATE category_categories SET archived_at = '2026-11-04 10:00:00+00' WHERE id = ?", [self::EXPENSE_CATEGORY]);
        $this->travel('2026-12-01T00:30:00+00:00');

        $result = $this->materialize();

        self::assertSame(1, $result['skippedRefused']);
        self::assertSame(0, $result['transactionsCreated']);
        self::assertCount(1, $this->transactions());
    }

    public function testTheInstalmentScaleIsKeptAndAPositiveAmountIsIncome(): void
    {
        $this->createRecurrence(['autoCreate' => true, 'expectedAmount' => '-850.10', 'label' => 'Loyer']);
        $this->createRecurrence(['autoCreate' => true, 'expectedAmount' => '1200.00', 'label' => 'Salaire']);

        $rows = $this->transactions();
        $byLabel = array_column($rows, null, 'raw_label');

        self::assertSame(['-850.10', 'EXPENSE'], [$byLabel['Loyer']['amount'], $byLabel['Loyer']['nature']]);
        self::assertSame(['1200.00', 'INCOME'], [$byLabel['Salaire']['amount'], $byLabel['Salaire']['nature']]);
    }

    public function testATransactionAlreadyHoldingTheInstalmentIdentifierIsLinkedWithoutBeingCountedTwice(): void
    {
        $recurrence = $this->createRecurrence();
        $instalment = $this->instalment('2026-11-05');
        // What a concurrent run that lost the race leaves behind: the row exists, the link does not.
        $this->connection->executeStatement(
            'INSERT INTO transaction_transactions (id, workspace_id, account_id, amount_value, amount_scale, asset_code, state, nature, source, source_ref, booked_on, raw_label, version, created_at, updated_at) '
            ."VALUES ('00000000-0000-7000-8000-0000000000f1', ?, ?, -850, 2, 'EUR', 'PENDING', 'EXPENSE', 'RECURRENCE', ?, '2026-11-05', 'Loyer', 1, now(), now())",
            [WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, $instalment['id']],
        );
        $this->switchAutoCreateOn();

        $result = $this->materialize();

        self::assertSame(0, $result['transactionsCreated']);
        self::assertSame('00000000-0000-7000-8000-0000000000f1', $this->instalment('2026-11-05')['generated_transaction_id']);
        self::assertCount(1, $this->transactions());
        self::assertNotSame('', $recurrence['id']);
    }

    public function testARunInOneWorkspaceNeverTouchesTheOtherOne(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO transaction_recurrences (id, workspace_id, account_id, label, counterparty, expected_amount_value, expected_amount_scale, asset_code, amount_tolerance_value, amount_tolerance_scale, interval_kind, day_of_period, next_expected_on, confirmed_at, version, created_at, updated_at, auto_create) '
            ."VALUES ('00000000-0000-7000-8000-0000000000e9', ?, ?, 'Voisin', NULL, -10, 2, 'EUR', 0, 2, 'MONTHLY', 5, '2026-11-05', now(), 1, now(), now(), true)",
            [WorkspaceFixture::OTHER_WORKSPACE, self::OTHER_ACCOUNT],
        );
        $this->connection->executeStatement(
            "INSERT INTO transaction_recurrence_occurrences (id, workspace_id, recurrence_id, expected_on, expected_amount_value, expected_amount_scale, amount_tolerance_value, amount_tolerance_scale, status) VALUES ('00000000-0000-7000-8000-0000000000e8', ?, '00000000-0000-7000-8000-0000000000e9', '2026-11-05', -10, 2, 0, 2, 'EXPECTED')",
            [WorkspaceFixture::OTHER_WORKSPACE],
        );
        $this->createRecurrence(['autoCreate' => true]);

        $result = $this->materialize();

        self::assertSame(1, $result['recurrencesExamined']);
        self::assertSame(0, $this->scalarCount('SELECT count(*) FROM transaction_transactions WHERE workspace_id = ?', [WorkspaceFixture::OTHER_WORKSPACE]));
        self::assertNull($this->connection->fetchOne("SELECT generated_transaction_id FROM transaction_recurrence_occurrences WHERE id = '00000000-0000-7000-8000-0000000000e8'"));
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->client->getCookieJar()->clear();
        $this->renewCsrf();

        $this->client->request('POST', '/api/v1/recurrences/materialize', server: self::jsonHeaders(), content: '{}');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAMutationWithoutACsrfTokenIsRefused(): void
    {
        $this->createRecurrence();
        $this->switchAutoCreateOn();
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');

        $this->client->request('POST', '/api/v1/recurrences/materialize', server: self::jsonHeaders(), content: '{}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->transactions());
    }

    public function testTheAuditTrailAndTheResultCarryCountsOnly(): void
    {
        $this->createRecurrence(['autoCreate' => true, 'note' => 'Loyer appartement']);
        $this->travel('2026-11-05T08:00:00+00:00');
        $result = $this->materialize();

        $events = $this->connection->fetchAllAssociative("SELECT before_json, after_json FROM audit_events WHERE event_type IN ('transaction_recurrence.materialized', 'transaction.updated')");

        self::assertNotSame([], $events);
        $serialised = json_encode([$events, $result], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('850', $serialised);
        self::assertStringNotContainsString('Loyer', $serialised);
    }

    public function testAPlainMovementNoLongerSettlesAnInstalmentThatAlreadyHasAGeneratedTransaction(): void
    {
        $this->createRecurrence(['autoCreate' => true]);

        $this->client->request('POST', '/api/v1/transactions', server: self::jsonHeaders(), content: json_encode([
            'accountId' => self::OWN_ACCOUNT, 'amount' => ['value' => '-850.00', 'assetCode' => 'EUR'], 'nature' => 'EXPENSE',
            'state' => 'BOOKED', 'bookedOn' => '2026-11-03', 'valueOn' => null, 'authorizedOn' => null,
            'rawLabel' => 'VIR LOYER', 'counterparty' => null, 'note' => null, 'paymentMethod' => null,
            'mcc' => null, 'maskedCard' => null, 'bankReference' => null, 'categoryId' => null, 'splits' => null,
            'source' => 'MANUAL',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->instalment('2026-11-05')['matched_transaction_id']);
    }

    public function testEditingABookedGeneratedTransactionKeepsItsInstalmentReceived(): void
    {
        $recurrence = $this->createRecurrence(['autoCreate' => true]);
        $this->travel('2026-11-05T08:00:00+00:00');
        $this->materialize();
        $before = $this->instalment('2026-11-05');
        $pointer = $this->readRecurrence($recurrence['id'])['nextExpectedOn'];

        $this->putTransaction($this->transactions()[0]['id'], ['note' => 'retouché']);

        self::assertResponseStatusCodeSame(200);
        $after = $this->instalment('2026-11-05');
        self::assertSame('RECEIVED', $after['status']);
        self::assertSame($before['matched_transaction_id'], $after['matched_transaction_id']);
        self::assertNotNull($after['matched_transaction_id']);
        self::assertSame($pointer, $this->readRecurrence($recurrence['id'])['nextExpectedOn']);
    }

    public function testTheOwnerCanRejectAFutureDatedPendingGeneratedRow(): void
    {
        $this->createRecurrence(['autoCreate' => true]);
        $original = $this->transactions()[0];

        $this->putTransaction($original['id'], ['state' => 'REJECTED']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('REJECTED', $this->transactions()[0]['state']);
        self::assertSame(0, $this->materialize()['transactionsCreated']);
    }

    public function testAFutureDatedGeneratedRowCanNeverBeBooked(): void
    {
        $this->createRecurrence(['autoCreate' => true]);

        $this->putTransaction($this->transactions()[0]['id'], ['state' => 'BOOKED']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('PENDING', $this->transactions()[0]['state']);
    }

    public function testEditingARecurrenceKeepsItsCategoryEvenOnceArchived(): void
    {
        $recurrence = $this->createRecurrence(['autoCreate' => true, 'categoryId' => self::EXPENSE_CATEGORY]);
        $this->connection->executeStatement("UPDATE category_categories SET archived_at = '2026-11-04 10:00:00+00' WHERE id = ?", [self::EXPENSE_CATEGORY]);

        $this->requestUpdate($recurrence['id'], [...$this->updateBody($recurrence), 'label' => 'Loyer bis']);

        self::assertResponseStatusCodeSame(200);
        $this->travel('2026-12-01T00:30:00+00:00');
        self::assertSame(1, $this->materialize()['skippedRefused']);
        $other = $this->readRecurrence($recurrence['id']);
        $this->requestUpdate($other['id'], [...$this->updateBody($other), 'categoryId' => self::INCOME_CATEGORY]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testARejectedRowHoldingTheInstalmentIdentifierIsNeverAdopted(): void
    {
        $this->createRecurrence();
        $instalment = $this->instalment('2026-11-05');
        $this->connection->executeStatement(
            'INSERT INTO transaction_transactions (id, workspace_id, account_id, amount_value, amount_scale, asset_code, state, nature, source, source_ref, booked_on, raw_label, version, created_at, updated_at) '
            ."VALUES ('00000000-0000-7000-8000-0000000000f2', ?, ?, -850, 2, 'EUR', 'REJECTED', 'EXPENSE', 'RECURRENCE', ?, '2026-11-05', 'Loyer', 2, now(), now())",
            [WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, $instalment['id']],
        );
        $this->switchAutoCreateOn();

        $result = $this->materialize();

        self::assertSame(1, $result['skippedRefused']);
        self::assertNull($this->instalment('2026-11-05')['generated_transaction_id']);
    }

    public function testALaterPeriodStartDropsEarlierInstalmentsAndGeneratesFromIt(): void
    {
        $recurrence = $this->createRecurrence();

        $this->requestUpdate($recurrence['id'], [...$this->updateBody($recurrence), 'firstExpectedOn' => '2027-01-01']);

        self::assertResponseIsSuccessful();
        self::assertSame(['2027-01-05'], array_slice($this->instalmentDays(), 0, 1));
        self::assertNotContains('2026-11-05', $this->instalmentDays());
        self::assertNotContains('2026-12-05', $this->instalmentDays());
    }

    public function testAnEarlierOrSameStartChangesNothing(): void
    {
        $recurrence = $this->createRecurrence();
        $before = $this->instalmentDays();

        foreach (['2026-11-05', '2026-01-01', null] as $start) {
            $this->requestUpdate($recurrence['id'], [...$this->updateBody($recurrence), 'firstExpectedOn' => $start]);
            self::assertResponseIsSuccessful();
            $recurrence = $this->readRecurrence($recurrence['id']);
            self::assertSame($before, $this->instalmentDays());
        }
    }

    public function testAStartBeyondTheHorizonIsRefused(): void
    {
        $recurrence = $this->createRecurrence();

        $this->requestUpdate($recurrence['id'], [...$this->updateBody($recurrence), 'firstExpectedOn' => '2028-01-01']);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('2026-11-05', $this->instalmentDays());
    }

    public function testAMalformedStartIsRefused(): void
    {
        $recurrence = $this->createRecurrence();

        $this->requestUpdate($recurrence['id'], [...$this->updateBody($recurrence), 'firstExpectedOn' => '2026-13-45']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testALaterStartNeverTouchesAMatchedInstalmentOrItsBookedTransaction(): void
    {
        $recurrence = $this->createRecurrence(['autoCreate' => true]);
        $this->travel('2026-11-05T08:00:00+00:00');
        $this->materialize();
        $recurrence = $this->readRecurrence($recurrence['id']);

        $this->requestUpdate($recurrence['id'], [...$this->updateBody($recurrence), 'firstExpectedOn' => '2027-01-01']);

        self::assertResponseIsSuccessful();
        self::assertSame('RECEIVED', $this->instalment('2026-11-05')['status']);
        self::assertSame(['BOOKED'], array_map(static fn (array $row): string => $row['state'], $this->transactions()));
        self::assertNotContains('2026-12-05', $this->instalmentDays());
    }

    /** @return list<string> */
    private function instalmentDays(): array
    {
        return array_map(
            static fn (mixed $day): string => is_string($day) ? $day : '',
            $this->connection->fetchFirstColumn('SELECT expected_on FROM transaction_recurrence_occurrences WHERE workspace_id = ? ORDER BY expected_on', [WorkspaceFixture::OWN_WORKSPACE]),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function putTransaction(string $id, array $overrides): void
    {
        $row = $this->transactions()[0];
        $this->client->request('PUT', '/api/v1/transactions/'.$id, server: self::jsonHeaders(), content: json_encode([
            'accountId' => self::OWN_ACCOUNT, 'amount' => ['value' => $row['amount'], 'assetCode' => 'EUR'],
            'nature' => $row['nature'], 'state' => $row['state'], 'bookedOn' => $row['booked_on'], 'valueOn' => null,
            'authorizedOn' => null, 'rawLabel' => $row['raw_label'], 'counterparty' => null, 'note' => $row['note'],
            'paymentMethod' => null, 'mcc' => null, 'maskedCard' => null, 'bankReference' => null, 'categoryId' => null,
            'splits' => null, 'version' => (int) $row['version'], 'userLabel' => null,
            ...$overrides,
        ], JSON_THROW_ON_ERROR));
    }

    public function testAClientCannotCreateARecurrenceSourcedTransaction(): void
    {
        $this->client->request('POST', '/api/v1/transactions', server: self::jsonHeaders(), content: json_encode([
            'accountId' => self::OWN_ACCOUNT, 'amount' => ['value' => '-1.00', 'assetCode' => 'EUR'], 'nature' => 'EXPENSE',
            'state' => 'BOOKED', 'bookedOn' => '2026-11-03', 'valueOn' => null, 'authorizedOn' => null,
            'rawLabel' => 'X', 'counterparty' => null, 'note' => null, 'paymentMethod' => null,
            'mcc' => null, 'maskedCard' => null, 'bankReference' => null, 'categoryId' => null, 'splits' => null,
            'source' => 'RECURRENCE',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testTwoConcurrentRunsCreateTheTransactionOnce(): void
    {
        $this->createRecurrence();
        $this->switchAutoCreateOn();
        $barrier = sys_get_temp_dir().'/cadran-materialize-'.bin2hex(random_bytes(8));
        $processes = [];
        $pipes = [];

        try {
            $this->connection->beginTransaction();
            $this->connection->fetchOne(
                'SELECT id FROM transaction_recurrences WHERE workspace_id = ? FOR UPDATE',
                [WorkspaceFixture::OWN_WORKSPACE],
            );
            foreach ([0, 1] as $worker) {
                $workerPipes = [];
                $process = proc_open(
                    [PHP_BINARY, 'tests/Support/ConcurrentRecurrenceMaterializationWorker.php', $barrier, (string) $worker],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $workerPipes,
                    dirname(__DIR__, 5),
                    null,
                );
                self::assertIsResource($process);
                $processes[] = $process;
                $pipes[] = $workerPipes;
            }
            $deadline = microtime(true) + 10;
            while (2 !== count(glob($barrier.'.calling.*') ?: []) && microtime(true) < $deadline) {
                usleep(1_000);
            }
            self::assertCount(2, glob($barrier.'.calling.*') ?: []);
            usleep(50_000);
            $this->connection->commit();

            $created = 0;
            foreach ($processes as $index => $process) {
                $stdout = stream_get_contents($pipes[$index][1]);
                $stderr = stream_get_contents($pipes[$index][2]);
                self::assertSame(0, proc_close($process), $stderr);
                $processes[$index] = null;
                /** @var array{outcome: array{created: int, booked: int}|string} $response */
                $response = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($response['outcome'], is_string($response['outcome']) ? $response['outcome'] : '');
                $created += $response['outcome']['created'];
            }
            self::assertSame(1, $created);
            self::assertCount(1, $this->transactions());
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            foreach (glob($barrier.'.*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'accountId' => self::OWN_ACCOUNT, 'label' => 'Loyer', 'counterparty' => null, 'expectedAmount' => '-850.00',
            'amountTolerance' => '0.00', 'intervalKind' => 'MONTHLY', 'dayOfPeriod' => 5, 'firstExpectedOn' => '2026-11-05',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{id: string, version: int, label: string, counterparty: ?string, expectedAmount: array{value: string}, amountTolerance: array{value: string}, intervalKind: string, dayOfPeriod: int, nextExpectedOn: string, autoCreate: bool, categoryId: ?string, note: ?string, paymentMethod: ?string}
     */
    private function createRecurrence(array $overrides = []): array
    {
        $this->requestCreate([...$this->payload(), ...$overrides]);
        self::assertResponseStatusCodeSame(201);

        return $this->recurrenceBody();
    }

    /** @return array{id: string, version: int, label: string, counterparty: ?string, expectedAmount: array{value: string}, amountTolerance: array{value: string}, intervalKind: string, dayOfPeriod: int, nextExpectedOn: string, autoCreate: bool, categoryId: ?string, note: ?string, paymentMethod: ?string} */
    private function recurrenceBody(): array
    {
        /** @var array{id: string, version: int, label: string, counterparty: ?string, expectedAmount: array{value: string}, amountTolerance: array{value: string}, intervalKind: string, dayOfPeriod: int, nextExpectedOn: string, autoCreate: bool, categoryId: ?string, note: ?string, paymentMethod: ?string} $body */
        $body = $this->decode();

        return $body;
    }

    /**
     * @param array{id: string, version: int, label: string, counterparty: ?string, expectedAmount: array{value: string}, amountTolerance: array{value: string}, intervalKind: string, dayOfPeriod: int, nextExpectedOn: string, autoCreate: bool, categoryId: ?string, note: ?string, paymentMethod: ?string} $recurrence
     *
     * @return array<string, mixed>
     */
    private function updateBody(array $recurrence): array
    {
        return [
            'label' => $recurrence['label'], 'counterparty' => $recurrence['counterparty'],
            'expectedAmount' => $recurrence['expectedAmount']['value'], 'amountTolerance' => $recurrence['amountTolerance']['value'],
            'intervalKind' => $recurrence['intervalKind'], 'dayOfPeriod' => $recurrence['dayOfPeriod'],
            'version' => $recurrence['version'], 'autoCreate' => $recurrence['autoCreate'],
            'categoryId' => $recurrence['categoryId'], 'note' => $recurrence['note'], 'paymentMethod' => $recurrence['paymentMethod'],
        ];
    }

    /** @param array<string, mixed> $body */
    private function requestCreate(array $body): void
    {
        $this->client->request('POST', '/api/v1/recurrences', server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $body */
    private function requestUpdate(string $id, array $body): void
    {
        $this->client->request('PUT', '/api/v1/recurrences/'.$id, server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function requestArchive(string $id, int $version): void
    {
        $this->client->request('POST', '/api/v1/recurrences/'.$id.'/archive', server: self::jsonHeaders(), content: json_encode(['version' => $version], JSON_THROW_ON_ERROR));
    }

    /** @return array{id: string, version: int, label: string, counterparty: ?string, expectedAmount: array{value: string}, amountTolerance: array{value: string}, intervalKind: string, dayOfPeriod: int, nextExpectedOn: string, autoCreate: bool, categoryId: ?string, note: ?string, paymentMethod: ?string} */
    private function readRecurrence(string $id): array
    {
        $this->client->request('GET', '/api/v1/recurrences');
        /** @var array{items: list<array{id: string, version: int, label: string, counterparty: ?string, expectedAmount: array{value: string}, amountTolerance: array{value: string}, intervalKind: string, dayOfPeriod: int, nextExpectedOn: string, autoCreate: bool, categoryId: ?string, note: ?string, paymentMethod: ?string}>} $page */
        $page = $this->decode();
        foreach ($page['items'] as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        self::fail('The recurrence is missing.');
    }

    /** @return array{recurrencesExamined: int, transactionsCreated: int, transactionsBooked: int, skippedClosedPeriod: int, skippedRefused: int, monthStart: string, monthEnd: string} */
    private function materialize(): array
    {
        $this->client->request('POST', '/api/v1/recurrences/materialize', server: self::jsonHeaders(), content: '{}');
        self::assertResponseStatusCodeSame(200);
        /** @var array{recurrencesExamined: int, transactionsCreated: int, transactionsBooked: int, skippedClosedPeriod: int, skippedRefused: int, monthStart: string, monthEnd: string} $result */
        $result = $this->decode();

        return $result;
    }

    /**
     * @param array{transactionsCreated: int, transactionsBooked: int, ...} $result
     *
     * @return array{transactionsCreated: int, transactionsBooked: int}
     */
    private function counts(array $result): array
    {
        return ['transactionsCreated' => $result['transactionsCreated'], 'transactionsBooked' => $result['transactionsBooked']];
    }

    private function switchAutoCreateOn(): void
    {
        $this->connection->executeStatement('UPDATE transaction_recurrences SET auto_create = true');
    }

    private function travel(string $moment): void
    {
        $this->clock->modify($moment);
        $this->renewCsrf();
    }

    /** @return list<array{id: string, state: string, nature: string, source: string, source_ref: ?string, booked_on: string, raw_label: string, note: ?string, payment_method: ?string, version: int|string, amount: string}> */
    private function transactions(): array
    {
        /** @var list<array{id: string, state: string, nature: string, source: string, source_ref: ?string, booked_on: string, raw_label: string, note: ?string, payment_method: ?string, version: int|string, amount: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, state, nature, source, source_ref, booked_on, raw_label, note, payment_method, version, round(amount_value, amount_scale::int)::text AS amount FROM transaction_transactions WHERE workspace_id = ? ORDER BY booked_on, created_at, id',
            [WorkspaceFixture::OWN_WORKSPACE],
        );

        return $rows;
    }

    /** @return array{id: string, status: string, matched_transaction_id: ?string, generated_transaction_id: ?string, generated_version: int|string|null} */
    private function instalment(string $day): array
    {
        /** @var array{id: string, status: string, matched_transaction_id: ?string, generated_transaction_id: ?string, generated_version: int|string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT id, status, matched_transaction_id, generated_transaction_id, generated_version FROM transaction_recurrence_occurrences WHERE workspace_id = ? AND expected_on = ? ORDER BY id LIMIT 1',
            [WorkspaceFixture::OWN_WORKSPACE, $day],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function recurrenceCount(): int
    {
        return $this->scalarCount('SELECT count(*) FROM transaction_recurrences');
    }

    /** @param list<string> $parameters */
    private function scalarCount(string $sql, array $parameters = []): int
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function renewCsrf(): void
    {
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
    }

    private function signIn(): void
    {
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $object = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $object[$key] = $value;
        }

        return $object;
    }

    /** @return array<string, string> */
    private static function jsonHeaders(): array
    {
        return ['CONTENT_TYPE' => 'application/json'];
    }

    private function seedAccount(string $id, string $workspace): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => 'Compte '.$id, 'asset_code' => 'EUR',
            'kind' => 'CURRENT', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => false,
            'include_in_emergency_fund' => false, 'opened_on' => '2024-01-01', 'closed_on' => null,
            'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    private function seedCategory(string $id, string $workspace, string $type, string $label): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id, 'workspace_id' => $workspace, 'type' => $type, 'label' => $label,
            'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1,
            'version' => 1, 'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ], ['budget_included' => ParameterType::BOOLEAN]);
    }
}
