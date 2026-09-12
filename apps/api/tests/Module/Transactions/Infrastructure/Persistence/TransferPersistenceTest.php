<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Accounts\Infrastructure\Persistence\DbalAccountRepository;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Infrastructure\Persistence\DbalAuditEventRepository;
use App\Module\Categories\Infrastructure\Persistence\DbalCategoryRepository;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Identity\Infrastructure\Persistence\DbalTransactionManager;
use App\Module\Reference\Infrastructure\Persistence\DbalAssetCatalog;
use App\Module\Transactions\Application\CreateTransfer;
use App\Module\Transactions\Application\CreateTransferInput;
use App\Module\Transactions\Application\PresentTransaction;
use App\Module\Transactions\Application\PresentTransfer;
use App\Module\Transactions\Application\TransferInputParser;
use App\Module\Transactions\Application\TransferLegFactory;
use App\Module\Transactions\Application\TransferReferences;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransferRepository;
use App\Tests\Module\Accounts\Application\Double\SequenceUuidGenerator;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Proves the invariants a controller test cannot reach: a failure between
 * the legs leaves neither behind, and the database itself — not only the
 * domain — refuses a same-asset pair that does not net to exactly zero.
 */
final class TransferPersistenceTest extends KernelTestCase
{
    private const string OWN_SOURCE_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OWN_TARGET_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';

    private Connection $connection;
    private WorkspaceFixture $fixture;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $this->seedAccount(self::OWN_SOURCE_ACCOUNT, 'Compte courant');
        $this->seedAccount(self::OWN_TARGET_ACCOUNT, 'Épargne');
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testAFailureWritingTheTargetLegRollsBackTheSourceLegAndTheTransferRow(): void
    {
        $create = new CreateTransfer(
            caller: $this->callerContext(),
            transactions: new FailOnSecondAddTransactionRepository(new DbalTransactionRepository($this->connection)),
            transfers: new DbalTransferRepository($this->connection),
            references: $this->references(),
            legFactory: $this->legFactory(),
            parser: new TransferInputParser(),
            uuidGenerator: new SequenceUuidGenerator(),
            transactionBoundary: new DbalTransactionManager($this->connection),
            recordAuditEvent: $this->recordAuditEvent(),
            presentTransfer: $this->presentTransfer(),
            clock: new MockClock('2026-03-14T10:00:00+00:00'),
        );

        try {
            $create($this->input());
            self::fail('The simulated failure on the target leg should have propagated.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated persistence failure.', $exception->getMessage());
        }

        self::assertSame(0, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        ));
        self::assertSame(0, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM transaction_transfers WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        ));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM audit_events'));
    }

    public function testTheDatabaseRefusesASameAssetPairThatDoesNotNetToZero(): void
    {
        $this->expectException(DbalException::class);

        $this->connection->transactional(function (Connection $connection): void {
            $sourceId = '00000000-0000-7000-8000-0000000000f1';
            $targetId = '00000000-0000-7000-8000-0000000000f2';
            $this->insertLeg($connection, $sourceId, self::OWN_SOURCE_ACCOUNT, '-500.00');
            // A mismatched target: -500.00 does not net to zero against 500.01.
            $this->insertLeg($connection, $targetId, self::OWN_TARGET_ACCOUNT, '500.01');
            $connection->insert('transaction_transfers', [
                'id' => '00000000-0000-7000-8000-0000000000f3',
                'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
                'source_transaction_id' => $sourceId,
                'target_transaction_id' => $targetId,
                'version' => 1,
                'created_at' => '2026-03-14 10:00:00+00',
                'updated_at' => '2026-03-14 10:00:00+00',
            ]);
        });
    }

    /**
     * The two accounts are locked in ascending identifier order regardless of
     * which side the caller names source or target. Proving this directly is
     * what proves two concurrent transfers between the same pair cannot
     * deadlock: named in opposite role order, they still contend for the
     * same first lock, so the second one waits cleanly instead of each
     * holding one account and waiting on the other.
     */
    public function testConcurrentTransfersNamingTheSamePairInOppositeRoleOrderContendForTheSameFirstLock(): void
    {
        $now = new \DateTimeImmutable('2026-03-14T10:00:00+00:00');
        $bookedOn = new \DateTimeImmutable('2026-03-14');
        $second = DriverManager::getConnection($this->connection->getParams());

        try {
            $this->connection->beginTransaction();
            // source=SOURCE (…d1), target=TARGET (…d2): ascending order locks
            // d1 then d2, so this connection now holds both.
            $this->references()->lockForCreation(
                WorkspaceFixture::own(), self::OWN_SOURCE_ACCOUNT, self::OWN_TARGET_ACCOUNT, $bookedOn, $bookedOn, $now,
            );

            $second->beginTransaction();
            $second->executeStatement("SET LOCAL lock_timeout = '100ms'");
            $secondReferences = new TransferReferences(new DbalAccountRepository($second));

            try {
                // The opposite role order (source=TARGET, target=SOURCE) still
                // sorts to the same ascending sequence, so it contends for the
                // account this connection already holds — not the other one.
                $secondReferences->lockForCreation(
                    WorkspaceFixture::own(), self::OWN_TARGET_ACCOUNT, self::OWN_SOURCE_ACCOUNT, $bookedOn, $bookedOn, $now,
                );
                self::fail('The concurrent transfer should wait for the row lock held by the first one.');
            } catch (DbalException $exception) {
                self::assertStringContainsString('lock timeout', $exception->getMessage());
            }
        } finally {
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $second->close();
        }
    }

    public function testTheDatabaseRefusesASplitOnATransferLeg(): void
    {
        $legId = '00000000-0000-7000-8000-0000000000f4';
        $this->insertLeg($this->connection, $legId, self::OWN_SOURCE_ACCOUNT, '-500.00');

        $this->expectException(DbalException::class);
        $this->connection->insert('transaction_splits', [
            'id' => '00000000-0000-7000-8000-0000000000f5',
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'transaction_id' => $legId,
            'category_id' => '00000000-0000-7000-8000-0000000000c1',
            'amount_value' => '-500.00',
            'amount_scale' => 2,
            'asset_code' => 'EUR',
            'analytic_axes' => '[]',
            'created_at' => '2026-03-14 10:00:00+00',
        ]);
    }

    private function insertLeg(Connection $connection, string $id, string $accountId, string $amount): void
    {
        $connection->insert('transaction_transactions', [
            'id' => $id,
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'account_id' => $accountId,
            'asset_code' => 'EUR',
            'amount_value' => $amount,
            'amount_scale' => 2,
            'state' => 'BOOKED',
            'nature' => 'TRANSFER',
            'source' => 'MANUAL',
            'booked_on' => '2026-03-14',
            'raw_label' => 'Virement épargne',
            'version' => 1,
            'created_at' => '2026-03-14 10:00:00+00',
            'updated_at' => '2026-03-14 10:00:00+00',
        ]);
    }

    private function seedAccount(string $id, string $label): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'label' => $label,
            'asset_code' => 'EUR',
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

    private function callerContext(): CallerWorkspaceContext
    {
        return new class implements CallerWorkspaceContext {
            public function resolveContext(): WorkspaceContext
            {
                return new WorkspaceContext(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID);
            }
        };
    }

    private function references(): TransferReferences
    {
        return new TransferReferences(new DbalAccountRepository($this->connection));
    }

    private function legFactory(): TransferLegFactory
    {
        return new TransferLegFactory(new AmountInputParser(new DbalAssetCatalog($this->connection)));
    }

    private function recordAuditEvent(): RecordAuditEvent
    {
        return new RecordAuditEvent(new DbalAuditEventRepository($this->connection), new SequenceUuidGenerator());
    }

    private function presentTransfer(): PresentTransfer
    {
        $transactions = new DbalTransactionRepository($this->connection);
        $transfers = new DbalTransferRepository($this->connection);

        return new PresentTransfer($transactions, new PresentTransaction(new DbalCategoryRepository($this->connection), $transfers));
    }

    private function input(): CreateTransferInput
    {
        return new CreateTransferInput(
            sourceAccountId: self::OWN_SOURCE_ACCOUNT,
            targetAccountId: self::OWN_TARGET_ACCOUNT,
            sourceAmount: ['value' => '500.00', 'assetCode' => 'EUR'],
            targetAmount: ['value' => '500.00', 'assetCode' => 'EUR'],
            state: 'BOOKED',
            bookedOn: '2026-03-14',
            valueOn: null,
            label: 'Virement épargne',
            note: null,
            fee: null,
        );
    }
}

