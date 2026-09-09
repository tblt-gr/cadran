<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TransactionPersistenceTest extends KernelTestCase
{
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string OWN_CATEGORY = '00000000-0000-7000-8000-0000000000c1';
    private const string OWN_TRANSACTION = '00000000-0000-7000-8000-0000000000f1';
    private const string OTHER_TRANSACTION = '00000000-0000-7000-8000-0000000000f2';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private TransactionRepository $repository;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->repository = new DbalTransactionRepository($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $this->seedAccount(self::OWN_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant');
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Compte voisin');
        $this->seedCategory();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testExactScaleSplitAndWorkspaceIsolationRoundTrip(): void
    {
        $own = $this->transaction(self::OWN_TRANSACTION, WorkspaceFixture::own(), self::OWN_ACCOUNT, '-42.90', true);
        $other = $this->transaction(self::OTHER_TRANSACTION, WorkspaceFixture::other(), self::OTHER_ACCOUNT, '1.50');
        $this->repository->add($own);
        $this->repository->add($other);

        $stored = $this->repository->find(WorkspaceFixture::own(), self::OWN_TRANSACTION);
        self::assertNotNull($stored);
        self::assertSame('-42.90', $stored->amount->value->toString());
        self::assertSame(2, $stored->amount->value->scale());
        self::assertCount(1, $stored->splits);
        self::assertNull($this->repository->find(WorkspaceFixture::own(), self::OTHER_TRANSACTION));
        self::assertSame([self::OWN_TRANSACTION], array_column(
            $this->repository->list(WorkspaceFixture::own(), null, false, 100, null),
            'id',
        ));
    }

    public function testVoidedRowsStayStoredButLeaveTheDefaultListing(): void
    {
        $transaction = $this->transaction(self::OWN_TRANSACTION, WorkspaceFixture::own(), self::OWN_ACCOUNT, '-1.00');
        $this->repository->add($transaction);
        $voided = $transaction->void(new \DateTimeImmutable('2026-03-15T10:00:00+00:00'), WorkspaceFixture::OWNER_ID);

        self::assertTrue($this->repository->update($voided, 1));
        self::assertSame([], $this->repository->list(WorkspaceFixture::own(), null, false, 100, null));
        self::assertSame([self::OWN_TRANSACTION], array_column(
            $this->repository->list(WorkspaceFixture::own(), null, true, 100, null),
            'id',
        ));
        self::assertFalse($this->repository->update($voided, 1));
    }

    public function testTheCompositeAccountForeignKeyRejectsAnotherWorkspaceAccount(): void
    {
        $this->expectException(DbalException::class);
        $this->repository->add($this->transaction(
            self::OWN_TRANSACTION,
            WorkspaceFixture::own(),
            self::OTHER_ACCOUNT,
            '-1.00',
        ));
    }

    public function testTheDeferredTriggerRejectsANonEmptyZeroSumOrPartialAllocationAtCommit(): void
    {
        $this->expectException(DbalException::class);
        $this->connection->transactional(function (): void {
            $this->repository->add($this->transaction(self::OWN_TRANSACTION, WorkspaceFixture::own(), self::OWN_ACCOUNT, '-42.90'));
            foreach ([['00000000-0000-7000-8000-0000000000e1', '-20.00'], ['00000000-0000-7000-8000-0000000000e2', '20.00']] as [$id, $amount]) {
                $this->connection->insert('transaction_splits', [
                    'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
                    'transaction_id' => self::OWN_TRANSACTION, 'category_id' => self::OWN_CATEGORY,
                    'amount_value' => $amount, 'amount_scale' => 2, 'asset_code' => 'EUR',
                    'note' => null, 'created_at' => '2026-03-14 09:12:04+00',
                ]);
            }
        });
    }

    public function testTheDeferredTriggerAllowsAMultiStatementExactRewrite(): void
    {
        $this->connection->transactional(function (): void {
            $this->repository->add($this->transaction(self::OWN_TRANSACTION, WorkspaceFixture::own(), self::OWN_ACCOUNT, '-42.90'));
            $this->connection->insert('transaction_splits', [
                'id' => '00000000-0000-7000-8000-0000000000e1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
                'transaction_id' => self::OWN_TRANSACTION, 'category_id' => self::OWN_CATEGORY,
                'amount_value' => '-20.00', 'amount_scale' => 2, 'asset_code' => 'EUR',
                'note' => null, 'created_at' => '2026-03-14 09:12:04+00',
            ]);
            $this->connection->update('transaction_splits', ['amount_value' => '-42.90'], [
                'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
                'id' => '00000000-0000-7000-8000-0000000000e1',
            ]);
        });

        self::assertSame('-42.900000000000000000000000', $this->connection->fetchOne(
            'SELECT amount_value FROM transaction_splits WHERE workspace_id = :workspace_id AND transaction_id = :transaction_id',
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'transaction_id' => self::OWN_TRANSACTION],
        ));
    }

    public function testTheDeferredTriggerRejectsAnAmountUpdateThatLeavesItsSplitBehind(): void
    {
        $this->repository->add($this->transaction(
            self::OWN_TRANSACTION,
            WorkspaceFixture::own(),
            self::OWN_ACCOUNT,
            '-42.90',
            true,
        ));

        $this->expectException(DbalException::class);
        $this->connection->transactional(function (): void {
            $this->connection->update('transaction_transactions', ['amount_value' => '-43.00'], [
                'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
                'id' => self::OWN_TRANSACTION,
            ]);
        });
    }

    private function seedAccount(string $id, string $workspace, string $label): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => $label, 'asset_code' => 'EUR',
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

    private function seedCategory(): void
    {
        $this->connection->insert('category_categories', [
            'id' => self::OWN_CATEGORY, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'type' => 'EXPENSE', 'label' => 'Courses', 'parent_id' => null, 'icon' => null,
            'color' => null, 'default_analytic_axes' => '[]', 'budget_included' => true,
            'sort_order' => 0, 'depth' => 1, 'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ], [
            'budget_included' => ParameterType::BOOLEAN,
        ]);
    }

    private function transaction(
        string $id,
        \App\Module\Foundation\Domain\WorkspaceScope $workspace,
        string $accountId,
        string $amount,
        bool $categorised = false,
    ): Transaction {
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');
        $value = new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR'));
        $splits = $categorised ? [new TransactionSplit(
            '00000000-0000-7000-8000-0000000000e1', $workspace, $id, self::OWN_CATEGORY, $value, null, $now,
        )] : [];

        return new Transaction(
            id: $id, workspace: $workspace, accountId: $accountId, amount: $value,
            originalAmount: null, exchangeRate: null, state: TransactionState::BOOKED,
            nature: $value->value->isNegative() ? TransactionNature::EXPENSE : TransactionNature::INCOME,
            source: TransactionSource::MANUAL, sourceRef: null, bookedOn: new \DateTimeImmutable('2026-03-14'),
            valueOn: null, authorizedOn: null, rawLabel: 'CB TEST', counterparty: null, note: null,
            paymentMethod: null, mcc: null, maskedCard: null, bankReference: null, splits: $splits,
            version: 1, createdAt: $now, updatedAt: $now, voidedAt: null,
            lastEditorId: WorkspaceFixture::OWNER_ID,
        );
    }
}
