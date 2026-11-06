<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Transactions\Application\BulkEditChanges;
use App\Module\Transactions\Application\BulkPreviewTokenCodec;
use App\Tests\Support\ClosesPeriods;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

/** Moving several transactions to another account or day, behind a previewed balance impact. */
final class TransactionBulkMoveControllerTest extends WebTestCase
{
    use ClosesPeriods;

    private const string COURANT = '00000000-0000-7000-8000-0000000000d1';
    private const string JOINT = '00000000-0000-7000-8000-0000000000d5';
    private const string EPARGNE = '00000000-0000-7000-8000-0000000000d4';
    private const string DOLLARS = '00000000-0000-7000-8000-0000000000d6';
    private const string TITRES = '00000000-0000-7000-8000-0000000000d7';
    private const string LATE = '00000000-0000-7000-8000-0000000000d8';
    private const string ARCHIVED = '00000000-0000-7000-8000-0000000000d9';
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string UNKNOWN = '00000000-0000-7000-8000-0000000000e9';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $databaseReady = false;
    private int $sequence = 0;

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
        $this->seedAccount(self::COURANT, WorkspaceFixture::OWN_WORKSPACE, 'Courant');
        $this->seedAccount(self::JOINT, WorkspaceFixture::OWN_WORKSPACE, 'Joint');
        $this->seedAccount(self::EPARGNE, WorkspaceFixture::OWN_WORKSPACE, 'Epargne');
        $this->seedAccount(self::DOLLARS, WorkspaceFixture::OWN_WORKSPACE, 'Dollars', 'USD');
        $this->seedAccount(self::LATE, WorkspaceFixture::OWN_WORKSPACE, 'Tardif', 'EUR', '2026-11-01');
        $this->seedAccount(self::ARCHIVED, WorkspaceFixture::OWN_WORKSPACE, 'Archive', 'EUR', '2026-01-01', '2026-03-01 00:00:00+00');
        $this->seedAccount(self::FOREIGN_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Compte voisin');
        (new PortfolioFixture($connection))->account(self::TITRES, label: 'Titres');

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

    public function testThePreviewOfTheWorkedExampleAppliesToTheTwoEligibleRowsAndSkipsTheTransferLeg(): void
    {
        $a = $this->row('-42.10', '2026-10-03');
        $b = $this->row('-17.90', '2026-10-15');
        $leg = $this->transferLeg();

        $preview = $this->preview(['accountId' => self::JOINT], [$a, $b, $leg]);

        self::assertSame(2, $preview['eligibleCount']);
        self::assertSame([['id' => $leg, 'reason' => 'TRANSFER_LEG']], $preview['skipped']);
        self::assertSame([
            ['accountId' => self::COURANT, 'label' => 'Courant', 'assetCode' => 'EUR', 'delta' => '60.00', 'from' => '2026-10-03', 'to' => null],
            ['accountId' => self::JOINT, 'label' => 'Joint', 'assetCode' => 'EUR', 'delta' => '-60.00', 'from' => '2026-10-03', 'to' => null],
        ], $preview['impacts']);
        self::assertIsString($preview['previewToken']);
        // A preview writes nothing: no version, no audit event, no idempotency key.
        self::assertSame(1, $this->version($a));
        self::assertSame(self::COURANT, $this->column($a, 'account_id'));
        self::assertSame(0, $this->scalar("SELECT count(*) FROM audit_events WHERE event_type LIKE 'transactions.bulk%'"));
        self::assertSame(0, $this->scalar('SELECT count(*) FROM transaction_idempotency_keys'));
    }

    public function testConfirmingMovesTheRowsAuditsWithoutAmountsAndReplaysTheSameResult(): void
    {
        $a = $this->row('-42.10', '2026-10-03');
        $b = $this->row('-17.90', '2026-10-15');
        $token = $this->token(['accountId' => self::JOINT], [$a, $b]);

        $this->apply(['accountId' => self::JOINT], [$a, $b], $token, 'bulk-move-happy-0001');

        self::assertResponseStatusCodeSame(200);
        $result = $this->decode();
        self::assertSame(2, $result['changed']);
        self::assertSame(['accountId'], $result['fieldsChanged']);
        self::assertSame([self::COURANT, self::JOINT], $result['impactedAccountIds']);
        foreach ([$a, $b] as $id) {
            self::assertSame(self::JOINT, $this->column($id, 'account_id'));
            self::assertSame(2, $this->version($id));
        }
        // Amount, sign and asset are untouched by a move.
        self::assertSame('-42.100000000000000000000000', $this->column($a, 'amount_value') ?? '');
        $events = $this->connection->fetchAllAssociative("SELECT event_type, before_json, after_json FROM audit_events WHERE workspace_id = ? AND event_type = 'transactions.bulk_moved'", [WorkspaceFixture::OWN_WORKSPACE]);
        self::assertCount(1, $events);
        $json = json_encode($events, JSON_THROW_ON_ERROR);
        self::assertStringContainsString(self::JOINT, $json);
        self::assertStringContainsString('accountId', $json);
        self::assertStringNotContainsString('42.1', $json);
        self::assertStringNotContainsString('60', $json);

        $this->apply(['accountId' => self::JOINT], [$a, $b], $token, 'bulk-move-happy-0001');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertEquals($result, $this->decode());
        self::assertSame(2, $this->version($a));
    }

    public function testAnApplyWithoutATokenIsRefusedForAnAccountAndForADateChange(): void
    {
        $a = $this->row('-10.00', '2026-10-03');

        $this->apply(['accountId' => self::JOINT], [$a], null, 'bulk-move-no-token-01');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/transactions.bulk-preview-required', $this->decode()['type']);

        $this->apply(['bookedOn' => '2026-10-04'], [$a], null, 'bulk-move-no-token-02');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/transactions.bulk-preview-required', $this->decode()['type']);
        self::assertSame(1, $this->version($a));
    }

    public function testAnEditOfARowSincePreviewMakesTheTokenStaleAndNothingIsWritten(): void
    {
        $a = $this->row('-10.00', '2026-10-03');
        $b = $this->row('-11.00', '2026-10-04');
        $token = $this->token(['accountId' => self::JOINT], [$a, $b]);
        // B carries the highest version, so a token bound to the maximum alone would miss this edit of A.
        $this->connection->executeStatement('UPDATE transaction_transactions SET version = 9 WHERE id = ?', [$b]);
        $token = $this->token(['accountId' => self::JOINT], [$a, $b]);
        $this->connection->executeStatement('UPDATE transaction_transactions SET version = 5 WHERE id = ?', [$a]);

        $this->apply(['accountId' => self::JOINT], [$a, $b], $token, 'bulk-move-stale-0001');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/transactions.bulk-preview-stale', $this->decode()['type']);
        self::assertSame(self::COURANT, $this->column($b, 'account_id'));
        self::assertSame(0, $this->scalar("SELECT count(*) FROM audit_events WHERE event_type = 'transactions.bulk_moved'"));
    }

    public function testATokenOfAnotherScopeOrOtherChangesIsStale(): void
    {
        $a = $this->row('-10.00', '2026-10-03');
        $b = $this->row('-11.00', '2026-10-04');
        $token = $this->token(['accountId' => self::JOINT], [$a]);

        $this->apply(['accountId' => self::JOINT], [$a, $b], $token, 'bulk-move-scope-00001');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/transactions.bulk-preview-stale', $this->decode()['type']);

        $this->apply(['accountId' => self::EPARGNE], [$a], $token, 'bulk-move-scope-00002');
        self::assertResponseStatusCodeSame(409);

        $this->apply(['accountId' => self::JOINT], [$a], 'forged.token', 'bulk-move-scope-00003');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/transactions.bulk-preview-stale', $this->decode()['type']);
    }

    public function testARowInAClosedMonthOrMovedIntoOneIsSkippedAndUnchanged(): void
    {
        $closed = $this->row('-10.00', '2026-09-10');
        $open = $this->row('-11.00', '2026-10-10');
        $this->closeMonthInDatabase($this->connection, 2026, 9);

        $preview = $this->preview(['accountId' => self::JOINT], [$closed, $open]);
        self::assertSame(1, $preview['eligibleCount']);
        self::assertSame([['id' => $closed, 'reason' => 'CLOSED_PERIOD']], $preview['skipped']);

        $into = $this->preview(['bookedOn' => '2026-09-20'], [$open]);
        self::assertSame(0, $into['eligibleCount']);
        self::assertSame([['id' => $open, 'reason' => 'CLOSED_PERIOD']], $into['skipped']);
        self::assertSame([], $into['impacts']);

        $token = $this->token(['bookedOn' => '2026-09-20'], [$open]);
        $this->apply(['bookedOn' => '2026-09-20'], [$open], $token, 'bulk-move-closed-0001');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->decode()['changed']);
        self::assertSame('2026-10-10', $this->column($open, 'booked_on'));
        self::assertSame(1, $this->version($open));
    }