/**
 * Fails on the second leg written (the target), so a test can prove the
 * source leg written just before it does not survive the rollback.
 */
final class FailOnSecondAddTransactionRepository implements TransactionRepository
{
    private int $addCount = 0;

    public function __construct(private readonly TransactionRepository $inner)
    {
    }

    public function find(\App\Module\Foundation\Domain\WorkspaceScope $workspace, string $id): ?Transaction
    {
        return $this->inner->find($workspace, $id);
    }

    public function findForUpdate(\App\Module\Foundation\Domain\WorkspaceScope $workspace, string $id): ?Transaction
    {
        return $this->inner->findForUpdate($workspace, $id);
    }

    public function list(
        \App\Module\Foundation\Domain\WorkspaceScope $workspace,
        ?string $accountId,
        bool $includeVoided,
        int $limit,
        ?\App\Module\Transactions\Domain\TransactionPosition $after,
        bool $uncategorized = false,
    ): array {
        return $this->inner->list($workspace, $accountId, $includeVoided, $limit, $after, $uncategorized);
    }

    public function add(Transaction $transaction): void
    {
        ++$this->addCount;
        if (2 === $this->addCount) {
            throw new \RuntimeException('Simulated persistence failure.');
        }
        $this->inner->add($transaction);
    }

    public function update(Transaction $transaction, int $expectedVersion): bool
    {
        return $this->inner->update($transaction, $expectedVersion);
    }
}
