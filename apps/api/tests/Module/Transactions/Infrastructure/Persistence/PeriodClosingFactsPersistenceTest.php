<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Application\Reconciliation\TransactionPeriodClosingFacts;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Worked example, April 2026, every account opened on 2026-01-01 with a
 * reconciled opening of 100.00 on 2026-03-31 and no movement: an observed
 * closing of 112.40 gives a gap of 12.40, 90.00 gives -10, 100.00 gives none.
 */
final class PeriodClosingFactsPersistenceTest extends KernelTestCase
{
    private const string UP = '00000000-0000-7000-8000-0000000000e1';
    private const string DOWN = '00000000-0000-7000-8000-0000000000e2';
    private const string BALANCED = '00000000-0000-7000-8000-0000000000e3';
    private const string NO_OPENING = '00000000-0000-7000-8000-0000000000e4';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private TransactionPeriodClosingFacts $facts;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $facts = self::getContainer()->get(TransactionPeriodClosingFacts::class);
        self::assertInstanceOf(TransactionPeriodClosingFacts::class, $facts);
        $this->facts = $facts;
        $this->fixture = new WorkspaceFixture($connection);
        $this->databaseReady = true;
        $this->fixture->reset();
        $this->fixture->seed();
        foreach ([self::UP, self::DOWN, self::BALANCED, self::NO_OPENING] as $account) {
            $this->seedAccount($account);
        }
        foreach ([self::UP, self::DOWN, self::BALANCED] as $index => $account) {
            $this->seedSnapshot('00000000-0000-7000-8000-0000000001a'.$index, $account, '2026-03-31', '100.00', 'RECONCILED');
        }
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testOnlyNonZeroCalculableGapsAreReturnedKeyedByAccountAndSigned(): void
    {
        $closings = [
            $this->closing('00000000-0000-7000-8000-0000000001b1', self::UP, '112.40'),
            $this->closing('00000000-0000-7000-8000-0000000001b2', self::DOWN, '90.00'),
            $this->closing('00000000-0000-7000-8000-0000000001b3', self::BALANCED, '100.00'),
            $this->closing('00000000-0000-7000-8000-0000000001b4', self::NO_OPENING, '55.00'),
        ];

        $gaps = $this->facts->unexplainedDiscrepancies(WorkspaceFixture::own(), $closings, new \DateTimeImmutable('2026-04-01'));

        self::assertSame([self::UP, self::DOWN], array_keys($gaps), 'a balanced account and an uncalculable one are absent');
        self::assertSame('12.40', $gaps[self::UP]->toString());
        self::assertSame('-10.00', $gaps[self::DOWN]->toString());
    }

    public function testAnEmptyListOfClosingsReturnsNothing(): void
    {
        self::assertSame([], $this->facts->unexplainedDiscrepancies(WorkspaceFixture::own(), [], new \DateTimeImmutable('2026-04-01')));
    }

    private function closing(string $id, string $accountId, string $amount): AccountBalanceSnapshot
    {
        $this->seedSnapshot($id, $accountId, '2026-04-30', $amount, 'UNRECONCILED');

        return new AccountBalanceSnapshot(
            id: $id,
            workspace: WorkspaceFixture::own(),
            accountId: $accountId,
            asOf: new \DateTimeImmutable('2026-04-30', new \DateTimeZone('UTC')),
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            source: BalanceSnapshotSource::MANUAL,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
            recordedBy: WorkspaceFixture::OWNER_ID,
        );
    }

    private function seedAccount(string $id): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => 'Compte '.$id, 'asset_code' => 'EUR',
            'kind' => 'CURRENT', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => false,
            'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01', 'closed_on' => null,
            'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function seedSnapshot(string $id, string $accountId, string $asOf, string $amount, string $status): void
    {
        $this->connection->insert('account_balance_snapshots', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => $accountId, 'as_of' => $asOf,
            'amount_value' => $amount, 'amount_literal' => $amount, 'amount_asset' => 'EUR', 'source' => 'MANUAL',
            'reconciliation_status' => $status, 'comment' => null, 'version' => 1,
            'recorded_at' => '2026-09-01 10:00:00+00', 'recorded_by' => WorkspaceFixture::OWNER_ID, 'superseded_at' => null,
        ]);
    }
}