    public function testEveryRefusalReasonOfAMoveIsReported(): void
    {
        $asset = $this->row('-1.00', '2026-10-03');
        $same = $this->row('-2.00', '2026-10-03');
        $pending = $this->row('-3.00', '2026-10-03', state: 'PENDING', reviewReason: 'AMBIGUOUS_MATCH');
        $linked = $this->row('-4.00', '2026-10-03');
        $matched = $this->row('-5.00', '2026-10-03');
        $voided = $this->row('-6.00', '2026-10-03', state: 'VOIDED');
        $this->connection->insert('transaction_reconciliations', [
            'id' => '00000000-0000-7000-8000-00000000f001', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'reviewed_transaction_id' => $voided, 'matched_transaction_id' => $matched, 'created_at' => '2026-10-04 00:00:00+00',
        ]);
        $this->linkToPortfolio($linked);

        $this->assertReasons(['accountId' => self::DOLLARS], [$asset => 'ASSET_MISMATCH']);
        $this->assertReasons(['accountId' => self::LATE], [$asset => 'ACCOUNT_NOT_OPEN']);
        $this->assertReasons(['accountId' => self::TITRES], [$asset => 'PORTFOLIO_ACCOUNT']);
        $this->assertReasons(['accountId' => self::COURANT], [$asset => 'ALREADY_ON_ACCOUNT']);
        $reasons = $this->preview(['accountId' => self::JOINT], [$same, $pending, $linked, $matched, $voided]);
        self::assertSame(1, $reasons['eligibleCount']);
        $byId = self::columnOf($reasons['skipped'], 'reason', 'id');
        self::assertSame('UNDER_REVIEW', $byId[$pending]);
        self::assertSame('PORTFOLIO_LINKED', $byId[$linked]);
        self::assertSame('RECONCILED', $byId[$matched]);
        self::assertSame('TERMINAL', $byId[$voided]);
    }

