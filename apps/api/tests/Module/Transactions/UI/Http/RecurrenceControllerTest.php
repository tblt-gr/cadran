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
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class RecurrenceControllerTest extends WebTestCase
{
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OWN_SECOND_ACCOUNT = '00000000-0000-7000-8000-0000000000d3';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string TODAY = '2026-03-14T09:12:04+00:00';
    private const array RECURRENCE_KEYS = [
        'id', 'accountId', 'label', 'counterparty', 'expectedAmount', 'amountTolerance',
        'intervalKind', 'dayOfPeriod', 'nextExpectedOn', 'confirmedAt', 'version',
        'createdAt', 'updatedAt', 'archivedAt',
    ];
    private const array OCCURRENCE_KEYS = [
        'id', 'recurrenceId', 'expectedOn', 'expectedAmount', 'amountTolerance', 'status',
        'matchedTransactionId', 'matchedAt',
    ];
    private const array CANDIDATE_KEYS = [
        'fingerprint', 'accountId', 'counterparty', 'intervalKind', 'medianAmount', 'tolerance',
        'occurrenceCount', 'firstSeenOn', 'lastSeenOn', 'confidence', 'confidenceReason',
    ];

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
        $this->seedAccount(self::OWN_SECOND_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->signIn();

        $this->clock = new MockClock(new \DateTimeImmutable(self::TODAY));
        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->client->disableReboot();
        self::getContainer()->set(ClockInterface::class, $this->clock);
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testARegularHistoryIsProposedWithItsQualitativeConfidenceAndWritesNothing(): void
    {
        $this->seedRegularHistory();

        $this->client->request('GET', '/api/v1/recurrence-candidates');

        self::assertResponseIsSuccessful();
        $page = $this->decode();
        self::assertFalse($page['partial']);
        $candidates = $this->items($page);
        self::assertCount(1, $candidates);
        self::assertSame(self::CANDIDATE_KEYS, array_keys($candidates[0]));
        self::assertSame(self::OWN_ACCOUNT, $candidates[0]['accountId']);
        self::assertSame('Netflix', $candidates[0]['counterparty']);
        self::assertSame('MONTHLY', $candidates[0]['intervalKind']);
        self::assertSame(['value' => '-14.99', 'assetCode' => 'EUR'], $candidates[0]['medianAmount']);
        self::assertSame(['value' => '0.30', 'assetCode' => 'EUR'], $candidates[0]['tolerance']);
        self::assertSame(6, $candidates[0]['occurrenceCount']);
        self::assertSame('2025-10-06', $candidates[0]['firstSeenOn']);
        self::assertSame('2026-03-05', $candidates[0]['lastSeenOn']);
        self::assertSame('HIGH', $candidates[0]['confidence']);
        self::assertNull($candidates[0]['confidenceReason']);
        self::assertSame(0, $this->recurrenceCount());
        self::assertSame(0, $this->occurrenceCount());
    }

    public function testAnIrregularHistoryIsNeverProposed(): void
    {
        $this->seedTransaction(201, '2025-11-02', '-42.00', 'Gym');
        $this->seedTransaction(202, '2026-01-19', '-42.00', 'Gym');
        $this->seedTransaction(203, '2026-03-02', '-42.00', 'Gym');

        $this->client->request('GET', '/api/v1/recurrence-candidates');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->items($this->decode()));
    }

    public function testADismissedCandidateStopsBeingProposedUntilTheDismissalIsUndone(): void
    {
        $this->seedRegularHistory();
        $fingerprint = $this->fingerprint();

        $this->requestDismiss($fingerprint);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->items($this->candidates()));

        $this->client->request('DELETE', '/api/v1/recurrence-candidates/dismissals/'.$fingerprint);
        self::assertResponseStatusCodeSame(204);
        self::assertCount(1, $this->items($this->candidates()));
    }

    public function testUndoingAnAbsentDismissalIsNotFound(): void
    {
        $this->client->request('DELETE', '/api/v1/recurrence-candidates/dismissals/'.str_repeat('a', 64));

        self::assertResponseStatusCodeSame(404);
    }

    public function testConfirmationGeneratesTwelveMonthsOfInstalmentsClampedToShortMonths(): void
    {
        $recurrence = $this->createRecurrence();

        self::assertSame(self::RECURRENCE_KEYS, array_keys($recurrence));
        self::assertSame('Netflix', $recurrence['label']);
        self::assertSame(['value' => '-14.99', 'assetCode' => 'EUR'], $recurrence['expectedAmount']);
        self::assertSame(['value' => '0.30', 'assetCode' => 'EUR'], $recurrence['amountTolerance']);
        self::assertSame(31, $recurrence['dayOfPeriod']);
        self::assertSame('2026-03-31', $recurrence['nextExpectedOn']);
        self::assertSame(1, $recurrence['version']);
        self::assertNull($recurrence['archivedAt']);

        $occurrences = $this->occurrences($recurrence['id']);
        self::assertSame(self::OCCURRENCE_KEYS, array_keys($occurrences[0]));
        self::assertSame([
            '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30', '2026-07-31', '2026-08-31',
            '2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31', '2027-01-31', '2027-02-28',
        ], array_column($occurrences, 'expectedOn'));
        self::assertSame(['value' => '-14.99', 'assetCode' => 'EUR'], $occurrences[0]['expectedAmount']);
        self::assertSame('EXPECTED', $occurrences[0]['status']);
        self::assertNull($occurrences[0]['matchedTransactionId']);
    }

    public function testAMalformedConfirmationIsRefused(): void
    {
        $this->requestCreate([...$this->recurrencePayload(), 'dayOfPeriod' => 32]);
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate([...$this->recurrencePayload(), 'expectedAmount' => '0.00']);
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate([...$this->recurrencePayload(), 'amountTolerance' => '-0.01']);
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate([...$this->recurrencePayload(), 'intervalKind' => 'DAILY']);
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate([...$this->recurrencePayload(), 'accountId' => self::OTHER_ACCOUNT]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, $this->recurrenceCount());
    }

    public function testAnEditRegeneratesOnlyUnmatchedFutureInstalments(): void
    {
        $recurrence = $this->createRecurrence(['dayOfPeriod' => 20, 'firstExpectedOn' => '2026-03-20']);
        $id = $recurrence['id'];
        $this->createTransaction('2026-03-19', '-14.99');
        $matched = $this->matchedOccurrence($id);
        self::assertSame('2026-03-20', $matched['expectedOn']);

        $this->clock->modify('2026-04-25T09:12:04+00:00');
        $this->renewCsrf();
        $this->requestUpdate($id, [...$this->updatePayload(), 'expectedAmount' => '-17.99', 'version' => 1]);

        self::assertResponseIsSuccessful();
        $updated = $this->decode();
        self::assertSame(2, $updated['version']);
        $occurrences = $this->occurrences($id, '2026-01-01', '2027-06-30');
        $byDate = array_column($occurrences, null, 'expectedOn');
        self::assertSame(['value' => '-14.99', 'assetCode' => 'EUR'], $byDate['2026-03-20']['expectedAmount']);
        self::assertSame('RECEIVED', $byDate['2026-03-20']['status']);
        self::assertSame(['value' => '-14.99', 'assetCode' => 'EUR'], $byDate['2026-04-20']['expectedAmount']);
        self::assertSame(['value' => '-17.99', 'assetCode' => 'EUR'], $byDate['2026-05-20']['expectedAmount']);
        self::assertSame('2026-04-20', $updated['nextExpectedOn']);
    }

    public function testDuplicatingATransactionMatchesItToAnExpectedOccurrence(): void
    {
        $recurrence = $this->createRecurrence(['dayOfPeriod' => 20, 'firstExpectedOn' => '2026-03-20']);
        $id = $recurrence['id'];
        // Booked well outside the match window: this original never settles the
        // occurrence on its own, so a later match can only come from its duplicate.
        $original = $this->createTransaction('2026-01-05', '-14.99');

        $this->clock->modify('2026-03-20T09:12:04+00:00');
        $this->renewCsrf();
        $this->requestDuplicate($original['id']);
        self::assertResponseStatusCodeSame(201);
        $duplicate = $this->decode();
        self::assertSame('2026-03-20', $duplicate['bookedOn']);

        $matched = $this->matchedOccurrence($id);
        self::assertSame('2026-03-20', $matched['expectedOn']);
        self::assertSame($duplicate['id'], $matched['matchedTransactionId']);
        self::assertSame('RECEIVED', $matched['status']);
    }

    public function testAStaleVersionOrAnArchivedRecurrenceIsAConflict(): void
    {
        $recurrence = $this->createRecurrence();
        $id = $recurrence['id'];

        $this->requestUpdate($id, [...$this->updatePayload(), 'version' => 7]);
        self::assertResponseStatusCodeSame(409);

        $this->requestRestore($id, 1);
        self::assertResponseStatusCodeSame(409);

        $this->requestArchive($id, 1);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->decode()['archivedAt']);

        $this->requestUpdate($id, [...$this->updatePayload(), 'version' => 2]);
        self::assertResponseStatusCodeSame(409);
        $this->requestArchive($id, 2);
        self::assertResponseStatusCodeSame(409);
        $this->requestRestore($id, 7);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAnArchivedRecurrenceCanBeRestoredWithItsCurrentVersion(): void
    {
        $recurrence = $this->createRecurrence();
        $this->requestArchive($recurrence['id'], $recurrence['version']);
        self::assertResponseIsSuccessful();
        $archived = self::versionedRecord($this->decode());

        $this->requestRestore($recurrence['id'], $archived['version']);

        self::assertResponseIsSuccessful();
        $restored = $this->decode();
        self::assertNull($restored['archivedAt']);
        self::assertSame(3, $restored['version']);
        self::assertSame($recurrence['nextExpectedOn'], $restored['nextExpectedOn']);
        self::assertSame($restored, $this->readRecurrence($recurrence['id']));
    }

    public function testAMovementSettlesTheNearestInstalmentAndAdvancesTheSchedule(): void
    {
        $recurrence = $this->createRecurrence(['dayOfPeriod' => 20, 'firstExpectedOn' => '2026-03-20']);
        $id = $recurrence['id'];

        $transaction = $this->createTransaction('2026-03-19', '-14.99');

        $matched = $this->matchedOccurrence($id);
        self::assertSame('2026-03-20', $matched['expectedOn']);
        self::assertSame($transaction['id'], $matched['matchedTransactionId']);
        self::assertNotNull($matched['matchedAt']);
        self::assertSame('2026-04-20', $this->readRecurrence($id)['nextExpectedOn']);
    }

    public function testATieBetweenTwoInstalmentsTakesTheEarlierExpectedDate(): void
    {
        $earlier = $this->createRecurrence(['label' => 'Abonnement A', 'dayOfPeriod' => 19, 'firstExpectedOn' => '2026-03-19']);
        $later = $this->createRecurrence(['label' => 'Abonnement B', 'dayOfPeriod' => 21, 'firstExpectedOn' => '2026-03-21']);

        $this->createTransaction('2026-03-20', '-14.99');

        self::assertSame('2026-03-19', $this->matchedOccurrence($earlier['id'])['expectedOn']);
        self::assertNull($this->firstMatched($later['id']));
    }

    public function testAMovementOutsideTheWindowOrTheToleranceSettlesNothing(): void
    {
        $recurrence = $this->createRecurrence(['dayOfPeriod' => 20, 'firstExpectedOn' => '2026-03-20']);

        $this->createTransaction('2026-03-12', '-14.99');
        self::assertNull($this->firstMatched($recurrence['id']));

        $this->createTransaction('2026-03-20', '-15.30');
        self::assertNull($this->firstMatched($recurrence['id']));

        $this->createTransaction('2026-03-20', '-14.99', account: self::OWN_SECOND_ACCOUNT);
        self::assertNull($this->firstMatched($recurrence['id']));
    }

    public function testVoidingTheMatchedMovementReturnsTheInstalmentWithoutDoubleAdvancingTheSchedule(): void
    {
        $recurrence = $this->createRecurrence(['dayOfPeriod' => 20, 'firstExpectedOn' => '2026-03-20']);
        $id = $recurrence['id'];
        $transaction = $this->createTransaction('2026-03-19', '-14.99');
        self::assertSame('2026-04-20', $this->readRecurrence($id)['nextExpectedOn']);

        $this->client->request(
            'POST', '/api/v1/transactions/'.$transaction['id'].'/void', server: self::jsonHeaders(),
            content: json_encode(['version' => $transaction['version']], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertNull($this->firstMatched($id));
        self::assertSame('2026-03-20', $this->readRecurrence($id)['nextExpectedOn']);
        self::assertSame('EXPECTED', $this->occurrences($id)[0]['status']);
    }

    public function testEditingAMatchedMovementReleasesItWhenItNoLongerQualifies(): void
    {
        $recurrence = $this->createRecurrence(['dayOfPeriod' => 20, 'firstExpectedOn' => '2026-03-20']);
        $transaction = $this->createTransaction('2026-03-19', '-14.99');
        self::assertNotNull($this->firstMatched($recurrence['id']));

        $this->client->request('PUT', '/api/v1/transactions/'.$transaction['id'], server: self::jsonHeaders(), content: json_encode([
            'accountId' => self::OWN_ACCOUNT,
            'amount' => ['value' => '-16.00', 'assetCode' => 'EUR'],
            'nature' => 'EXPENSE',
            'state' => 'BOOKED',
            'bookedOn' => '2026-03-19',
            'valueOn' => null,
            'authorizedOn' => null,
            'rawLabel' => 'CB NETFLIX',
            'counterparty' => 'Netflix',
            'note' => null,
            'paymentMethod' => null,
            'mcc' => null,
            'maskedCard' => null,
            'bankReference' => null,
            'categoryId' => null,
            'splits' => null,
            'version' => $transaction['version'],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertNull($this->firstMatched($recurrence['id']));
        self::assertSame('2026-03-20', $this->readRecurrence($recurrence['id'])['nextExpectedOn']);
    }

    public function testAPassedInstalmentReadsAsLateWithoutTheStoredStatusChanging(): void
    {
        $recurrence = $this->createRecurrence(['dayOfPeriod' => 20, 'firstExpectedOn' => '2026-03-20']);
        $id = $recurrence['id'];
        self::assertSame('EXPECTED', $this->occurrences($id)[0]['status']);

        $this->clock->modify('2026-03-21T09:12:04+00:00');

        self::assertSame('LATE', $this->occurrences($id, '2026-01-01', '2027-06-30')[0]['status']);
        self::assertSame(['EXPECTED'], array_values(array_unique($this->storedStatuses($id))));
    }

    public function testLateUsesTheWorkspaceTimezoneAtReadTime(): void
    {
        $recurrence = $this->createRecurrence(['dayOfPeriod' => 20, 'firstExpectedOn' => '2026-03-20']);
        $this->clock->modify('2026-03-21T05:30:00+00:00');
        $this->connection->update('identity_workspaces', ['timezone' => 'America/Los_Angeles'], ['id' => WorkspaceFixture::OWN_WORKSPACE]);

        self::assertSame('EXPECTED', $this->occurrences($recurrence['id'])[0]['status']);

        $this->connection->update('identity_workspaces', ['timezone' => 'Europe/Paris'], ['id' => WorkspaceFixture::OWN_WORKSPACE]);
        self::assertSame('LATE', $this->occurrences($recurrence['id'])[0]['status']);
        self::assertSame(['EXPECTED'], array_values(array_unique($this->storedStatuses($recurrence['id']))));
    }

    public function testTheHorizonRefreshIsExplicitAndIdempotent(): void
    {
        $recurrence = $this->createRecurrence(['dayOfPeriod' => 20, 'firstExpectedOn' => '2026-03-20']);
        $id = $recurrence['id'];
        self::assertCount(12, $this->occurrences($id, '2026-01-01', '2028-01-01'));

        $this->requestRefreshHorizon();
        self::assertResponseIsSuccessful();
        self::assertSame(['recurrencesExamined' => 1, 'occurrencesGenerated' => 0, 'horizonEndsOn' => '2027-03-14'], $this->decode());

        $this->clock->modify('2026-04-21T09:12:04+00:00');
        $this->renewCsrf();
        $this->requestRefreshHorizon();
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->decode()['occurrencesGenerated']);
        self::assertCount(14, $this->occurrences($id, '2026-01-01', '2028-01-01'));

        $this->requestRefreshHorizon();
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->decode()['occurrencesGenerated']);
        self::assertCount(14, $this->occurrences($id, '2026-01-01', '2028-01-01'));
    }

    public function testListingNeverExtendsTheHorizon(): void
    {
        $recurrence = $this->createRecurrence(['dayOfPeriod' => 20, 'firstExpectedOn' => '2026-03-20']);
        $this->clock->modify('2026-06-21T09:12:04+00:00');

        $this->client->request('GET', '/api/v1/recurrences');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->decode()['total']);

        self::assertCount(12, $this->occurrences($recurrence['id'], '2026-01-01', '2028-01-01'));
    }

    public function testAnotherWorkspaceSeesNeitherTheRecurrenceNorItsInstalments(): void
    {
        $this->seedRegularHistory();
        $recurrence = $this->createRecurrence();
        $id = $recurrence['id'];
        $fingerprint = $this->fingerprint();
        $this->requestDismiss($fingerprint);
        self::assertResponseStatusCodeSame(204);

        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/recurrences');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->items($this->decode()));

        $this->client->request('GET', '/api/v1/recurrence-candidates');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->items($this->decode()));

        $this->client->request('GET', '/api/v1/recurrences/'.$id.'/occurrences');
        self::assertResponseStatusCodeSame(404);

        $this->requestUpdate($id, [...$this->updatePayload(), 'version' => 1]);
        self::assertResponseStatusCodeSame(404);

        $this->requestArchive($id, 1);
        self::assertResponseStatusCodeSame(404);

        $this->requestRestore($id, 1);
        self::assertResponseStatusCodeSame(404);

        $this->requestCreate([...$this->recurrencePayload(), 'accountId' => self::OWN_ACCOUNT]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('DELETE', '/api/v1/recurrence-candidates/dismissals/'.$fingerprint);
        self::assertResponseStatusCodeSame(404);

        $this->requestRefreshHorizon();
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->decode()['recurrencesExamined']);

        $this->signIn();
        self::assertSame(1, $this->recurrenceCount());
        self::assertSame(1, $this->readRecurrence($id)['version']);
        self::assertSame([], $this->items($this->candidates()));
    }

    public function testEveryMutationRequiresCsrf(): void
    {
        $recurrence = $this->createRecurrence();
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');

        $this->requestCreate($this->recurrencePayload());
        self::assertResponseStatusCodeSame(403);

        $this->requestUpdate($recurrence['id'], [...$this->updatePayload(), 'version' => 1]);
        self::assertResponseStatusCodeSame(403);

        $this->requestArchive($recurrence['id'], 1);
        self::assertResponseStatusCodeSame(403);

        $this->requestRestore($recurrence['id'], 1);
        self::assertResponseStatusCodeSame(403);

        $this->requestDismiss(str_repeat('b', 64));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('DELETE', '/api/v1/recurrence-candidates/dismissals/'.str_repeat('b', 64));
        self::assertResponseStatusCodeSame(403);

        $this->requestRefreshHorizon();
        self::assertResponseStatusCodeSame(403);

        self::assertSame(1, $this->recurrenceCount());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{
     *     id: string,
     *     version: int,
     *     label: mixed,
     *     expectedAmount: mixed,
     *     amountTolerance: mixed,
     *     dayOfPeriod: mixed,
     *     nextExpectedOn: mixed,
     *     archivedAt: mixed,
     *     ...
     * }
     */
    private function createRecurrence(array $overrides = []): array
    {
        $this->requestCreate([...$this->recurrencePayload(), ...$overrides]);
        self::assertResponseStatusCodeSame(201);

        /** @var array{id: string, version: int, label: mixed, expectedAmount: mixed, amountTolerance: mixed, dayOfPeriod: mixed, nextExpectedOn: mixed, archivedAt: mixed, ...} $record */
        $record = self::versionedRecord($this->decode());
        self::assertArrayHasKey('label', $record);
        self::assertArrayHasKey('expectedAmount', $record);
        self::assertArrayHasKey('amountTolerance', $record);
        self::assertArrayHasKey('dayOfPeriod', $record);
        self::assertArrayHasKey('nextExpectedOn', $record);
        self::assertArrayHasKey('archivedAt', $record);

        return [
            ...$record,
            'label' => $record['label'],
            'expectedAmount' => $record['expectedAmount'],
            'amountTolerance' => $record['amountTolerance'],
            'dayOfPeriod' => $record['dayOfPeriod'],
            'nextExpectedOn' => $record['nextExpectedOn'],
            'archivedAt' => $record['archivedAt'],
        ];
    }

    /** @return array<string, mixed> */
    private function recurrencePayload(): array
    {
        return [
            'accountId' => self::OWN_ACCOUNT,
            'label' => 'Netflix',
            'counterparty' => 'Netflix',
            'expectedAmount' => '-14.99',
            'amountTolerance' => '0.30',
            'intervalKind' => 'MONTHLY',
            'dayOfPeriod' => 31,
            'firstExpectedOn' => '2026-03-31',
        ];
    }

    /** @return array<string, mixed> */
    private function updatePayload(): array
    {
        return [
            'label' => 'Netflix Premium',
            'counterparty' => 'Netflix',
            'expectedAmount' => '-14.99',
            'amountTolerance' => '0.30',
            'intervalKind' => 'MONTHLY',
            'dayOfPeriod' => 20,
            'version' => 1,
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

    private function requestDuplicate(string $id): void
    {
        $this->client->request('POST', '/api/v1/transactions/'.$id.'/duplicate', server: self::jsonHeaders(), content: '{}');
    }

    private function requestRestore(string $id, int $version): void
    {
        $this->client->request('POST', '/api/v1/recurrences/'.$id.'/restore', server: self::jsonHeaders(), content: json_encode(['version' => $version], JSON_THROW_ON_ERROR));
    }

    private function requestDismiss(string $fingerprint): void
    {
        $this->client->request('POST', '/api/v1/recurrence-candidates/dismiss', server: self::jsonHeaders(), content: json_encode(['fingerprint' => $fingerprint], JSON_THROW_ON_ERROR));
    }

    private function requestRefreshHorizon(): void
    {
        $this->client->request('POST', '/api/v1/recurrences/refresh-horizon', server: self::jsonHeaders(), content: '{}');
    }

    /** @return array<string, mixed> */
    private function candidates(): array
    {
        $this->client->request('GET', '/api/v1/recurrence-candidates');
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function readRecurrence(string $id): array
    {
        $this->client->request('GET', '/api/v1/recurrences');
        self::assertResponseIsSuccessful();
        foreach ($this->items($this->decode()) as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        self::fail('The recurrence is missing from the listing.');
    }

    /** @return list<array<string, mixed>> */
    private function occurrences(string $id, ?string $from = null, ?string $to = null): array
    {
        $query = null === $from ? '' : '?from='.$from.'&to='.$to;
        $this->client->request('GET', '/api/v1/recurrences/'.$id.'/occurrences'.$query);
        self::assertResponseIsSuccessful();

        return $this->items($this->decode());
    }

    /** @return array<string, mixed> */
    private function matchedOccurrence(string $id): array
    {
        $matched = $this->firstMatched($id);
        self::assertNotNull($matched);

        return $matched;
    }

    /** @return array<string, mixed>|null */
    private function firstMatched(string $id): ?array
    {
        foreach ($this->occurrences($id, '2026-01-01', '2028-01-01') as $occurrence) {
            if (null !== $occurrence['matchedTransactionId']) {
                return $occurrence;
            }
        }

        return null;
    }

    /** @return array{id: string, version: int, ...} */
    private function createTransaction(string $bookedOn, string $amount, string $account = self::OWN_ACCOUNT): array
    {
        if ($this->clock->now()->format('Y-m-d') < $bookedOn) {
            $this->clock->modify($bookedOn.'T12:00:00+00:00');
            $this->renewCsrf();
        }
        $this->client->request('POST', '/api/v1/transactions', server: self::jsonHeaders(), content: json_encode([
            'accountId' => $account, 'amount' => ['value' => $amount, 'assetCode' => 'EUR'], 'nature' => 'EXPENSE',
            'state' => 'BOOKED', 'bookedOn' => $bookedOn, 'valueOn' => null, 'authorizedOn' => null,
            'rawLabel' => 'CB NETFLIX', 'counterparty' => 'Netflix', 'note' => null, 'paymentMethod' => null,
            'mcc' => null, 'maskedCard' => null, 'bankReference' => null, 'categoryId' => null, 'splits' => null,
            'source' => 'MANUAL',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return self::versionedRecord($this->decode());
    }

    private function fingerprint(): string
    {
        $candidates = $this->items($this->candidates());
        self::assertCount(1, $candidates);
        self::assertIsString($candidates[0]['fingerprint']);

        return $candidates[0]['fingerprint'];
    }

    private function seedRegularHistory(): void
    {
        foreach ([
            [101, '2025-10-06'], [102, '2025-11-05'], [103, '2025-12-05'],
            [104, '2026-01-04'], [105, '2026-02-03'], [106, '2026-03-05'],
        ] as [$index, $bookedOn]) {
            $this->seedTransaction($index, $bookedOn, '-14.99', 'Netflix');
        }
    }

    private function seedTransaction(int $index, string $bookedOn, string $amount, string $counterparty): void
    {
        $this->connection->insert('transaction_transactions', [
            'id' => sprintf('00000000-0000-7000-8000-%012d', $index),
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'account_id' => self::OWN_ACCOUNT,
            'asset_code' => 'EUR',
            'amount_value' => $amount,
            'amount_scale' => 2,
            'state' => 'BOOKED',
            'nature' => 'EXPENSE',
            'source' => 'MANUAL',
            'source_ref' => null,
            'booked_on' => $bookedOn,
            'raw_label' => 'CB '.$counterparty,
            'counterparty' => $counterparty,
            'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
            'voided_at' => null,
        ]);
    }

    /** @return list<string> */
    private function storedStatuses(string $recurrenceId): array
    {
        return array_map(
            static function (mixed $value): string {
                self::assertIsString($value);

                return $value;
            },
            $this->connection->fetchFirstColumn(
                'SELECT status FROM transaction_recurrence_occurrences WHERE workspace_id = ? AND recurrence_id = ? ORDER BY expected_on',
                [WorkspaceFixture::OWN_WORKSPACE, $recurrenceId],
            ),
        );
    }

    private function recurrenceCount(): int
    {
        return self::integerResult($this->connection->fetchOne(
            'SELECT count(*) FROM transaction_recurrences WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        ));
    }

    private function occurrenceCount(): int
    {
        return self::integerResult($this->connection->fetchOne(
            'SELECT count(*) FROM transaction_recurrence_occurrences WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        ));
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

    private function signIn(string $email = WorkspaceFixture::OWNER_EMAIL): void
    {
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: json_encode([
            'email' => $email,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    private function renewCsrf(): void
    {
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
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

    /**
     * @param array<string, mixed> $page
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $page): array
    {
        $items = $page['items'] ?? null;
        self::assertIsArray($items);

        return array_values(array_map(self::object(...), $items));
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        self::assertIsArray($value);
        $object = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $object[$key] = $item;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array{id: string, version: int, ...}
     */
    private static function versionedRecord(array $record): array
    {
        self::assertIsString($record['id'] ?? null);
        self::assertIsInt($record['version'] ?? null);

        return $record;
    }

    private static function integerResult(mixed $value): int
    {
        self::assertTrue(is_int($value) || is_string($value));

        return (int) $value;
    }

    /** @return array<string, string> */
    private static function jsonHeaders(): array
    {
        return ['CONTENT_TYPE' => 'application/json'];
    }
}
