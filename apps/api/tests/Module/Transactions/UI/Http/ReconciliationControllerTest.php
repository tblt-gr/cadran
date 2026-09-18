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
 * The worked example: a pending card authorisation of -128.45 EUR booked on
 * 2026-04-12, and the same movement delivered again on 2026-04-14 once the
 * provider cleared it. Two rows, one real movement.
 */
final class ReconciliationControllerTest extends WebTestCase
{
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string OWN_SECOND_ACCOUNT = '00000000-0000-7000-8000-0000000000d3';
    private const string OWN_EXPENSE = '00000000-0000-7000-8000-0000000000c1';
    private const string OTHER_TRANSACTION = '00000000-0000-7000-8000-0000000000f9';
    private const string UNKNOWN_TRANSACTION = '00000000-0000-7000-8000-0000000000f8';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $databaseReady = false;
    private int $deliveries = 0;

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
        $this->seedAccount(self::OWN_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedAccount(self::OWN_SECOND_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);
        $this->seedCategory(self::OWN_EXPENSE, WorkspaceFixture::OWN_WORKSPACE);

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

    public function testAStableSourceReferenceSettlesThePendingRowAndKeepsItsEnrichment(): void
    {
        $pending = $this->createPending(['sourceRef' => 'PROV-4711', 'note' => 'Courses de la semaine']);
        self::assertSame('PENDING', $pending['state']);

        $settled = $this->createIncoming([
            'sourceRef' => 'PROV-4711',
            'bookedOn' => '2026-04-14',
            'rawLabel' => 'SUMUP *CARREFOUR',
            'note' => null,
            'categoryId' => null,
        ]);

        self::assertSame($pending['id'], $settled['id']);
        self::assertSame('BOOKED', $settled['state']);
        self::assertSame('2026-04-14', $settled['bookedOn']);
        self::assertNull($settled['reviewReason']);
        // The provider delivery carries neither the note nor the category the
        // owner added; the settled row keeps both.
        self::assertSame('Courses de la semaine', $settled['note']);
        self::assertSame('CB CARREFOUR 1234', $settled['rawLabel']);
        $splits = $settled['splits'];
        self::assertIsList($splits);
        self::assertCount(1, $splits);
        self::assertSame(1, $this->countTransactions());
        self::assertSame(1, $this->countAuditEvents('transaction.reconciled'));
    }

    public function testASingleNearbyPendingRowOfTheSameAmountSettlesWithoutAnyIdentifier(): void
    {
        $pending = $this->createPending();

        $settled = $this->createIncoming(['bookedOn' => '2026-04-14']);

        self::assertSame($pending['id'], $settled['id']);
        self::assertSame('BOOKED', $settled['state']);
        self::assertSame(1, $this->countTransactions());
    }

    public function testTwoEquallyCloseCandidatesLeaveTheIncomingMovementPendingUnderReview(): void
    {
        $before = $this->createPending(['bookedOn' => '2026-04-11']);
        $after = $this->createPending(['bookedOn' => '2026-04-17']);

        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14']);

        self::assertNotSame($before['id'], $reviewed['id']);
        self::assertSame('PENDING', $reviewed['state']);
        self::assertSame('AMBIGUOUS_MATCH', $reviewed['reviewReason']);
        $candidates = $reviewed['reconciliationCandidateIds'];
        self::assertIsList($candidates);
        self::assertEqualsCanonicalizing([$before['id'], $after['id']], $candidates);
        // Nothing was booked: a settleable duplicate is exactly what must not exist.
        self::assertSame(3, $this->countTransactions());
        self::assertSame(0, $this->countBookedTransactions());
    }

    public function testAnIncomingMovementWithoutAnyCandidateWaitsForAHumanToo(): void
    {
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14']);

        self::assertSame('PENDING', $reviewed['state']);
        self::assertSame('NO_MATCH', $reviewed['reviewReason']);
        self::assertSame([], $reviewed['reconciliationCandidateIds']);
    }

    public function testARedeliveryWhileTheMovementIsUnderReviewNeverBooksItAutomatically(): void
    {
        $before = $this->createPending(['bookedOn' => '2026-04-11']);
        $after = $this->createPending(['bookedOn' => '2026-04-17']);
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14']);

        // The provider delivers the same movement again. The row waiting for a
        // human is not a candidate for it: settling it here would book exactly
        // the ambiguity the review exists to resolve.
        $redelivered = $this->createIncoming(['bookedOn' => '2026-04-14']);

        self::assertNotSame($reviewed['id'], $redelivered['id']);
        self::assertSame('PENDING', $redelivered['state']);
        self::assertSame('AMBIGUOUS_MATCH', $redelivered['reviewReason']);
        self::assertEqualsCanonicalizing([$before['id'], $after['id']], $redelivered['reconciliationCandidateIds']);
        self::assertSame(0, $this->countBookedTransactions());
    }

    public function testAnUnderReviewMovementCannotBeBookedThroughAPlainEdit(): void
    {
        $this->createPending(['bookedOn' => '2026-04-11']);
        $this->createPending(['bookedOn' => '2026-04-17']);
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14']);

        $this->client->request(
            'PUT',
            '/api/v1/transactions/'.self::stringValue($reviewed, 'id'),
            server: self::jsonHeaders(),
            content: json_encode([
                'accountId' => self::OWN_ACCOUNT,
                'amount' => ['value' => '-128.45', 'assetCode' => 'EUR'],
                'nature' => 'EXPENSE',
                'state' => 'BOOKED',
                'bookedOn' => '2026-04-14',
                'valueOn' => null,
                'authorizedOn' => null,
                'rawLabel' => 'CB CARREFOUR 1234',
                'counterparty' => null,
                'note' => null,
                'paymentMethod' => 'CARD',
                'mcc' => null,
                'maskedCard' => null,
                'bankReference' => null,
                'categoryId' => null,
                'splits' => null,
                'version' => self::intValue($reviewed, 'version'),
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(409);
        self::assertSame('PENDING', $this->readTransaction(self::stringValue($reviewed, 'id'))['state']);
    }

    public function testASourceReferenceAlreadyRecordedIsRefusedInsteadOfCrashing(): void
    {
        $this->createPending(['sourceRef' => 'PROV-4711']);
        $settled = $this->createIncoming(['sourceRef' => 'PROV-4711', 'bookedOn' => '2026-04-14']);
        self::assertSame('BOOKED', $settled['state']);

        // A third delivery of a movement already settled, and a plain entry
        // reusing the same identifier, are both conflicts, never a 500.
        $this->requestCreate(['reconcile' => true, 'categoryId' => null, 'sourceRef' => 'PROV-4711', 'bookedOn' => '2026-04-14']);
        self::assertResponseStatusCodeSame(409);

        $this->requestCreate(['sourceRef' => 'PROV-4711', 'state' => 'PENDING', 'bookedOn' => '2026-04-20']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->countTransactions());
    }

    public function testAFuzzySettlementAdoptsTheIncomingSourceReference(): void
    {
        $this->createPending();

        $settled = $this->createIncoming(['bookedOn' => '2026-04-14', 'sourceRef' => 'PROV-9000']);

        self::assertSame('PROV-9000', $settled['sourceRef']);
        // Which is what makes the next delivery recognisable by identifier.
        $this->requestCreate(['reconcile' => true, 'categoryId' => null, 'sourceRef' => 'PROV-9000', 'bookedOn' => '2026-04-14']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAMovementItselfUnderReviewCannotBeNamedAsTheMatch(): void
    {
        $this->createPending(['bookedOn' => '2026-04-11']);
        $this->createPending(['bookedOn' => '2026-04-17']);
        $firstReview = $this->createIncoming(['bookedOn' => '2026-04-14']);
        $secondReview = $this->createIncoming(['bookedOn' => '2026-04-14', 'amount' => ['value' => '-7.10', 'assetCode' => 'EUR']]);
        self::assertSame('NO_MATCH', $secondReview['reviewReason']);

        $this->reconcileRequest(
            self::stringValue($secondReview, 'id'),
            self::intValue($secondReview, 'version'),
            self::stringValue($firstReview, 'id'),
        );

        self::assertResponseStatusCodeSame(409);
    }

    public function testResolvingWithACandidateBooksItAndVoidsTheReviewedRow(): void
    {
        $before = $this->createPending(['bookedOn' => '2026-04-11', 'note' => 'À vérifier']);
        $this->createPending(['bookedOn' => '2026-04-17']);
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14']);

        $settled = $this->requestReconcile($reviewed, self::stringValue($before, 'id'));

        self::assertSame($before['id'], $settled['id']);
        self::assertSame('BOOKED', $settled['state']);
        self::assertSame('2026-04-14', $settled['bookedOn']);
        self::assertSame('À vérifier', $settled['note']);
        $voided = $this->readTransaction(self::stringValue($reviewed, 'id'));
        self::assertSame('VOIDED', $voided['state']);
        // The voided row points at what it settled, the way a refund points at
        // its original, instead of leaving the link visible only in the schema.
        self::assertSame($before['id'], $voided['reconciledIntoId']);
        self::assertNull($settled['reconciledIntoId']);
        self::assertSame(1, $this->countBookedTransactions());
        self::assertSame(1, $this->countAuditEvents('transaction.reconciled'));
        self::assertSame($before['id'], $this->connection->fetchOne(
            'SELECT matched_transaction_id FROM transaction_reconciliations WHERE workspace_id = :workspace_id AND reviewed_transaction_id = :id',
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $reviewed['id']],
        ));
    }

    public function testAHumanResolutionMovesTheSourceReferenceOntoTheRowItSettles(): void
    {
        $override = $this->createPending(['bookedOn' => '2026-04-11']);
        $this->createPending(['bookedOn' => '2026-04-17']);
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14', 'sourceRef' => 'PROV-4711']);
        self::assertSame('AMBIGUOUS_MATCH', $reviewed['reviewReason']);

        $settled = $this->requestReconcile($reviewed, self::stringValue($override, 'id'));

        // The identifier lives on exactly one row: the settled one. The voided
        // row releases it, so nothing holds it hostage on the unique index.
        self::assertSame('PROV-4711', $settled['sourceRef']);
        self::assertNull($this->readTransaction(self::stringValue($reviewed, 'id'))['sourceRef']);

        // Which is what makes the next delivery of that movement recognisable
        // as already recorded, instead of fuzzily booking a second row for it.
        $this->requestCreate(['reconcile' => true, 'categoryId' => null, 'sourceRef' => 'PROV-4711', 'bookedOn' => '2026-04-14']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->countBookedTransactions());
    }

    public function testASettlementIsRefusedRatherThanLoseOneOfTwoDifferingIdentifiers(): void
    {
        // The authorisation was announced under one identifier, the booking
        // under another. Settling would have to drop one of the two, and the
        // dropped one would stop being recognisable on its next delivery.
        $this->createPending(['sourceRef' => 'AUTH-1']);

        $this->requestCreate(['reconcile' => true, 'categoryId' => null, 'sourceRef' => 'BOOK-9', 'bookedOn' => '2026-04-14']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->countBookedTransactions());
    }

    public function testVoidingAMovementFreesItsExternalIdentifier(): void
    {
        $pending = $this->createPending(['sourceRef' => 'PROV-4711']);

        $this->client->request(
            'POST',
            '/api/v1/transactions/'.self::stringValue($pending, 'id').'/void',
            server: self::jsonHeaders(),
            content: json_encode(['version' => self::intValue($pending, 'version')], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        // The identifier is unique across states, so a voided row holding it
        // would refuse every later delivery with nothing visible to blame.
        self::assertNull($this->readTransaction(self::stringValue($pending, 'id'))['sourceRef']);
        $reissued = $this->createPending(['sourceRef' => 'PROV-4711', 'bookedOn' => '2026-04-13']);
        self::assertSame('PROV-4711', $reissued['sourceRef']);
    }

    public function testVoidingAMovementUnderReviewLeavesNoCandidateBehind(): void
    {
        $this->createPending(['bookedOn' => '2026-04-11']);
        $this->createPending(['bookedOn' => '2026-04-17']);
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14']);

        $this->client->request(
            'POST',
            '/api/v1/transactions/'.self::stringValue($reviewed, 'id').'/void',
            server: self::jsonHeaders(),
            content: json_encode(['version' => self::intValue($reviewed, 'version')], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) self::scalar($this->connection->fetchOne(
            'SELECT count(*) FROM transaction_reconciliation_candidates WHERE workspace_id = :workspace_id AND transaction_id = :id',
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $reviewed['id']],
        )));
    }

    public function testResolvingWithoutACandidateBooksTheReviewedRowItself(): void
    {
        $this->createPending(['bookedOn' => '2026-04-11']);
        $this->createPending(['bookedOn' => '2026-04-17']);
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14']);

        $booked = $this->requestReconcile($reviewed, null);

        self::assertSame($reviewed['id'], $booked['id']);
        self::assertSame('BOOKED', $booked['state']);
        self::assertNull($booked['reviewReason']);
        self::assertSame([], $booked['reconciliationCandidateIds']);
        self::assertSame(2, $this->countPendingTransactions());
    }

    public function testAPendingRowOfTheSameAccountOutsideTheCandidateListIsAValidHumanOverride(): void
    {
        $this->createPending(['bookedOn' => '2026-04-11']);
        $this->createPending(['bookedOn' => '2026-04-17']);
        $override = $this->createPending(['bookedOn' => '2026-04-14', 'amount' => ['value' => '-9.99', 'assetCode' => 'EUR']]);
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14']);

        $settled = $this->requestReconcile($reviewed, self::stringValue($override, 'id'));

        self::assertSame($override['id'], $settled['id']);
        self::assertSame('BOOKED', $settled['state']);
        // The override settles the incoming movement, so it carries its amount.
        self::assertSame(['value' => '-128.45', 'assetCode' => 'EUR'], $settled['amount']);
    }

    public function testAnotherWorkspaceTransactionIsNeverAValidMatch(): void
    {
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14']);
        $this->seedForeignPending();

        $this->reconcileRequest(self::stringValue($reviewed, 'id'), self::intValue($reviewed, 'version'), self::OTHER_TRANSACTION);
        self::assertResponseStatusCodeSame(404);

        $this->reconcileRequest(self::stringValue($reviewed, 'id'), self::intValue($reviewed, 'version'), self::UNKNOWN_TRANSACTION);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAConcurrentResolutionOfTheSameReviewIsRefusedAsStale(): void
    {
        $candidate = $this->createPending(['bookedOn' => '2026-04-11']);
        $this->createPending(['bookedOn' => '2026-04-17']);
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14']);
        $version = self::intValue($reviewed, 'version');

        $this->reconcileRequest(self::stringValue($reviewed, 'id'), $version, self::stringValue($candidate, 'id'));
        self::assertResponseIsSuccessful();

        // The second reviewer still holds the version it read before the first
        // resolution, so its contradictory outcome is refused, not applied.
        $this->reconcileRequest(self::stringValue($reviewed, 'id'), $version, null);
        self::assertResponseStatusCodeSame(409);
    }

    public function testABookedTransactionCannotBeNamedAsTheMatch(): void
    {
        $booked = $this->createPending(['state' => 'BOOKED', 'bookedOn' => '2026-04-11']);
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14']);

        $this->reconcileRequest(self::stringValue($reviewed, 'id'), self::intValue($reviewed, 'version'), self::stringValue($booked, 'id'));

        self::assertResponseStatusCodeSame(409);
    }

    public function testATransactionThatIsNotUnderReviewCannotBeReconciled(): void
    {
        $pending = $this->createPending();

        $this->reconcileRequest(self::stringValue($pending, 'id'), self::intValue($pending, 'version'), null);

        self::assertResponseStatusCodeSame(409);
    }

    public function testAPendingTransferLegIsNeverAutoSettledByAMatchingIncomingMovement(): void
    {
        $leg = $this->seedPendingTransferLeg();

        // Nothing else pending matches, and the leg itself is not a valid
        // candidate, so the movement waits for a human instead of quietly
        // booking one half of a transfer through the wrong endpoint.
        $incoming = $this->createIncoming(['bookedOn' => '2026-04-14']);

        self::assertSame('PENDING', $incoming['state']);
        self::assertSame('NO_MATCH', $incoming['reviewReason']);
        self::assertSame([], $incoming['reconciliationCandidateIds']);
        self::assertSame('PENDING', $this->readTransaction($leg)['state']);
    }

    public function testAPendingTransferLegCannotSettleThroughAStableSourceReference(): void
    {
        $leg = $this->seedPendingTransferLeg(sourceRef: 'PROV-LEG');

        $this->requestCreate(['reconcile' => true, 'categoryId' => null, 'sourceRef' => 'PROV-LEG', 'bookedOn' => '2026-04-14']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('PENDING', $this->readTransaction($leg)['state']);
    }

    public function testAPendingTransferLegNamedByAHumanIsRefusedInsteadOfBookingHalfATransfer(): void
    {
        $leg = $this->seedPendingTransferLeg();
        $this->createPending(['bookedOn' => '2026-04-11', 'amount' => ['value' => '-9.00', 'assetCode' => 'EUR']]);
        $this->createPending(['bookedOn' => '2026-04-17', 'amount' => ['value' => '-9.00', 'assetCode' => 'EUR']]);
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14', 'amount' => ['value' => '-9.00', 'assetCode' => 'EUR']]);
        self::assertSame('AMBIGUOUS_MATCH', $reviewed['reviewReason']);

        $this->reconcileRequest(self::stringValue($reviewed, 'id'), self::intValue($reviewed, 'version'), $leg);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('PENDING', $this->readTransaction($leg)['state']);
        self::assertSame('PENDING', $this->readTransaction(self::stringValue($reviewed, 'id'))['state']);
    }

    public function testAPendingRefundRowIsRefusedAsAReconciliationMatch(): void
    {
        $refundRow = $this->seedPendingLinkedTransaction(self::OWN_ACCOUNT, '-128.45', asRefundOf: $this->seedBookedOriginal());
        $this->createPending(['bookedOn' => '2026-04-11', 'amount' => ['value' => '-42.00', 'assetCode' => 'EUR']]);
        $this->createPending(['bookedOn' => '2026-04-17', 'amount' => ['value' => '-42.00', 'assetCode' => 'EUR']]);
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14', 'amount' => ['value' => '-42.00', 'assetCode' => 'EUR']]);

        $this->reconcileRequest(self::stringValue($reviewed, 'id'), self::intValue($reviewed, 'version'), $refundRow);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('PENDING', $this->readTransaction($refundRow)['state']);
    }

    public function testAnOriginalWithALiveRefundIsRefusedAsAReconciliationMatch(): void
    {
        $original = $this->seedBookedOriginal();
        $this->seedPendingLinkedTransaction(self::OWN_ACCOUNT, '-30.00', asOriginalFor: $original);
        // The original itself is re-seeded PENDING directly: a domain rule
        // (refunds only ever target a BOOKED original) makes this state
        // otherwise unreachable, so the guard is exercised at the row it
        // protects rather than through a flow the application forbids.
        $this->connection->update('transaction_transactions', ['state' => 'PENDING'], [
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $original,
        ]);
        $this->createPending(['bookedOn' => '2026-04-11', 'amount' => ['value' => '-58.00', 'assetCode' => 'EUR']]);
        $this->createPending(['bookedOn' => '2026-04-17', 'amount' => ['value' => '-58.00', 'assetCode' => 'EUR']]);
        $reviewed = $this->createIncoming(['bookedOn' => '2026-04-14', 'amount' => ['value' => '-58.00', 'assetCode' => 'EUR']]);

        $this->reconcileRequest(self::stringValue($reviewed, 'id'), self::intValue($reviewed, 'version'), $original);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('PENDING', $this->readTransaction($original)['state']);
    }

    public function testBookingACandidateRemovesItFromEveryOtherReviewThatNamedIt(): void
    {
        $shared = $this->createPending(['bookedOn' => '2026-04-11']);
        $this->createPending(['bookedOn' => '2026-04-17']);
        $firstReview = $this->createIncoming(['bookedOn' => '2026-04-14']);
        self::assertSame('AMBIGUOUS_MATCH', $firstReview['reviewReason']);
        // A second delivery of the same real-world movement, while $shared and
        // its sibling are both still pending: it lands on the same ambiguity.
        $secondReview = $this->createIncoming(['bookedOn' => '2026-04-14']);
        $secondReviewCandidates = $secondReview['reconciliationCandidateIds'];
        self::assertIsArray($secondReviewCandidates);
        self::assertEqualsCanonicalizing($firstReview['reconciliationCandidateIds'], $secondReviewCandidates);
        self::assertContains($shared['id'], $secondReviewCandidates);

        $this->requestReconcile($firstReview, self::stringValue($shared, 'id'));

        $refreshed = $this->readTransaction(self::stringValue($secondReview, 'id'));
        $refreshedCandidates = $refreshed['reconciliationCandidateIds'];
        self::assertIsArray($refreshedCandidates);
        self::assertNotContains($shared['id'], $refreshedCandidates);
    }

    /**
     * Seeds a transfer's source leg directly on OWN_ACCOUNT, PENDING, of the
     * amount and day every other reconciliation fixture in this file uses, so
     * it lines up as an auto-match or a human override would expect. The
     * counterpart leg nets it to zero on the other own account, satisfying
     * the transfer invariant without going through the transfer endpoint.
     */
    private function seedPendingTransferLeg(?string $sourceRef = null): string
    {
        $legId = $this->seedPendingLinkedTransaction(self::OWN_ACCOUNT, '-128.45', sourceRef: $sourceRef);
        $counterpartId = '00000000-0000-7000-8000-0000000000e7';
        $this->connection->insert('transaction_transactions', [
            'id' => $counterpartId, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'account_id' => self::OWN_SECOND_ACCOUNT, 'asset_code' => 'EUR', 'amount_value' => '128.45',
            'amount_scale' => 2, 'state' => 'PENDING', 'nature' => 'TRANSFER', 'source' => 'MANUAL',
            'source_ref' => null, 'booked_on' => '2026-04-12', 'value_on' => null, 'authorized_on' => null,
            'raw_label' => 'Virement', 'counterparty' => null, 'note' => null, 'payment_method' => null,
            'mcc' => null, 'masked_card' => null, 'bank_reference' => null, 'version' => 1,
            'created_at' => '2026-04-12 09:12:04+00', 'updated_at' => '2026-04-12 09:12:04+00',
            'voided_at' => null, 'last_editor_id' => null, 'review_reason' => null,
        ]);
        $this->connection->insert('transaction_transfers', [
            'id' => '00000000-0000-7000-8000-0000000000e1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'source_transaction_id' => $legId, 'target_transaction_id' => $counterpartId, 'exchange_rate' => null,
            'version' => 1, 'created_at' => '2026-04-12 09:12:04+00', 'updated_at' => '2026-04-12 09:12:04+00', 'voided_at' => null,
        ]);

        return $legId;
    }

    /** A booked EXPENSE eligible to carry a refund, seeded directly to avoid a whole HTTP round trip. */
    private function seedBookedOriginal(): string
    {
        return $this->seedTransaction(self::OWN_ACCOUNT, '-70.00', 'BOOKED');
    }

    /**
     * Seeds a PENDING transaction, optionally linked into transaction_refunds
     * either as the refund row of `$asRefundOf` or as the original that
     * `$asOriginalFor` refunds.
     */
    private function seedPendingLinkedTransaction(
        string $accountId,
        string $amount,
        ?string $sourceRef = null,
        ?string $asRefundOf = null,
        ?string $asOriginalFor = null,
    ): string {
        $id = $this->seedTransaction($accountId, $amount, 'PENDING', $sourceRef);
        if (null !== $asRefundOf) {
            $this->connection->insert('transaction_refunds', [
                'id' => '00000000-0000-7000-8000-0000000000'.substr(md5($id.'refund'), 0, 2),
                'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
                'refund_transaction_id' => $id, 'original_transaction_id' => $asRefundOf,
                'created_at' => '2026-04-12 09:12:04+00',
            ]);
        }
        if (null !== $asOriginalFor) {
            $this->connection->insert('transaction_refunds', [
                'id' => '00000000-0000-7000-8000-0000000000'.substr(md5($id.'original'), 0, 2),
                'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
                'refund_transaction_id' => $id, 'original_transaction_id' => $asOriginalFor,
                'created_at' => '2026-04-12 09:12:04+00',
            ]);
        }

        return $id;
    }

    private function seedTransaction(string $accountId, string $amount, string $state, ?string $sourceRef = null): string
    {
        $id = self::randomId();
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'account_id' => $accountId, 'asset_code' => 'EUR', 'amount_value' => $amount,
            'amount_scale' => 2, 'state' => $state, 'nature' => 'EXPENSE', 'source' => 'PROVIDER',
            'source_ref' => $sourceRef, 'booked_on' => '2026-04-12', 'value_on' => null, 'authorized_on' => null,
            'raw_label' => 'CB CARREFOUR 1234', 'counterparty' => null, 'note' => null, 'payment_method' => null,
            'mcc' => null, 'masked_card' => null, 'bank_reference' => null, 'version' => 1,
            'created_at' => '2026-04-12 09:12:04+00', 'updated_at' => '2026-04-12 09:12:04+00',
            'voided_at' => null, 'last_editor_id' => null, 'review_reason' => null,
        ]);

        return $id;
    }

    private static int $randomIdCounter = 0;

    private static function randomId(): string
    {
        return sprintf('00000000-0000-7000-8000-%012d', ++self::$randomIdCounter + 900000000000);
    }

    /**
     * @param array<string, mixed> $reviewed
     *
     * @return array<string, mixed>
     */
    private function requestReconcile(array $reviewed, ?string $matchedTransactionId): array
    {
        $this->reconcileRequest(self::stringValue($reviewed, 'id'), self::intValue($reviewed, 'version'), $matchedTransactionId);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    private function reconcileRequest(string $id, int $version, ?string $matchedTransactionId): void
    {
        $this->client->request(
            'POST',
            '/api/v1/transactions/'.$id.'/reconcile',
            server: self::jsonHeaders(),
            content: json_encode(
                ['version' => $version, 'matchedTransactionId' => $matchedTransactionId],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createPending(array $overrides = []): array
    {
        return $this->create(['state' => 'PENDING', 'bookedOn' => '2026-04-12', ...$overrides]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createIncoming(array $overrides = []): array
    {
        return $this->create(['reconcile' => true, 'categoryId' => null, ...$overrides]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function create(array $overrides): array
    {
        $this->requestCreate($overrides);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    /** @param array<string, mixed> $overrides */
    private function requestCreate(array $overrides): void
    {
        $headers = self::jsonHeaders();
        // A provider movement always carries an idempotency key; each call here
        // is a distinct delivery, so each one carries its own.
        $headers['HTTP_IDEMPOTENCY_KEY'] = sprintf('reconciliation-delivery-%02d', ++$this->deliveries);
        $this->client->request('POST', '/api/v1/transactions', server: $headers, content: json_encode([
            'accountId' => self::OWN_ACCOUNT,
            'amount' => ['value' => '-128.45', 'assetCode' => 'EUR'],
            'nature' => 'EXPENSE',
            'state' => 'BOOKED',
            'bookedOn' => '2026-04-14',
            'valueOn' => null,
            'authorizedOn' => null,
            'rawLabel' => 'CB CARREFOUR 1234',
            'counterparty' => null,
            'note' => null,
            'paymentMethod' => 'CARD',
            'mcc' => null,
            'maskedCard' => null,
            'bankReference' => null,
            'categoryId' => self::OWN_EXPENSE,
            'splits' => null,
            'source' => 'PROVIDER',
            ...$overrides,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function readTransaction(string $id): array
    {
        $this->client->request('GET', '/api/v1/transactions/'.$id);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    private function countTransactions(): int
    {
        return $this->countWhere('true');
    }

    private function countBookedTransactions(): int
    {
        return $this->countWhere("state = 'BOOKED'");
    }

    private function countPendingTransactions(): int
    {
        return $this->countWhere("state = 'PENDING'");
    }

    private function countWhere(string $predicate): int
    {
        return (int) self::scalar($this->connection->fetchOne(
            'SELECT count(*) FROM transaction_transactions WHERE workspace_id = :workspace_id AND '.$predicate,
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE],
        ));
    }

    private function countAuditEvents(string $event): int
    {
        return (int) self::scalar($this->connection->fetchOne(
            'SELECT count(*) FROM audit_events WHERE workspace_id = :workspace_id AND event_type = :event',
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'event' => $event],
        ));
    }

    private static function scalar(mixed $value): string
    {
        self::assertIsScalar($value);

        return (string) $value;
    }

    private function seedForeignPending(): void
    {
        $this->connection->insert('transaction_transactions', [
            'id' => self::OTHER_TRANSACTION, 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE,
            'account_id' => self::OTHER_ACCOUNT, 'asset_code' => 'EUR', 'amount_value' => '-128.45',
            'amount_scale' => 2, 'state' => 'PENDING', 'nature' => 'EXPENSE', 'source' => 'PROVIDER',
            'source_ref' => null, 'booked_on' => '2026-04-12', 'value_on' => null, 'authorized_on' => null,
            'raw_label' => 'CB VOISIN', 'counterparty' => null, 'note' => null, 'payment_method' => null,
            'mcc' => null, 'masked_card' => null, 'bank_reference' => null, 'version' => 1,
            'created_at' => '2026-04-12 09:12:04+00', 'updated_at' => '2026-04-12 09:12:04+00',
            'voided_at' => null, 'last_editor_id' => null, 'review_reason' => null,
        ]);
    }

    private function seedAccount(string $id, string $workspace): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => 'Compte '.$id, 'asset_code' => 'EUR',
            'kind' => 'CURRENT', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => false,
            'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01', 'closed_on' => null,
            'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    private function seedCategory(string $id, string $workspace): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id, 'workspace_id' => $workspace, 'type' => 'EXPENSE', 'label' => 'Courses',
            'parent_id' => null, 'icon' => null, 'color' => null, 'default_analytic_axes' => '[]',
            'budget_included' => true, 'sort_order' => 0, 'depth' => 1, 'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ], [
            'budget_included' => ParameterType::BOOLEAN,
        ]);
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

    /** @param array<string, mixed> $body */
    private static function stringValue(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        self::assertIsString($value);

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function intValue(array $body, string $key): int
    {
        $value = $body[$key] ?? null;
        self::assertIsInt($value);

        return $value;
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
