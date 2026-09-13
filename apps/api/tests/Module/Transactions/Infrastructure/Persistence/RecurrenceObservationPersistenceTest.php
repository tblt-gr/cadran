<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Recurrence\RecurrenceDetector;
use App\Module\Transactions\Domain\Recurrence\RecurrenceObservation;
use App\Module\Transactions\Domain\Recurrence\RecurrenceObservationRepository;
use App\Module\Transactions\Domain\Recurrence\RecurrenceObservationWindow;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Infrastructure\Persistence\DbalRecurrenceObservationRepository;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecurrenceObservationPersistenceTest extends KernelTestCase
{
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string SCAN_DAY = '2026-03-14';
    private const string WINDOW_START = '2024-03-14';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private DbalTransactionRepository $transactions;
    private RecurrenceObservationRepository $repository;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->transactions = new DbalTransactionRepository($connection);
        $this->repository = new DbalRecurrenceObservationRepository($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $this->seedAccount(self::OWN_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testTheWindowNeverShowsAnotherWorkspaceMovement(): void
    {
        $this->add(1, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-04', '-14.99', 'Netflix');
        $this->add(2, WorkspaceFixture::other(), self::OTHER_ACCOUNT, '2026-03-05', '-14.99', 'Netflix');

        $window = $this->window();

        self::assertSame([$this->id(1)], $this->identifiers($window->observations));
        self::assertTrue($window->workspace->equals(WorkspaceFixture::own()));
    }

    public function testAVoidedMovementLeavesTheWindow(): void
    {
        $voided = $this->add(1, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-04', '-14.99', 'Netflix');
        self::assertTrue($this->transactions->update($voided->void(new \DateTimeImmutable('2026-03-15T10:00:00+00:00'), WorkspaceFixture::OWNER_ID), 1));

        self::assertSame([], $this->window()->observations);
    }

    public function testOnlyIncomeExpenseAndFeeMovementsAreScanned(): void
    {
        $this->add(1, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-01', '-14.99', 'Netflix', TransactionNature::EXPENSE);
        $this->add(2, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-02', '1000.00', 'Salaire', TransactionNature::INCOME);
        $this->add(3, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-03', '-2.00', 'Frais', TransactionNature::FEE);
        $this->add(4, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-04', '-5.00', 'Virement', TransactionNature::TRANSFER);
        $this->add(5, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-05', '-6.00', 'Ajustement', TransactionNature::ADJUSTMENT);

        self::assertSame(
            [$this->id(1), $this->id(2), $this->id(3)],
            $this->identifiers($this->window()->observations),
        );
    }

    public function testTheWindowStopsAtItsOldestDayAndKeepsThatDay(): void
    {
        $this->add(1, WorkspaceFixture::own(), self::OWN_ACCOUNT, self::WINDOW_START, '-14.99', 'Netflix');
        $this->add(2, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2024-03-13', '-14.99', 'Netflix');

        self::assertSame([$this->id(1)], $this->identifiers($this->window()->observations));
    }

    public function testAMovementIsGroupedByItsCounterpartyInLowercase(): void
    {
        $this->add(1, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-04', '-14.99', 'NetFlix');

        $observation = $this->window()->observations[0];
        self::assertSame('netflix', $observation->groupingKey);
        self::assertSame('NetFlix', $observation->displayName);
        self::assertSame(self::OWN_ACCOUNT, $observation->accountId);
        self::assertSame('-14.99', $observation->amount->value->toString());
        self::assertSame('EUR', $observation->amount->asset->toString());
        self::assertSame('2026-03-04', $observation->bookedOn->format('Y-m-d'));
    }

    public function testAMovementWithoutACounterpartyIsGroupedByItsNormalisedLabel(): void
    {
        $this->add(1, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-04', '-14.99', null, TransactionNature::EXPENSE, 'CB Spotify');

        $observation = $this->window()->observations[0];
        self::assertSame('cb spotify', $observation->groupingKey);
        self::assertSame('CB Spotify', $observation->displayName);
    }

    public function testReachingTheRowCapReturnsAPartialWindow(): void
    {
        $this->add(1, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-04', '-14.99', 'Netflix');
        $this->add(2, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-05', '-14.99', 'Netflix');
        $this->add(3, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-06', '-14.99', 'Netflix');

        $capped = $this->window(2);
        self::assertTrue($capped->partial);
        self::assertCount(2, $capped->observations);

        $whole = $this->window(3);
        self::assertFalse($whole->partial);
        self::assertCount(3, $whole->observations);
    }

    public function testTheScanBoundsAreTheOnesTheTicketStates(): void
    {
        self::assertSame(24, RecurrenceDetector::WINDOW_MONTHS);
        self::assertSame(5000, RecurrenceDetector::MAX_OBSERVATIONS);
    }

    private function window(int $limit = RecurrenceDetector::MAX_OBSERVATIONS): RecurrenceObservationWindow
    {
        return $this->repository->window(
            WorkspaceFixture::own(),
            new \DateTimeImmutable(self::WINDOW_START, new \DateTimeZone('UTC')),
            $limit,
        );
    }

    /**
     * @param list<RecurrenceObservation> $observations
     *
     * @return list<string>
     */
    private function identifiers(array $observations): array
    {
        $identifiers = array_map(static fn (RecurrenceObservation $o): string => $o->transactionId, $observations);
        sort($identifiers);

        return $identifiers;
    }

    private function id(int $index): string
    {
        return sprintf('00000000-0000-7000-8000-%012d', $index);
    }

    private function add(
        int $index,
        WorkspaceScope $workspace,
        string $accountId,
        string $bookedOn,
        string $amount,
        ?string $counterparty,
        TransactionNature $nature = TransactionNature::EXPENSE,
        string $rawLabel = 'CB TEST',
    ): Transaction {
        $now = new \DateTimeImmutable(self::SCAN_DAY.'T09:12:04+00:00');
        $transaction = new Transaction(
            id: $this->id($index), workspace: $workspace, accountId: $accountId,
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            originalAmount: null, exchangeRate: null, state: TransactionState::BOOKED, nature: $nature,
            source: TransactionSource::MANUAL, sourceRef: null,
            bookedOn: new \DateTimeImmutable($bookedOn, new \DateTimeZone('UTC')),
            valueOn: null, authorizedOn: null, rawLabel: $rawLabel, counterparty: $counterparty, note: null,
            paymentMethod: null, mcc: null, maskedCard: null, bankReference: null, splits: [],
            version: 1, createdAt: $now, updatedAt: $now, voidedAt: null, lastEditorId: null,
        );
        $this->transactions->add($transaction);

        return $transaction;
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
}