    public function testADateInTheFutureIsRefusedForABookedRowAndAllowedForAPendingOne(): void
    {
        $booked = $this->row('-1.00', '2026-10-03');
        $pending = $this->row('-2.00', '2026-10-03', state: 'PENDING');

        $preview = $this->preview(['bookedOn' => '2030-01-01'], [$booked, $pending]);

        self::assertSame(1, $preview['eligibleCount']);
        self::assertSame([['id' => $booked, 'reason' => 'FUTURE_BOOKED']], $preview['skipped']);
    }

    public function testARefundCannotBeDatedBeforeItsOriginal(): void
    {
        $original = $this->row('-80.00', '2026-10-10');
        $this->client->request('POST', '/api/v1/transactions/'.$original.'/refunds', server: self::headers(), content: json_encode([
            'accountId' => self::COURANT, 'amount' => ['value' => '30.00', 'assetCode' => 'EUR'],
            'bookedOn' => '2026-10-12', 'rawLabel' => 'Remboursement', 'counterparty' => null, 'note' => null,
        ], JSON_THROW_ON_ERROR));
        $refund = self::text($this->decode()['id']);

        $preview = $this->preview(['bookedOn' => '2026-10-09'], [$refund]);

        self::assertSame([['id' => $refund, 'reason' => 'REFUND']], $preview['skipped']);
        self::assertSame(1, $this->preview(['bookedOn' => '2026-10-20'], [$refund])['eligibleCount']);
    }

