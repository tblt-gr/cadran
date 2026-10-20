<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Domain\MovementWindow;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BookedMovementTotalsTest extends KernelTestCase
{
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000e1';

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
        $this->seedAccount(WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT);
        $this->seedAccount(WorkspaceFixture::OTHER_WORKSPACE, self::OTHER_ACCOUNT);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testReferenceFiltersBoundsStatesAccountsWorkspacesAndForeignAssets(): void
    {
        $rows = [
            ['01', '-20.00', 'BOOKED', '2026-09-30'],
            ['02', '-42.10', 'BOOKED', '2026-10-02'],
            ['03', '1800.00', 'BOOKED', '2026-10-05'],
            ['04', '-300.00', 'BOOKED', '2026-10-07'],
            ['05', '-15.00', 'PENDING', '2026-10-08'],
            ['06', '-9.99', 'VOIDED', '2026-10-09'],
            ['07', '-60.00', 'BOOKED', '2026-10-15'],
            ['08', '-10.00', 'REJECTED', '2026-10-09'],
            ['09', '-100.00', 'BOOKED', '2026-09-29'],
        ];
        foreach ($rows as [$id, $amount, $state, $day]) {
            $this->seedTransaction($id, WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, $amount, 'ADJUSTMENT', $state, $day);
        }
        $this->seedTransaction('e1', WorkspaceFixture::OTHER_WORKSPACE, self::OTHER_ACCOUNT, '9000.00', 'INCOME', 'BOOKED', '2026-10-03');
        $repo = new DbalTransactionRepository($this->connection);
        $window = $this->window();
        $foreignWindow = $this->window(self::OTHER_ACCOUNT);
        $totals = $repo->forWindows(WorkspaceFixture::own(), [$window, $foreignWindow]);
        self::assertSame('1457.9', $totals[$window->key()]->total?->toString());
        self::assertSame(3, $totals[$window->key()]->bookedCount);
        self::assertSame(1, $totals[$window->key()]->pendingCount);
        self::assertFalse($totals[$window->key()]->foreignAsset);
        self::assertNull($totals[$foreignWindow->key()]->total);
        self::assertSame(0, $totals[$foreignWindow->key()]->bookedCount);
        self::assertSame(0, $repo->forWindows(WorkspaceFixture::other(), [$window])[$window->key()]->bookedCount);

        $this->seedTransaction('10', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, '-1.00', 'ADJUSTMENT', 'BOOKED', '2026-10-03');
        $this->connection->update('transaction_transactions', ['asset_code' => 'USD'], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => '00000000-0000-7000-8000-000000000a10']);
        $total = $repo->forWindows(WorkspaceFixture::own(), [$window])[$window->key()];
        self::assertTrue($total->foreignAsset);
        self::assertSame('1457.9', $total->total?->toString());
    }

    public function testNumericSumRetainsTwentyFourPlacesAndVoidedBookedRowsAreIgnored(): void
    {
        $this->seedTransaction('01', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, '0.000000000000000000000001', 'ADJUSTMENT', 'BOOKED', '2026-10-01');
        $this->seedTransaction('02', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, '0.000000000000000000000002', 'ADJUSTMENT', 'BOOKED', '2026-10-02');
        $this->seedTransaction('03', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, '999.00', 'ADJUSTMENT', 'BOOKED', '2026-10-03');
        $this->connection->update('transaction_transactions', ['voided_at' => '2026-10-04', 'state' => 'VOIDED'], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => '00000000-0000-7000-8000-000000000a03']);
        $window = $this->window();
        $total = (new DbalTransactionRepository($this->connection))->forWindows(WorkspaceFixture::own(), [$window])[$window->key()];
        self::assertSame('0.000000000000000000000003', $total->total?->toString());
        self::assertSame(2, $total->bookedCount);
    }

    public function testEmptyBatchAndWindowAreExplicitAndOversizedBatchIsRejected(): void
    {
        $repo = new DbalTransactionRepository($this->connection);
        self::assertSame([], $repo->forWindows(WorkspaceFixture::own(), []));
        $window = $this->window();
        $empty = $repo->forWindows(WorkspaceFixture::own(), [$window])[$window->key()];
        self::assertNull($empty->total);
        self::assertSame(0, $empty->bookedCount);
        self::assertSame(0, $empty->pendingCount);
        $this->expectException(NetWorthScopeTooLarge::class);
        $repo->forWindows(WorkspaceFixture::own(), array_fill(0, 30001, $window));
    }

    private function window(string $account = self::OWN_ACCOUNT): MovementWindow
    {
        return new MovementWindow($account, new \DateTimeImmutable('2026-09-30'), new \DateTimeImmutable('2026-10-10'), AssetCode::fromString('EUR'));
    }

    private function seedAccount(string $workspaceId, string $id): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspaceId, 'label' => 'Account '.substr($id, -2), 'asset_code' => 'EUR', 'kind' => 'CURRENT',
            'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => false,
            'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01', 'closed_on' => null, 'version' => 1,
            'created_at' => '2026-09-01 00:00:00+00', 'updated_at' => '2026-09-01 00:00:00+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function seedTransaction(string $suffix, string $workspaceId, string $accountId, string $amount, string $nature, string $state, string $bookedOn): void
    {
        $this->connection->insert('transaction_transactions', [
            'id' => '00000000-0000-7000-8000-000000000a'.$suffix,
            'workspace_id' => $workspaceId,
            'account_id' => $accountId,
            'asset_code' => 'EUR',
            'amount_value' => $amount,
            'amount_scale' => 2,
            'state' => $state,
            'nature' => $nature,
            'source' => 'MANUAL',
            'booked_on' => $bookedOn,
            'raw_label' => 'Movement '.$suffix,
            'version' => 1,
            'created_at' => '2026-09-01 00:00:00+00',
            'updated_at' => '2026-09-01 00:00:00+00',
            'voided_at' => 'VOIDED' === $state ? '2026-09-02 00:00:00+00' : null,
        ]);
    }
}
