<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\DuplicateSourceReference;
use App\Module\Transactions\Domain\Reconciliation\ReconciliationRepository;
use App\Module\Transactions\Domain\Reconciliation\ReviewReason;
use App\Module\Transactions\Domain\Reconciliation\TransactionReconciliation;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Infrastructure\Persistence\DbalReconciliationRepository;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReconciliationPersistenceTest extends KernelTestCase
{
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OWN_SECOND_ACCOUNT = '00000000-0000-7000-8000-0000000000d3';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private TransactionRepository $transactions;
    private ReconciliationRepository $reconciliations;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->transactions = new DbalTransactionRepository($connection);
        $this->reconciliations = new DbalReconciliationRepository($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $this->seedAccount(self::OWN_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedAccount(self::OWN_SECOND_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testAReviewReasonRoundTripsAndClearsWhenTheRowIsBooked(): void
    {
        $underReview = $this->pending('f1', self::OWN_ACCOUNT, '-128.45', reviewReason: ReviewReason::AMBIGUOUS_MATCH);
        $this->transactions->add($underReview);

        $stored = $this->transactions->find(WorkspaceFixture::own(), $underReview->id);
        self::assertNotNull($stored);
        self::assertSame(ReviewReason::AMBIGUOUS_MATCH, $stored->reviewReason);

        $booked = $stored->resolveReview()->edit(
            amount: $stored->amount, nature: $stored->nature, state: TransactionState::BOOKED,
            bookedOn: $stored->bookedOn, valueOn: null, authorizedOn: null, rawLabel: $stored->rawLabel,
            counterparty: null, note: null, paymentMethod: null, mcc: null, maskedCard: null,
            bankReference: null, splits: [], updatedAt: new \DateTimeImmutable('2026-04-15T10:00:00+00:00'),
            lastEditorId: WorkspaceFixture::OWNER_ID,
        );
        self::assertTrue($this->transactions->update($booked, 1));
        self::assertNull($this->transactions->find(WorkspaceFixture::own(), $underReview->id)?->reviewReason);
    }

    public function testOneSourceReferenceIsUniquePerAccountAndFreeElsewhere(): void
    {
        $this->transactions->add($this->pending('f1', self::OWN_ACCOUNT, '-128.45', sourceRef: 'PROV-4711'));
        // The same reference on another account, and in another workspace, is a
        // different provider's movement and stays accepted.
        $this->transactions->add($this->pending('f3', self::OWN_SECOND_ACCOUNT, '-128.45', sourceRef: 'PROV-4711'));
        $this->transactions->add($this->pending('f4', self::OTHER_ACCOUNT, '-128.45', sourceRef: 'PROV-4711', workspace: WorkspaceFixture::other()));
        // A null reference never collides with another null one.
        $this->transactions->add($this->pending('f5', self::OWN_ACCOUNT, '-128.45'));
        $this->transactions->add($this->pending('f6', self::OWN_ACCOUNT, '-128.45'));

        // The index refusal reaches the caller as a domain refusal it can map,
        // never as a driver exception surfacing to the client as a crash.
        $this->expectException(DuplicateSourceReference::class);
        $this->transactions->add($this->pending('f2', self::OWN_ACCOUNT, '-99.00', sourceRef: 'PROV-4711'));
    }

    public function testPendingCandidatesAreScopedToTheirWorkspaceAccountAndState(): void
    {
        $this->transactions->add($this->pending('f1', self::OWN_ACCOUNT, '-128.45'));
        $this->transactions->add($this->pending('f2', self::OWN_SECOND_ACCOUNT, '-128.45'));
        $this->transactions->add($this->pending('f3', self::OTHER_ACCOUNT, '-128.45', workspace: WorkspaceFixture::other()));
        $this->transactions->add($this->pending('f4', self::OWN_ACCOUNT, '-128.45', state: TransactionState::BOOKED));

        $amount = new AssetAmount(DecimalValue::fromString('-128.45'), AssetCode::fromString('EUR'));
        $candidates = $this->transactions->listPendingByAccount(
            WorkspaceFixture::own(), self::OWN_ACCOUNT, $amount, new \DateTimeImmutable('2026-04-12'), 5, 50, false,
        );

        self::assertSame(['00000000-0000-7000-8000-0000000000f1'], array_column($candidates, 'id'));
    }

    /**
     * A pending row whose amount or booked date falls outside the matching
     * window, or that carries a review reason, is not a settlement candidate
     * even though it is otherwise a plain pending row on this account.
     */
    public function testPendingCandidatesExcludeWrongAmountOutsideWindowAndUnderReview(): void
    {
        $this->transactions->add($this->pending('f1', self::OWN_ACCOUNT, '-128.45', bookedOn: '2026-04-12'));
        $this->transactions->add($this->pending('f2', self::OWN_ACCOUNT, '-99.00', bookedOn: '2026-04-12'));
        $this->transactions->add($this->pending('f3', self::OWN_ACCOUNT, '-128.45', bookedOn: '2026-04-05'));
        $this->transactions->add($this->pending('f4', self::OWN_ACCOUNT, '-128.45', bookedOn: '2026-04-12', reviewReason: ReviewReason::AMBIGUOUS_MATCH));

        $amount = new AssetAmount(DecimalValue::fromString('-128.45'), AssetCode::fromString('EUR'));
        $candidates = $this->transactions->listPendingByAccount(
            WorkspaceFixture::own(), self::OWN_ACCOUNT, $amount, new \DateTimeImmutable('2026-04-12'), 5, 50, false,
        );

        self::assertSame(['00000000-0000-7000-8000-0000000000f1'], array_column($candidates, 'id'));
    }

    /**
     * A pending row that is a transfer leg, a refund, or an original still
     * carrying a live refund can only change through its own operation, never
     * through a reconciliation settlement, so none of the three is offered as
     * a candidate even when its amount and date otherwise match exactly.
     */
    public function testPendingCandidatesExcludeTransferLegsRefundsAndLiveRefundOriginals(): void
    {
        $this->transactions->add($this->pending('f1', self::OWN_ACCOUNT, '-128.45'));
        $leg = $this->pending('f2', self::OWN_ACCOUNT, '-128.45');
        $this->transactions->add($leg);
        // A transfer's two legs must net to exactly zero on the same asset;
        // the counterpart sits on the other own account, carries the
        // opposite sign a TRANSFER nature allows either way, and is not
        // itself a candidate on OWN_ACCOUNT.
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
            'source_transaction_id' => $leg->id, 'target_transaction_id' => $counterpartId, 'exchange_rate' => null,
            'version' => 1, 'created_at' => '2026-04-12 09:12:04+00', 'updated_at' => '2026-04-12 09:12:04+00', 'voided_at' => null,
        ]);
        $refundRow = $this->pending('f3', self::OWN_ACCOUNT, '-128.45');
        $this->transactions->add($refundRow);
        $original = $this->pending('f4', self::OWN_ACCOUNT, '-128.45', state: TransactionState::BOOKED);
        $this->transactions->add($original);
        $this->connection->insert('transaction_refunds', [
            'id' => '00000000-0000-7000-8000-0000000000e2', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'refund_transaction_id' => $refundRow->id, 'original_transaction_id' => $original->id,
            'created_at' => '2026-04-12 09:12:04+00',
        ]);
        $liveRefundOriginal = $this->pending('f5', self::OWN_ACCOUNT, '-128.45');
        $this->transactions->add($liveRefundOriginal);
        $liveRefund = $this->pending('f6', self::OWN_ACCOUNT, '-30.00', state: TransactionState::BOOKED);
        $this->transactions->add($liveRefund);
        $this->connection->insert('transaction_refunds', [
            'id' => '00000000-0000-7000-8000-0000000000e3', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'refund_transaction_id' => $liveRefund->id, 'original_transaction_id' => $liveRefundOriginal->id,
            'created_at' => '2026-04-12 09:12:04+00',
        ]);

        $amount = new AssetAmount(DecimalValue::fromString('-128.45'), AssetCode::fromString('EUR'));
        $candidates = $this->transactions->listPendingByAccount(
            WorkspaceFixture::own(), self::OWN_ACCOUNT, $amount, new \DateTimeImmutable('2026-04-12'), 5, 50, false,
        );

        // f2 is a transfer leg, f3 is a refund row, and f5 is an original
        // still carrying a live refund (f6): none of the three is a valid
        // candidate, whatever its own amount and date. Only f1 remains.
        self::assertSame(['00000000-0000-7000-8000-0000000000f1'], array_column($candidates, 'id'));
    }

    public function testRemovingACandidateEverywhereClearsEveryOtherReviewThatNamedIt(): void
    {
        $this->transactions->add($this->pending('f1', self::OWN_ACCOUNT, '-128.45'));
        $this->transactions->add($this->pending('f2', self::OWN_ACCOUNT, '-9.99'));
        $reviewA = $this->pending('fa', self::OWN_ACCOUNT, '-128.45', reviewReason: ReviewReason::AMBIGUOUS_MATCH);
        $reviewB = $this->pending('fb', self::OWN_ACCOUNT, '-128.45', reviewReason: ReviewReason::AMBIGUOUS_MATCH);
        $this->transactions->add($reviewA);
        $this->transactions->add($reviewB);
        $at = new \DateTimeImmutable('2026-04-14T09:12:04+00:00');
        $candidateId = '00000000-0000-7000-8000-0000000000f1';
        $this->reconciliations->recordCandidates(WorkspaceFixture::own(), $reviewA->id, [$candidateId, '00000000-0000-7000-8000-0000000000f2'], $at);
        $this->reconciliations->recordCandidates(WorkspaceFixture::own(), $reviewB->id, [$candidateId], $at);

        $this->reconciliations->removeCandidateEverywhere(WorkspaceFixture::own(), $candidateId);

        self::assertSame(['00000000-0000-7000-8000-0000000000f2'], $this->reconciliations->candidateIds(WorkspaceFixture::own(), $reviewA->id));
        self::assertSame([], $this->reconciliations->candidateIds(WorkspaceFixture::own(), $reviewB->id));
    }

    public function testCandidatesAndTheirResolutionLinkRoundTripWithinOneWorkspace(): void
    {
        $reviewed = $this->pending('f1', self::OWN_ACCOUNT, '-128.45', reviewReason: ReviewReason::AMBIGUOUS_MATCH);
        $this->transactions->add($reviewed);
        $this->transactions->add($this->pending('f2', self::OWN_ACCOUNT, '-128.45'));
        $this->transactions->add($this->pending('f3', self::OWN_ACCOUNT, '-128.45'));
        $at = new \DateTimeImmutable('2026-04-14T09:12:04+00:00');
        $candidateIds = ['00000000-0000-7000-8000-0000000000f2', '00000000-0000-7000-8000-0000000000f3'];

        $this->reconciliations->recordCandidates(WorkspaceFixture::own(), $reviewed->id, $candidateIds, $at);

        self::assertSame($candidateIds, $this->reconciliations->candidateIds(WorkspaceFixture::own(), $reviewed->id));
        self::assertSame([], $this->reconciliations->candidateIds(WorkspaceFixture::other(), $reviewed->id));
        self::assertSame(
            [$reviewed->id => $candidateIds],
            $this->reconciliations->candidateIdsByTransactionIds(WorkspaceFixture::own(), [$reviewed->id]),
        );

        $this->reconciliations->link(new TransactionReconciliation(
            '00000000-0000-7000-8000-0000000000b9', WorkspaceFixture::own(), $reviewed->id, $candidateIds[0], $at,
        ));
        $this->reconciliations->clearCandidates(WorkspaceFixture::own(), $reviewed->id);

        self::assertSame([], $this->reconciliations->candidateIds(WorkspaceFixture::own(), $reviewed->id));
        $link = $this->reconciliations->findByReviewedTransactionId(WorkspaceFixture::own(), $reviewed->id);
        self::assertNotNull($link);
        self::assertSame($candidateIds[0], $link->matchedTransactionId);
        self::assertNull($this->reconciliations->findByReviewedTransactionId(WorkspaceFixture::other(), $reviewed->id));
    }

    public function testACandidateFromAnotherWorkspaceIsRejectedByTheSchema(): void
    {
        $this->transactions->add($this->pending('f1', self::OWN_ACCOUNT, '-128.45'));
        $this->transactions->add($this->pending('f3', self::OTHER_ACCOUNT, '-128.45', workspace: WorkspaceFixture::other()));

        $this->expectException(DbalException::class);
        $this->reconciliations->recordCandidates(
            WorkspaceFixture::own(),
            '00000000-0000-7000-8000-0000000000f1',
            ['00000000-0000-7000-8000-0000000000f3'],
            new \DateTimeImmutable('2026-04-14T09:12:04+00:00'),
        );
    }

    private function pending(
        string $suffix,
        string $accountId,
        string $amount,
        ?string $sourceRef = null,
        ?ReviewReason $reviewReason = null,
        TransactionState $state = TransactionState::PENDING,
        ?WorkspaceScope $workspace = null,
        string $bookedOn = '2026-04-12',
    ): Transaction {
        $now = new \DateTimeImmutable('2026-04-14T09:12:04+00:00');
        $value = new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR'));

        return new Transaction(
            id: '00000000-0000-7000-8000-0000000000'.$suffix, workspace: $workspace ?? WorkspaceFixture::own(),
            accountId: $accountId, amount: $value, originalAmount: null, exchangeRate: null, state: $state,
            nature: TransactionNature::EXPENSE, source: TransactionSource::PROVIDER, sourceRef: $sourceRef,
            bookedOn: new \DateTimeImmutable($bookedOn), valueOn: null, authorizedOn: null,
            rawLabel: 'CB CARREFOUR 1234', counterparty: null, note: null, paymentMethod: null, mcc: null,
            maskedCard: null, bankReference: null, splits: [], version: 1, createdAt: $now, updatedAt: $now,
            voidedAt: null, lastEditorId: null, reviewReason: $reviewReason,
        );
    }

    private function seedAccount(string $id, string $workspace): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => 'Compte '.$id, 'asset_code' => 'EUR',
            'kind' => 'CURRENT', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => false,
            'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01', 'closed_on' => null,
            'version' => 1, 'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }
}