    public function testADateChangeAloneLeavesTheBalanceUnchangedAtTermAndFollowsTheValueDate(): void
    {
        $a = $this->row('-42.10', '2026-10-03', valueOn: '2026-10-03');
        $b = $this->row('-17.90', '2026-10-15', valueOn: '2026-10-16');
        $same = $this->row('-1.00', '2026-10-20');

        $preview = $this->preview(['bookedOn' => '2026-10-20'], [$a, $b, $same]);

        self::assertSame(3, $preview['eligibleCount']);
        self::assertSame([
            ['accountId' => self::COURANT, 'label' => 'Courant', 'assetCode' => 'EUR', 'delta' => '0', 'from' => '2026-10-03', 'to' => '2026-10-20'],
        ], $preview['impacts']);

        $token = $this->token(['bookedOn' => '2026-10-20'], [$a, $b, $same]);
        $this->apply(['bookedOn' => '2026-10-20'], [$a, $b, $same], $token, 'bulk-move-date-only-1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['bookedOn'], $this->decode()['fieldsChanged']);
        self::assertSame('2026-10-20', $this->column($a, 'booked_on'));
        self::assertSame('2026-10-20', $this->column($a, 'value_on'));
        self::assertSame('2026-10-16', $this->column($b, 'value_on'));
        self::assertSame(self::COURANT, $this->column($a, 'account_id'));
    }

    public function testAnAccountAndADateChangeTogetherUseTheNewDatesOnTheTarget(): void
    {
        $a = $this->row('-42.10', '2026-10-03');
        $b = $this->row('-17.90', '2026-10-15');

        $preview = $this->preview(['accountId' => self::JOINT, 'bookedOn' => '2026-10-20'], [$a, $b]);

        self::assertSame([
            ['accountId' => self::COURANT, 'label' => 'Courant', 'assetCode' => 'EUR', 'delta' => '60.00', 'from' => '2026-10-03', 'to' => null],
            ['accountId' => self::JOINT, 'label' => 'Joint', 'assetCode' => 'EUR', 'delta' => '-60.00', 'from' => '2026-10-20', 'to' => null],
        ], $preview['impacts']);
    }

    public function testHugeTinyAndOffsettingAmountsStayExactAndAZeroNetAccountIsStillListed(): void
    {
        $huge = $this->row('-9999999999999999999999.9999', '2026-10-03');
        $tiny = $this->row('-0.0001', '2026-10-04');
        $income = $this->row('10000000000000000000000.0000', '2026-10-05', nature: 'INCOME');

        $preview = $this->preview(['accountId' => self::JOINT], [$huge, $tiny, $income]);

        // 10000000000000000000000.0000 - 9999999999999999999999.9999 - 0.0001 = 0 exactly, listed on both accounts.
        self::assertSame(['0.0000', '0.0000'], self::columnOf($preview['impacts'], 'delta'));
    }

    public function testATargetOfAnotherWorkspaceUnknownOrArchivedRefusesTheWholeRequest(): void
    {
        $a = $this->row('-10.00', '2026-10-03');
        foreach ([self::FOREIGN_ACCOUNT, self::UNKNOWN, self::ARCHIVED] as $target) {
            $this->request(['accountId' => $target], [$a], null, preview: true);
            self::assertResponseStatusCodeSame(404);
            $this->request(['accountId' => $target], [$a], 'whatever.token', 'bulk-move-foreign-'.substr($target, -2), preview: false);
            self::assertResponseStatusCodeSame(404);
        }
        self::assertSame(self::COURANT, $this->column($a, 'account_id'));
        self::assertSame(1, $this->version($a));
    }

    public function testAForeignTransactionIsReportedNotFoundAndNeverMoved(): void
    {
        $foreign = '00000000-0000-7000-8000-0000000000f9';
        $this->connection->insert('transaction_transactions', [
            'id' => $foreign, 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE, 'account_id' => self::FOREIGN_ACCOUNT,
            'asset_code' => 'EUR', 'amount_value' => '-5.00', 'amount_scale' => 2, 'state' => 'BOOKED', 'nature' => 'EXPENSE',
            'source' => 'MANUAL', 'booked_on' => '2026-10-03', 'raw_label' => 'Prive', 'version' => 1,
            'created_at' => '2026-10-03 09:00:00+00', 'updated_at' => '2026-10-03 09:00:00+00',
        ]);

        $preview = $this->preview(['accountId' => self::JOINT], [$foreign]);

        self::assertSame(0, $preview['eligibleCount']);
        self::assertSame([['id' => $foreign, 'reason' => 'NOT_FOUND']], $preview['skipped']);
        self::assertSame([], $preview['impacts']);
    }

    public function testAMoveBeyondFiveHundredRowsAndAPreviewWithoutAMoveAreRefused(): void
    {
        $ids = [];
        for ($i = 0; $i < 501; ++$i) {
            $ids[] = sprintf('00000000-0000-7000-8000-%012d', 700000 + $i);
        }
        $this->request(['accountId' => self::JOINT], $ids, null, preview: true);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/transactions.bulk-scope-too-large', $this->decode()['type']);

        $this->request(['note' => 'x'], [$this->row('-1.00', '2026-10-03')], null, preview: true);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAMoveCombinedWithATextEditRefusesTheWholeRowWhenItsMoveIsRefused(): void
    {
        $movable = $this->row('-1.00', '2026-10-03');
        $leg = $this->transferLeg();
        $closed = $this->row('-2.00', '2026-09-03');
        $this->closeMonthInDatabase($this->connection, 2026, 9);
        $changes = ['accountId' => self::JOINT, 'note' => 'deplace'];
        $token = $this->token($changes, [$movable, $closed]);

        $this->apply($changes, [$movable, $closed], $token, 'bulk-move-mixed-00001');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['note', 'accountId'], $this->decode()['fieldsChanged']);
        self::assertSame('deplace', $this->column($movable, 'note'));
        self::assertNull($this->column($closed, 'note'));
        self::assertSame(self::COURANT, $this->column($closed, 'account_id'));
        self::assertNull($this->column($leg, 'note'));
    }

    public function testAFilterScopeCanBePreviewedAndApplied(): void
    {
        $a = $this->row('-1.00', '2026-10-03');
        $this->row('-2.00', '2026-10-04');
        $scope = ['type' => 'FILTER', 'filters' => ['from' => '2026-10-03', 'to' => '2026-10-03', 'accountIds' => [self::COURANT]], 'expectedCount' => 1];
        $this->post(['changes' => ['accountId' => self::JOINT], 'scope' => $scope, 'preview' => true], null);
        self::assertResponseStatusCodeSame(200);
        $token = self::text($this->decode()['previewToken']);

        $this->post(['changes' => ['accountId' => self::JOINT], 'scope' => $scope, 'previewToken' => $token], 'bulk-move-filter-0001');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->decode()['changed']);
        self::assertSame(self::JOINT, $this->column($a, 'account_id'));
    }

