<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * TX-017: a transaction dated later in the current month is stored pending with a booking marker,
 * booked on its date by the materialisation job, and counted in the monthly flows only when the
 * caller asks for it. The worked examples of the ticket are the reference tests: today is
 * 2026-11-10, the workspace timezone is Europe/Paris, category "Abonnements" is a budget expense
 * and "Salaire" an income.
 */
final class PlannedTransactionsTest extends WebTestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string SUBSCRIPTIONS = '00000000-0000-7000-8000-0000000000c1';
    private const string SALARY = '00000000-0000-7000-8000-0000000000c2';
    private const string OTHER_CATEGORY = '00000000-0000-7000-8000-0000000000c3';
    private const string BOOKED_SUBSCRIPTION = '00000000-0000-7000-8000-000000000101';
    private const string PENDING_CANDIDATE = '00000000-0000-7000-8000-000000000102';
    private const string BOOKED_SALARY = '00000000-0000-7000-8000-000000000103';
    private const string TODAY = '2026-11-10T09:00:00+00:00';

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
        $this->seedAccount(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);
        $this->seedCategory(self::SUBSCRIPTIONS, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Abonnements');
        $this->seedCategory(self::SALARY, WorkspaceFixture::OWN_WORKSPACE, 'INCOME', 'Salaire');
        $this->seedCategory(self::OTHER_CATEGORY, WorkspaceFixture::OTHER_WORKSPACE, 'EXPENSE', 'Voisin');

        $this->client->request('GET', '/api/v1/session');
        $this->signIn();
        $this->clock = new MockClock(new \DateTimeImmutable(self::TODAY));
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

    // Example 1

    public function testAFutureDatedPendingTransactionIsStoredWithTheBookingMarker(): void
    {
        $this->bookedSubscription();

        $created = $this->plan('Internet', '-29.99', '2026-11-20');

        self::assertSame('PENDING', $created['state']);
        self::assertTrue($created['autoBook']);
        self::assertSame('2026-11-20', $created['bookedOn']);
        self::assertTrue($this->row($this->idOf($created))['auto_book']);
    }

    public function testAPlannedRowNeverChangesAnAccountBalance(): void
    {
        $this->bookedSubscription();
        $this->plan('Internet', '-29.99', '2026-11-20');

        self::assertSame(['-13.99'], $this->bookedSum('2026-11-01', '2026-11-30'));
    }

    // Example 2

    // Example 3

    public function testTheJobBooksAPlannedRowOnItsDateAndNoFigureMovesWhileTheSettingIsOn(): void
    {
        $this->bookedSubscription();
        $planned = $this->plan('Internet', '-29.99', '2026-11-20');
        $id = $this->idOf($planned);

        $this->travel('2026-11-19T09:00:00+00:00');
        self::assertSame(0, $this->materialize()['transactionsBooked']);
        self::assertSame('PENDING', $this->row($id)['state']);
        self::assertSame(['-13.99'], $this->bookedSum('2026-11-01', '2026-11-30'));

        $this->travel('2026-11-20T09:00:00+00:00');
        $result = $this->materialize();

        self::assertSame(1, $result['transactionsBooked']);
        $row = $this->row($id);
        self::assertSame('BOOKED', $row['state']);
        self::assertFalse($row['auto_book']);
        self::assertSame(2, (int) $row['version']);
        self::assertSame(['-43.98'], $this->bookedSum('2026-11-01', '2026-11-30'));
        self::assertSame(1, $this->scalar("SELECT count(*) FROM audit_events WHERE event_type = 'transaction.updated' AND entity_id = ?", [$id]));
    }

    public function testRerunningTheJobBooksNothingTwice(): void
    {
        $id = $this->idOf($this->plan('Internet', '-29.99', '2026-11-20'));
        $this->travel('2026-11-21T09:00:00+00:00');

        self::assertSame(1, $this->materialize()['transactionsBooked']);
        self::assertSame(0, $this->materialize()['transactionsBooked']);
        self::assertSame(2, (int) $this->row($id)['version']);
    }

    public function testTheJobAlsoRunsOnAWorkspaceWithoutAnyAutomaticRecurrence(): void
    {
        $this->plan('Internet', '-29.99', '2026-11-12');
        $this->travel('2026-11-12T09:00:00+00:00');

        $result = $this->materialize();

        self::assertSame(0, $result['recurrencesExamined']);
        self::assertSame(1, $result['transactionsBooked']);
    }

    // Example 4

    public function testDateBoundsFollowTheStateOfTheTransaction(): void
    {
        $this->requestCreate($this->payload('Fin de mois', '-1.00', '2026-11-30'));
        self::assertResponseStatusCodeSame(201);

        $this->requestCreate($this->payload('Mois suivant', '-1.00', '2026-12-01'));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Réel', '-1.00', '2026-11-12', state: 'BOOKED'));
        self::assertResponseStatusCodeSame(422);

        self::assertSame(1, $this->scalar('SELECT count(*) FROM transaction_transactions WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE]));
    }

    public function testTheMonthEndIsTheWorkspaceLocalOneAcrossAMonthRollover(): void
    {
        // 2026-11-30 23:30 UTC is already 2026-12-01 in Europe/Paris.
        $this->travel('2026-11-30T23:30:00+00:00');

        $this->requestCreate($this->payload('Décembre', '-1.00', '2026-12-31'));
        self::assertResponseStatusCodeSame(201);
        $this->requestCreate($this->payload('Janvier', '-1.00', '2027-01-01'));
        self::assertResponseStatusCodeSame(422);
        $this->requestCreate($this->payload('Hier UTC', '-1.00', '2026-12-01', state: 'BOOKED'));
        self::assertResponseStatusCodeSame(201);
        $this->requestCreate($this->payload('Demain', '-1.00', '2026-12-02', state: 'BOOKED'));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAClientCannotSendTheBookingMarker(): void
    {
        $this->requestCreate([...$this->payload('Forgé', '-1.00', '2026-11-20'), 'autoBook' => true]);
        self::assertResponseStatusCodeSame(422);

        $planned = $this->plan('Internet', '-29.99', '2026-11-20');
        $this->requestUpdate($this->idOf($planned), [...$this->updateBody($planned), 'autoBook' => false]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(1, $this->scalar('SELECT count(*) FROM transaction_transactions WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE]));
    }

    public function testAPastPendingRowOrAnImportedOneNeverGetsTheMarker(): void
    {
        $past = $this->requestAndDecode($this->payload('Passé', '-1.00', '2026-11-08'));
        self::assertFalse($past['autoBook']);

        $today = $this->requestAndDecode($this->payload('Aujourd’hui', '-1.00', '2026-11-10'));
        self::assertFalse($today['autoBook']);
    }

    // Example 5

    public function testAPendingRowWithoutTheMarkerOrUnderReviewIsNeverBookedByTheJob(): void
    {
        $this->insertTransaction(self::PENDING_CANDIDATE, '-5.00', 'EXPENSE', 'PENDING', '2026-11-08', 'Manuel passé', self::SUBSCRIPTIONS);
        $this->insertTransaction('00000000-0000-7000-8000-000000000104', '-6.00', 'EXPENSE', 'PENDING', '2026-11-08', 'Import', self::SUBSCRIPTIONS, source: 'IMPORT');
        $this->insertTransaction('00000000-0000-7000-8000-000000000105', '-7.00', 'EXPENSE', 'PENDING', '2026-11-08', 'Revue', self::SUBSCRIPTIONS, reviewReason: 'NO_MATCH');
        // Defence in depth: even a corrupted row carrying both the marker and a review reason is left alone.
        $this->insertTransaction('00000000-0000-7000-8000-000000000106', '-8.00', 'EXPENSE', 'PENDING', '2026-11-08', 'Corrompu', self::SUBSCRIPTIONS, reviewReason: 'NO_MATCH', autoBook: true);
        $this->travel('2026-11-30T09:00:00+00:00');

        self::assertSame(0, $this->materialize()['transactionsBooked']);

        self::assertSame(4, $this->scalar("SELECT count(*) FROM transaction_transactions WHERE state = 'PENDING'"));
    }

    // Example 6

    public function testEditingThePlannedDateKeepsTheMarkerAndTodayIsBookedByTheNextRun(): void
    {
        $planned = $this->plan('Internet', '-29.99', '2026-11-20');
        $id = $this->idOf($planned);

        $moved = $this->update($planned, ['bookedOn' => '2026-11-25']);
        self::assertSame('PENDING', $moved['state']);
        self::assertTrue($moved['autoBook']);

        $today = $this->update($moved, ['bookedOn' => '2026-11-10']);
        self::assertTrue($today['autoBook']);
        self::assertSame('PENDING', $today['state']);

        self::assertSame(1, $this->materialize()['transactionsBooked']);
        self::assertSame('BOOKED', $this->row($id)['state']);
        self::assertFalse($this->row($id)['auto_book']);
    }

    public function testEditingAPastPendingRowIntoTheFutureMarksIt(): void
    {
        $past = $this->requestAndDecode($this->payload('Passé', '-1.00', '2026-11-08'));

        $moved = $this->update($past, ['bookedOn' => '2026-11-22']);

        self::assertTrue($moved['autoBook']);
    }

    public function testRejectingAPlannedRowClearsTheMarkerAndRemovesItFromTheFigures(): void
    {
        $this->bookedSubscription();
        $planned = $this->plan('Internet', '-29.99', '2026-11-20');

        $rejected = $this->update($planned, ['state' => 'REJECTED']);

        self::assertSame('REJECTED', $rejected['state']);
        self::assertFalse($rejected['autoBook']);
    }

    public function testVoidingAPlannedRowClearsTheMarker(): void
    {
        $planned = $this->plan('Internet', '-29.99', '2026-11-20');

        $this->client->request('POST', '/api/v1/transactions/'.$this->idOf($planned).'/void', server: self::jsonHeaders(), content: json_encode(['version' => $planned['version']], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->row($this->idOf($planned))['auto_book']);
        self::assertSame(0, $this->scalar('SELECT count(*) FROM transaction_transactions WHERE auto_book'));
    }

    public function testAPlannedRowCannotBeBookedByHandAheadOfItsDate(): void
    {
        $planned = $this->plan('Internet', '-29.99', '2026-11-20');

        $this->requestUpdate($this->idOf($planned), $this->updateBody($planned, ['state' => 'BOOKED']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('PENDING', $this->row($this->idOf($planned))['state']);
    }

    // Example 7

    public function testARecurrenceGeneratedPendingRowCarriesTheMarkerAndCountsLikeAManualOne(): void
    {
        $this->client->request('POST', '/api/v1/recurrences', server: self::jsonHeaders(), content: json_encode([
            'accountId' => self::ACCOUNT, 'label' => 'Netflix', 'counterparty' => null, 'expectedAmount' => '-17.99',
            'amountTolerance' => '0.00', 'intervalKind' => 'MONTHLY', 'dayOfPeriod' => 15, 'firstExpectedOn' => '2026-11-15',
            'autoCreate' => true, 'categoryId' => self::SUBSCRIPTIONS,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $row = $this->connection->fetchAssociative("SELECT id, state, auto_book FROM transaction_transactions WHERE source = 'RECURRENCE'");
        self::assertIsArray($row);
        self::assertSame('PENDING', $row['state']);
        self::assertTrue($row['auto_book']);

        $this->travel('2026-11-15T09:00:00+00:00');
        self::assertSame(1, $this->materialize()['transactionsBooked']);
        self::assertSame(1, $this->scalar("SELECT count(*) FROM transaction_recurrence_occurrences WHERE status = 'RECEIVED'"));
    }

    // Example 8

    public function testTheMonthlyReportFlowsCountPlannedRowsOnlyWhenAskedFor(): void
    {
        $this->bookedSubscription();
        $this->insertTransaction(self::BOOKED_SALARY, '1500.00', 'INCOME', 'BOOKED', '2026-11-01', 'Salaire', self::SALARY);
        $this->plan('Internet', '-29.99', '2026-11-20');
        $this->plan('Prime', '300.00', '2026-11-28', nature: 'INCOME', category: self::SALARY);

        $with = $this->get('/api/v1/reports/monthly?month=2026-11&includeUpcoming=true');
        self::assertTrue($with['includesUpcoming']);
        self::assertSame(2, $with['upcomingCount']);
        self::assertSame(0, $with['pendingCount']);
        self::assertSame('1800.00', self::valueOf($with, 'cashIncome'));
        self::assertSame('43.98', self::valueOf($with, 'budgetExpenses'));
        self::assertSame('1756.02', self::valueOf($with, 'budgetSurplus'));

        $without = $this->get('/api/v1/reports/monthly?month=2026-11&includeUpcoming=false');
        self::assertFalse($without['includesUpcoming']);
        self::assertSame(0, $without['upcomingCount']);
        self::assertSame(2, $without['pendingCount']);
        self::assertSame('1500.00', self::valueOf($without, 'cashIncome'));
        self::assertSame('13.99', self::valueOf($without, 'budgetExpenses'));
        self::assertSame('1486.01', self::valueOf($without, 'budgetSurplus'));

        self::assertSame($without['endNetWorth'], $with['endNetWorth']);
        self::assertSame($without['beginningNetWorth'], $with['beginningNetWorth']);
        self::assertSame($this->get('/api/v1/reports/monthly?month=2026-11'), $without);
    }

    public function testBookingThePlannedRowsDoesNotMoveTheReportWhileTheSettingIsOn(): void
    {
        $this->bookedSubscription();
        $this->plan('Internet', '-29.99', '2026-11-20');
        $before = $this->get('/api/v1/reports/monthly?month=2026-11&includeUpcoming=true');

        $this->travel('2026-11-20T09:00:00+00:00');
        $this->materialize();
        $after = $this->get('/api/v1/reports/monthly?month=2026-11&includeUpcoming=true');

        foreach (['cashIncome', 'budgetExpenses', 'budgetSurplus', 'cashSavingsRate'] as $kpi) {
            self::assertSame(self::valueOf($before, $kpi), self::valueOf($after, $kpi), $kpi);
        }
        self::assertSame(1, $before['upcomingCount']);
        self::assertSame(0, $after['upcomingCount']);
    }

    public function testTheLedgerTheRecapAndTheExplanationsFollowTheParameter(): void
    {
        $this->bookedSubscription();
        $planned = $this->plan('Internet', '-29.99', '2026-11-20');

        $ledger = $this->get('/api/v1/reports/monthly/ledger?month=2026-11&includeUpcoming=true');
        self::assertTrue($ledger['includesUpcoming']);
        self::assertSame(1, $ledger['upcomingCount']);
        $row = self::rowIn($ledger, 'expenseCategories', self::SUBSCRIPTIONS);
        self::assertSame('43.98', self::valueOfMetric($row['total']));
        $movements = $this->get('/api/v1/reports/monthly/ledger/expense/'.self::SUBSCRIPTIONS.'/movements?month=2026-11&includeUpcoming=true');
        $flags = [];
        foreach (self::items($movements) as $item) {
            $flags[self::string($item['id'])] = [$item['upcoming'], $item['pending']];
        }
        ksort($flags);
        $expectedFlags = [self::BOOKED_SUBSCRIPTION => [false, false], $this->idOf($planned) => [true, false]];
        ksort($expectedFlags);
        self::assertSame($expectedFlags, $flags);

        $plainLedger = $this->get('/api/v1/reports/monthly/ledger?month=2026-11');
        self::assertFalse($plainLedger['includesUpcoming']);
        self::assertSame('13.99', self::valueOfMetric(self::rowIn($plainLedger, 'expenseCategories', self::SUBSCRIPTIONS)['total']));

        $recap = $this->get('/api/v1/reports/monthly/recap?month=2026-11&includeUpcoming=true');
        self::assertTrue($recap['includesUpcoming']);
        self::assertSame(1, $recap['upcomingCount']);
        self::assertFalse($this->get('/api/v1/reports/monthly/recap?month=2026-11')['includesUpcoming']);

        $explained = $this->get('/api/v1/reports/monthly/budgetExpenses/explain?month=2026-11&includeUpcoming=true');
        self::assertTrue($explained['includesUpcoming']);
        self::assertStringContainsString('planned', self::string($explained['scope']));
        self::assertIsArray($explained['sourceTransactionIds']);
        self::assertContains($this->idOf($planned), $explained['sourceTransactionIds']);
        $plain = $this->get('/api/v1/reports/monthly/budgetExpenses/explain?month=2026-11');
        self::assertFalse($plain['includesUpcoming']);
        self::assertStringNotContainsString('planned', self::string($plain['scope']));
    }

    public function testAnInvalidParameterValueIsRefusedOnEveryMonthlyRead(): void
    {
        foreach ([
            '/api/v1/reports/monthly?month=2026-11&includeUpcoming=yes',
            '/api/v1/reports/monthly/ledger?month=2026-11&includeUpcoming=1',
            '/api/v1/reports/monthly/recap?month=2026-11&includeUpcoming=',
            '/api/v1/reports/monthly/budgetExpenses/explain?month=2026-11&includeUpcoming=maybe',
        ] as $uri) {
            $this->client->request('GET', $uri);
            self::assertResponseStatusCodeSame(400, $uri);
        }
    }

    // Net worth, closing, isolation, locking

    public function testNetWorthIgnoresPlannedRowsWhateverTheParameter(): void
    {
        $this->connection->update('account_financial_accounts', ['include_in_net_worth' => true], ['id' => self::ACCOUNT], ['include_in_net_worth' => ParameterType::BOOLEAN]);
        $this->insertTransaction(self::BOOKED_SALARY, '1500.00', 'INCOME', 'BOOKED', '2026-11-01', 'Salaire', self::SALARY);
        $before = $this->get('/api/v1/reports/monthly?month=2026-11&includeUpcoming=true');
        $this->plan('Prime', '300.00', '2026-11-28', nature: 'INCOME', category: self::SALARY);
        $this->plan('Internet', '-29.99', '2026-11-20');

        $with = $this->get('/api/v1/reports/monthly?month=2026-11&includeUpcoming=true');
        $without = $this->get('/api/v1/reports/monthly?month=2026-11&includeUpcoming=false');

        self::assertSame($before['endNetWorth'], $with['endNetWorth']);
        self::assertSame($before['endNetWorth'], $without['endNetWorth']);
        self::assertSame($before['accounts'], $with['accounts']);
        self::assertSame(['1500'], $this->bookedSum('2026-11-01', '2026-11-30'));
    }

    public function testAMonthWithAPendingPlannedRowCannotBeClosed(): void
    {
        $this->plan('Internet', '-29.99', '2026-11-20');
        $this->travel('2026-12-03T09:00:00+00:00');

        $this->client->request('PUT', '/api/v1/periods/2026-11/closure', server: self::jsonHeaders(), content: '{}');

        self::assertResponseStatusCodeSame(409);
        $body = $this->decode();
        self::assertSame('/problems/period-closing-blocked', $body['type']);
        $conditions = array_column(is_array($body['blockers'] ?? null) ? $body['blockers'] : [], 'condition');
        self::assertContains('PENDING_TRANSACTIONS', $conditions);
        self::assertSame(0, $this->scalar('SELECT count(*) FROM account_period_closures'));
    }

    public function testAClosedPeriodSkipsTheBookingAndCountsIt(): void
    {
        $id = $this->idOf($this->plan('Internet', '-29.99', '2026-11-20'));
        $this->travel('2026-11-21T09:00:00+00:00');
        $this->connection->insert('account_period_closures', [
            'id' => '00000000-0000-7000-8000-0000000000f1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'year' => 2026, 'month' => 11, 'closed_at' => '2026-11-21 08:00:00+00',
            'closed_by' => WorkspaceFixture::OWNER_ID, 'reopened_at' => null, 'reopen_reason' => null, 'version' => 1,
        ]);

        $result = $this->materialize();

        self::assertSame(0, $result['transactionsBooked']);
        self::assertSame(1, $result['skippedClosedPeriod']);
        self::assertSame('PENDING', $this->row($id)['state']);
    }

    public function testSeveralAutomaticRecurrencesScanThePlannedRowsOnceAndCountEachSkippedRowOnce(): void
    {
        foreach (['Un', 'Deux', 'Trois'] as $index => $label) {
            $this->client->request('POST', '/api/v1/recurrences', server: self::jsonHeaders(), content: json_encode([
                'accountId' => self::ACCOUNT, 'label' => $label, 'counterparty' => null, 'expectedAmount' => '-1.00',
                'amountTolerance' => '0.00', 'intervalKind' => 'MONTHLY', 'dayOfPeriod' => 28 - $index, 'firstExpectedOn' => '2026-11-'.(28 - $index),
                'autoCreate' => true, 'categoryId' => self::SUBSCRIPTIONS,
            ], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
        }
        $this->plan('Internet', '-29.99', '2026-11-12');
        $this->plan('Eau', '-9.99', '2026-11-12');
        $this->travel('2026-11-12T09:00:00+00:00');
        $this->connection->insert('account_period_closures', [
            'id' => '00000000-0000-7000-8000-0000000000f1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'year' => 2026, 'month' => 11, 'closed_at' => '2026-11-12 08:00:00+00',
            'closed_by' => WorkspaceFixture::OWNER_ID, 'reopened_at' => null, 'reopen_reason' => null, 'version' => 1,
        ]);

        $result = $this->materialize();

        self::assertSame(3, $result['recurrencesExamined']);
        self::assertSame(0, $result['transactionsBooked']);
        self::assertSame(2, $result['skippedClosedPeriod'], 'Each unbookable planned row is counted once per run, not once per recurrence.');
    }

    public function testAMoreThanCapBacklogOfClosedPeriodRowsNeverStarvesANewerBookableRow(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO transaction_transactions (id, workspace_id, account_id, asset_code, amount_value, amount_scale, state, nature, source, booked_on, raw_label, auto_book, version, created_at, updated_at)
             SELECT ('00000000-0000-7000-8000-' || lpad(n::text, 12, '0'))::uuid, ?, ?, 'EUR', -1, 0, 'PENDING', 'EXPENSE', 'MANUAL', DATE '2026-10-05', 'Ancien ' || n, true, 1, TIMESTAMPTZ '2026-10-01 12:00:00+00', TIMESTAMPTZ '2026-10-01 12:00:00+00'
             FROM generate_series(5000, 6000) AS n",
            [WorkspaceFixture::OWN_WORKSPACE, self::ACCOUNT],
        );
        $this->connection->insert('account_period_closures', [
            'id' => '00000000-0000-7000-8000-0000000000f2', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'year' => 2026, 'month' => 10, 'closed_at' => '2026-11-01 08:00:00+00',
            'closed_by' => WorkspaceFixture::OWNER_ID, 'reopened_at' => null, 'reopen_reason' => null, 'version' => 1,
        ]);
        $newer = $this->idOf($this->plan('Internet', '-29.99', '2026-11-12'));
        $this->travel('2026-11-12T09:00:00+00:00');

        $result = $this->materialize();

        self::assertSame(1001, $result['skippedClosedPeriod']);
        self::assertSame(1, $result['transactionsBooked']);
        self::assertSame('BOOKED', $this->row($newer)['state']);
    }

    public function testARunInOneWorkspaceNeverBooksNorCountsTheRowsOfTheOther(): void
    {
        $this->insertTransaction('00000000-0000-7000-8000-000000000201', '-9.99', 'EXPENSE', 'PENDING', '2026-11-12', 'Voisin', self::OTHER_CATEGORY, workspace: WorkspaceFixture::OTHER_WORKSPACE, account: self::OTHER_ACCOUNT, autoBook: true);
        $this->plan('Internet', '-29.99', '2026-11-12');
        $this->travel('2026-11-12T09:00:00+00:00');

        self::assertSame(1, $this->materialize()['transactionsBooked']);

        $neighbour = $this->connection->fetchAssociative("SELECT state, auto_book FROM transaction_transactions WHERE id = '00000000-0000-7000-8000-000000000201'");
        self::assertIsArray($neighbour);
        self::assertSame('PENDING', $neighbour['state']);
        self::assertTrue($neighbour['auto_book']);
    }

    public function testTwoRunsRacingOnTheSameRowBookItOnceWithTheOptimisticVersion(): void
    {
        $id = $this->idOf($this->plan('Internet', '-29.99', '2026-11-20'));
        $this->travel('2026-11-21T09:00:00+00:00');
        $repository = self::getContainer()->get(TransactionRepository::class);
        self::assertInstanceOf(TransactionRepository::class, $repository);
        $workspace = WorkspaceFixture::own();
        $listed = $repository->listAutoBookDueIds($workspace, new \DateTimeImmutable('2026-11-21'), 1000);
        self::assertSame([$id], $listed);

        // The slower run listed the row before the faster one booked it; once the faster one is done the row no longer qualifies.
        self::assertSame(1, $this->materialize()['transactionsBooked']);

        self::assertSame([], $repository->listAutoBookDueIds($workspace, new \DateTimeImmutable('2026-11-21'), 1000));
        $stale = $repository->findForUpdate($workspace, $id);
        self::assertNotNull($stale);
        self::assertFalse($stale->autoBook);
        self::assertFalse($repository->update($stale, 1), 'A write carrying the pre-booking version is refused.');
        self::assertSame('BOOKED', $this->row($id)['state']);
    }

    public function testTheBookingBoundIsOneThousandRowsPerRun(): void
    {
        $repository = self::getContainer()->get(TransactionRepository::class);
        self::assertInstanceOf(TransactionRepository::class, $repository);
        for ($index = 0; $index < 3; ++$index) {
            $this->insertTransaction(sprintf('00000000-0000-7000-8000-00000000030%d', $index), '-1.00', 'EXPENSE', 'PENDING', '2026-11-09', 'Lot '.$index, self::SUBSCRIPTIONS, autoBook: true);
        }

        $listed = $repository->listAutoBookDueIds(WorkspaceFixture::own(), new \DateTimeImmutable('2026-11-10'), 2);

        self::assertCount(2, $listed);
    }

    public function testAnAnonymousCallerCannotRunTheJob(): void
    {
        $this->client->request('DELETE', '/api/v1/session');
        $this->renewCsrf();

        $this->client->request('POST', '/api/v1/recurrences/materialize', server: self::jsonHeaders(), content: '{}');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheDatabaseRefusesAMarkerOnASettledRow(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);

        $this->insertTransaction(self::BOOKED_SUBSCRIPTION, '-1.00', 'EXPENSE', 'BOOKED', '2026-11-02', 'Marqué', self::SUBSCRIPTIONS, autoBook: true);
    }

    public function testTheMigrationBackfillsRecurrenceRowsOnly(): void
    {
        $this->insertTransaction(self::PENDING_CANDIDATE, '-1.00', 'EXPENSE', 'PENDING', '2026-11-20', 'Récurrente', self::SUBSCRIPTIONS, source: 'RECURRENCE');
        $this->insertTransaction('00000000-0000-7000-8000-000000000107', '-1.00', 'EXPENSE', 'PENDING', '2026-11-20', 'Manuelle', self::SUBSCRIPTIONS);

        $this->connection->executeStatement("UPDATE transaction_transactions SET auto_book = true WHERE state = 'PENDING' AND source = 'RECURRENCE' AND review_reason IS NULL");

        self::assertSame([self::PENDING_CANDIDATE], $this->connection->fetchFirstColumn('SELECT id FROM transaction_transactions WHERE auto_book'));
    }

    // Helpers

    private function bookedSubscription(): void
    {
        $this->insertTransaction(self::BOOKED_SUBSCRIPTION, '-13.99', 'EXPENSE', 'BOOKED', '2026-11-02', 'Streaming', self::SUBSCRIPTIONS);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(string $label, string $amount, string $day, string $nature = 'EXPENSE', ?string $category = self::SUBSCRIPTIONS): array
    {
        return $this->requestAndDecode($this->payload($label, $amount, $day, nature: $nature, category: $category), 201);
    }

    /** @return array<string, mixed> */
    private function payload(string $label, string $amount, string $day, string $state = 'PENDING', string $nature = 'EXPENSE', ?string $category = self::SUBSCRIPTIONS): array
    {
        return [
            'accountId' => self::ACCOUNT, 'amount' => ['value' => $amount, 'assetCode' => 'EUR'], 'nature' => $nature,
            'state' => $state, 'bookedOn' => $day, 'valueOn' => null, 'authorizedOn' => null, 'rawLabel' => $label,
            'counterparty' => null, 'note' => null, 'paymentMethod' => null, 'mcc' => null, 'maskedCard' => null,
            'bankReference' => null, 'categoryId' => $category, 'splits' => null,
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function requestAndDecode(array $body, int $status = 201): array
    {
        $this->requestCreate($body);
        self::assertResponseStatusCodeSame($status);

        return $this->decode();
    }

    /** @param array<string, mixed> $body */
    private function requestCreate(array $body): void
    {
        $this->client->request('POST', '/api/v1/transactions', server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function updateBody(array $transaction, array $overrides = []): array
    {
        $splits = $transaction['splits'] ?? null;
        $first = is_array($splits) ? ($splits[0] ?? null) : null;

        return [
            'accountId' => $transaction['accountId'], 'amount' => $transaction['amount'], 'nature' => $transaction['nature'],
            'state' => $transaction['state'], 'bookedOn' => $transaction['bookedOn'], 'valueOn' => null, 'authorizedOn' => null,
            'rawLabel' => $transaction['rawLabel'], 'userLabel' => null, 'counterparty' => null, 'note' => null,
            'paymentMethod' => null, 'mcc' => null, 'maskedCard' => null, 'bankReference' => null,
            'categoryId' => is_array($first) ? ($first['categoryId'] ?? null) : null, 'splits' => null,
            'version' => $transaction['version'],
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $body */
    private function requestUpdate(string $id, array $body): void
    {
        $this->client->request('PUT', '/api/v1/transactions/'.$id, server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function update(array $transaction, array $overrides): array
    {
        $this->requestUpdate($this->idOf($transaction), $this->updateBody($transaction, $overrides));
        self::assertResponseStatusCodeSame(200);

        return $this->decode();
    }

    /** @param array<string, mixed> $transaction */
    private function idOf(array $transaction): string
    {
        return self::string($transaction['id'] ?? null);
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<array<string, mixed>>
     */
    private static function items(array $page): array
    {
        $items = $page['items'] ?? null;
        self::assertIsArray($items);
        $result = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            $typed = [];
            foreach ($item as $key => $value) {
                self::assertIsString($key);
                $typed[$key] = $value;
            }
            $result[] = $typed;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $view
     *
     * @return array<string, mixed>
     */
    private static function rowIn(array $view, string $list, string $id): array
    {
        $rows = $view[$list] ?? null;
        self::assertIsArray($rows);
        foreach ($rows as $row) {
            self::assertIsArray($row);
            if (($row['id'] ?? null) === $id) {
                $typed = [];
                foreach ($row as $key => $value) {
                    self::assertIsString($key);
                    $typed[$key] = $value;
                }

                return $typed;
            }
        }
        self::fail('Row '.$id.' is missing from '.$list);
    }

    /** @param array<string, mixed> $view */
    private static function valueOf(array $view, string $kpi): ?string
    {
        return self::valueOfMetric($view[$kpi] ?? null);
    }

    private static function valueOfMetric(mixed $metric): ?string
    {
        self::assertIsArray($metric);
        $value = $metric['value'] ?? null;
        self::assertTrue(null === $value || is_string($value));

        return $value;
    }

    /** @return list<string> */
    private function bookedSum(string $from, string $to): array
    {
        $repository = self::getContainer()->get(TransactionRepository::class);
        self::assertInstanceOf(TransactionRepository::class, $repository);

        return array_map(
            static fn ($amount): string => $amount->value->toString(),
            $repository->sumBookedMovements(WorkspaceFixture::own(), self::ACCOUNT, new \DateTimeImmutable($from), new \DateTimeImmutable($to)),
        );
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

    private function travel(string $moment): void
    {
        $this->clock->modify($moment);
        $this->renewCsrf();
    }

    /** @return array<string, mixed> */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful($uri);

        return $this->decode();
    }

    /** @return array{state: string, auto_book: bool, version: int|string} */
    private function row(string $id): array
    {
        /** @var array{state: string, auto_book: bool, version: int|string}|false $row */
        $row = $this->connection->fetchAssociative('SELECT state, auto_book, version FROM transaction_transactions WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    /** @param list<string> $parameters */
    private function scalar(string $sql, array $parameters = []): int
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

    private function insertTransaction(
        string $id,
        string $amount,
        string $nature,
        string $state,
        string $day,
        string $label,
        string $category,
        string $source = 'MANUAL',
        ?string $reviewReason = null,
        bool $autoBook = false,
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
        string $account = self::ACCOUNT,
    ): void {
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => $workspace, 'account_id' => $account, 'asset_code' => 'EUR',
            'amount_value' => $amount, 'amount_scale' => 2, 'state' => $state, 'nature' => $nature, 'source' => $source,
            'booked_on' => $day, 'raw_label' => $label, 'review_reason' => $reviewReason, 'auto_book' => $autoBook,
            'version' => 1, 'created_at' => '2026-11-02 12:00:00+00', 'updated_at' => '2026-11-02 12:00:00+00',
        ], ['auto_book' => ParameterType::BOOLEAN]);
        $this->connection->insert('transaction_splits', [
            'id' => substr($id, 0, 24).'9'.substr($id, 25), 'workspace_id' => $workspace, 'transaction_id' => $id,
            'category_id' => 'INCOME' === $nature ? self::SALARY : $category, 'amount_value' => $amount, 'amount_scale' => 2,
            'asset_code' => 'EUR', 'created_at' => '2026-11-02 12:00:00+00',
        ]);
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
