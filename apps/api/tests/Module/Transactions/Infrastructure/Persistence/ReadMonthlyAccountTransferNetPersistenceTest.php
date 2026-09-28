<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Transactions\Application\ReadMonthlyAccountTransferNet;
use App\Module\Transactions\Application\ReadMonthlyTransferPairs;
use App\Module\Transactions\Infrastructure\Persistence\DbalMonthlyTransferPairReader;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReadMonthlyAccountTransferNetPersistenceTest extends KernelTestCase
{
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OWN_COUNTERPART = '00000000-0000-7000-8000-0000000000d2';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000e1';
    private const string OTHER_COUNTERPART = '00000000-0000-7000-8000-0000000000e2';

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

        $this->seedAccount(WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, 'Own target');
        $this->seedAccount(WorkspaceFixture::OWN_WORKSPACE, self::OWN_COUNTERPART, 'Own counterpart');
        $this->seedAccount(WorkspaceFixture::OTHER_WORKSPACE, self::OTHER_ACCOUNT, 'Other target');
        $this->seedAccount(WorkspaceFixture::OTHER_WORKSPACE, self::OTHER_COUNTERPART, 'Other counterpart');
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testItReadsOnlyTheRequestedWorkspaceAndMonthFromPostgresql(): void
    {
        $this->seedTransfer('d1', WorkspaceFixture::OWN_WORKSPACE, self::OWN_COUNTERPART, self::OWN_ACCOUNT, '-300.10', '300.10', '2026-09-15');
        $this->seedTransfer('d2', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, self::OWN_COUNTERPART, '-50.03', '50.03', '2026-09-20');
        $this->seedTransfer('d3', WorkspaceFixture::OWN_WORKSPACE, self::OWN_COUNTERPART, self::OWN_ACCOUNT, '-99.00', '99.00', '2026-08-31');
        $this->seedTransfer('e1', WorkspaceFixture::OTHER_WORKSPACE, self::OTHER_COUNTERPART, self::OTHER_ACCOUNT, '-700.00', '700.00', '2026-09-15');

        $read = new ReadMonthlyAccountTransferNet(
            new ReadMonthlyTransferPairs(new DbalMonthlyTransferPairReader($this->connection)),
        );

        self::assertSame('250.07', $read(WorkspaceFixture::own(), self::OWN_ACCOUNT, CalendarMonth::fromString('2026-09'))->value?->toString());
        self::assertSame('0', $read(WorkspaceFixture::own(), self::OTHER_ACCOUNT, CalendarMonth::fromString('2026-09'))->value?->toString());
        self::assertSame('700.00', $read(WorkspaceFixture::other(), self::OTHER_ACCOUNT, CalendarMonth::fromString('2026-09'))->value?->toString());
    }

    private function seedAccount(string $workspaceId, string $id, string $label): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => $workspaceId,
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
            'created_at' => '2026-09-01 00:00:00+00',
            'updated_at' => '2026-09-01 00:00:00+00',
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    private function seedTransfer(
        string $suffix,
        string $workspaceId,
        string $sourceAccountId,
        string $targetAccountId,
        string $sourceAmount,
        string $targetAmount,
        string $bookedOn,
    ): void {
        $sourceId = '00000000-0000-7000-8000-000000000'.$suffix.'1';
        $targetId = '00000000-0000-7000-8000-000000000'.$suffix.'2';
        $transferId = '00000000-0000-7000-8000-000000000'.$suffix.'3';
        $this->seedLeg($sourceId, $workspaceId, $sourceAccountId, $sourceAmount, $bookedOn);
        $this->seedLeg($targetId, $workspaceId, $targetAccountId, $targetAmount, $bookedOn);
        $this->connection->insert('transaction_transfers', [
            'id' => $transferId,
            'workspace_id' => $workspaceId,
            'source_transaction_id' => $sourceId,
            'target_transaction_id' => $targetId,
            'version' => 1,
            'created_at' => '2026-09-01 00:00:00+00',
            'updated_at' => '2026-09-01 00:00:00+00',
        ]);
    }

    private function seedLeg(string $id, string $workspaceId, string $accountId, string $amount, string $bookedOn): void
    {
        $this->connection->insert('transaction_transactions', [
            'id' => $id,
            'workspace_id' => $workspaceId,
            'account_id' => $accountId,
            'asset_code' => 'EUR',
            'amount_value' => $amount,
            'amount_scale' => 2,
            'state' => 'BOOKED',
            'nature' => 'TRANSFER',
            'source' => 'MANUAL',
            'booked_on' => $bookedOn,
            'raw_label' => 'Transfer',
            'version' => 1,
            'created_at' => '2026-09-01 00:00:00+00',
            'updated_at' => '2026-09-01 00:00:00+00',
        ]);
    }
}