    public function testATokenIssuedMoreThanTenMinutesAgoIsStale(): void
    {
        $a = $this->row('-1.00', '2026-10-03');
        $changes = ['accountId' => self::JOINT];
        $secret = $_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? getenv('APP_SECRET');
        self::assertIsString($secret);
        // Same secret and a valid signature, minted by a clock that stopped well before now.
        $past = new MockClock('2026-01-01 10:00:00');
        $expired = (new BulkPreviewTokenCodec($secret, $past))->issue(
            WorkspaceScope::fromString(WorkspaceFixture::OWN_WORKSPACE), [$a],
            new BulkEditChanges(accountId: self::JOINT), [$a => 1],
        );
        $fresh = $this->token($changes, [$a]);
        self::assertNotSame($expired, $fresh);

        $this->apply($changes, [$a], $expired, 'bulk-move-expired-0001');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/transactions.bulk-preview-stale', $this->decode()['type']);
        self::assertSame(self::COURANT, $this->column($a, 'account_id'));

        $this->apply($changes, [$a], $fresh, 'bulk-move-expired-0002');
        self::assertResponseStatusCodeSame(200);
    }

    public function testApplyStillRequiresAnIdempotencyKey(): void
    {
        $a = $this->row('-1.00', '2026-10-03');
        $token = $this->token(['accountId' => self::JOINT], [$a]);

        $this->apply(['accountId' => self::JOINT], [$a], $token, null);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::COURANT, $this->column($a, 'account_id'));
    }

    /**
     * @param array<string, mixed>  $changes
     * @param array<string, string> $expected reason by identifier
     */
    private function assertReasons(array $changes, array $expected): void
    {
        $preview = $this->preview($changes, array_keys($expected));
        self::assertSame(self::columnOf($preview['skipped'], 'reason', 'id'), $expected);
    }

    /**
     * @param array<string, mixed> $changes
     * @param list<string>         $ids
     *
     * @return array<string, mixed>
     */
    private function preview(array $changes, array $ids): array
    {
        $this->request($changes, $ids, null, preview: true);
        self::assertResponseStatusCodeSame(200);

        return $this->decode();
    }

    /**
     * @param array<string, mixed> $changes
     * @param list<string>         $ids
     */
    private function token(array $changes, array $ids): string
    {
        return self::text($this->preview($changes, $ids)['previewToken']);
    }

    /**
     * @param array<string, mixed> $changes
     * @param list<string>         $ids
     */
    private function apply(array $changes, array $ids, ?string $token, ?string $key): void
    {
        $this->request($changes, $ids, $token, $key, preview: false);
    }

    /**
     * @param array<string, mixed> $changes
     * @param list<string>         $ids
     */
    private function request(array $changes, array $ids, ?string $token, ?string $key = null, bool $preview = false): void
    {
        $body = ['changes' => $changes, 'scope' => ['type' => 'IDS', 'ids' => $ids]];
        if ($preview) {
            $body['preview'] = true;
        }
        if (null !== $token) {
            $body['previewToken'] = $token;
        }
        $this->post($body, $key);
    }

    /** @param array<string, mixed> $body */
    private function post(array $body, ?string $key): void
    {
        $headers = self::headers();
        if (null !== $key) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        $this->client->request('POST', '/api/v1/transactions/bulk-edit', server: $headers, content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function row(string $amount, string $bookedOn, string $state = 'BOOKED', string $nature = 'EXPENSE', ?string $reviewReason = null, ?string $valueOn = null): string
    {
        $id = sprintf('00000000-0000-7000-8000-%012d', 800000 + ++$this->sequence);
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => self::COURANT,
            'asset_code' => 'EUR', 'amount_value' => $amount, 'amount_scale' => strlen(substr(strrchr($amount, '.') ?: '', 1)),
            'state' => $state, 'nature' => $nature, 'source' => 'MANUAL', 'booked_on' => $bookedOn, 'value_on' => $valueOn,
            'raw_label' => 'CB CARREFOUR', 'review_reason' => $reviewReason, 'voided_at' => 'VOIDED' === $state ? '2026-10-04 00:00:00+00' : null,
            'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ]);

        return $id;
    }

    private function transferLeg(): string
    {
        $this->client->request('POST', '/api/v1/transfers', server: self::headers(), content: json_encode([
            'sourceAccountId' => self::COURANT, 'targetAccountId' => self::EPARGNE,
            'sourceAmount' => ['value' => '100.00', 'assetCode' => 'EUR'], 'targetAmount' => ['value' => '100.00', 'assetCode' => 'EUR'],
            'state' => 'BOOKED', 'bookedOn' => '2026-10-03', 'valueOn' => null, 'label' => 'Epargne', 'note' => null, 'fee' => null,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return self::text($this->connection->fetchOne('SELECT source_transaction_id FROM transaction_transfers WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE]));
    }

    private function linkToPortfolio(string $transactionId): void
    {
        $this->connection->insert('portfolio_operations', [
            'id' => '00000000-0000-7000-8000-00000000f002', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => self::TITRES,
            'operation_type' => 'DEPOSIT', 'trade_date' => '2026-10-03', 'sequence' => 1, 'asset_code' => 'EUR',
            'fees_value' => '0', 'fees_scale' => 0, 'taxes_value' => '0', 'taxes_scale' => 0, 'cash_amount_value' => '4', 'cash_amount_scale' => 0,
            'linked_transaction_id' => $transactionId, 'state' => 'ACTIVE', 'created_at' => '2026-10-03 09:00:00+00',
            'created_by' => WorkspaceFixture::OWNER_ID, 'version' => 1,
        ]);
    }

    private function column(string $id, string $column): ?string
    {
        $value = $this->connection->fetchOne(sprintf('SELECT %s FROM transaction_transactions WHERE id = ?', $column), [$id]);

        return false === $value || null === $value ? null : self::text(is_scalar($value) ? (string) $value : null);
    }

    private function version(string $id): int
    {
        return (int) $this->column($id, 'version');
    }

    private function scalar(string $sql): int
    {
        $value = $this->connection->fetchOne($sql);
        self::assertTrue(is_int($value) || is_string($value));

        return (int) $value;
    }

    /** @return array<array-key, mixed> */
    private static function columnOf(mixed $rows, string $column, ?string $index = null): array
    {
        self::assertIsArray($rows);

        return array_column($rows, $column, $index);
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }

    private function seedAccount(string $id, string $workspace, string $label, string $assetCode = 'EUR', string $openedOn = '2026-01-01', ?string $archivedAt = null): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => $label, 'asset_code' => $assetCode, 'kind' => 'CURRENT',
            'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => false, 'include_in_emergency_fund' => false, 'opened_on' => $openedOn, 'closed_on' => null,
            'archived_at' => $archivedAt, 'version' => 1, 'created_at' => '2026-11-02 09:00:00+00', 'updated_at' => '2026-11-02 09:00:00+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
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
