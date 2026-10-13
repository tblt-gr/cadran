<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Transactions\Application\BookedMovementFact;
use App\Module\Transactions\Infrastructure\Persistence\DbalBookedMovementReader;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbalBookedMovementReaderTest extends KernelTestCase
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

    public function testItReadsOnlyBookedMovementsOfTheWorkspaceInsideTheInclusiveRangeAsExactStrings(): void
    {
        $this->seedTransaction('01', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, '2500.10', 'INCOME', 'BOOKED', '2026-03-01');
        $this->seedTransaction('02', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, '-0.05', 'EXPENSE', 'BOOKED', '2026-03-31');
        $this->seedTransaction('03', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, '99.00', 'INCOME', 'PENDING', '2026-03-15');
        $this->seedTransaction('04', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, '-5.00', 'EXPENSE', 'VOIDED', '2026-03-15');
        $this->seedTransaction('05', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, '700.00', 'INCOME', 'BOOKED', '2026-02-28');
        $this->seedTransaction('06', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, '700.00', 'INCOME', 'BOOKED', '2026-04-01');
        $this->seedTransaction('e1', WorkspaceFixture::OTHER_WORKSPACE, self::OTHER_ACCOUNT, '9000.00', 'INCOME', 'BOOKED', '2026-03-10');

        $facts = (new DbalBookedMovementReader($this->connection))->between(
            WorkspaceFixture::own(),
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-31'),
            100,
        );

        self::assertSame(
            [['00000000-0000-7000-8000-000000000a01', self::OWN_ACCOUNT, 'INCOME', '2500.10', 'EUR'], ['00000000-0000-7000-8000-000000000a02', self::OWN_ACCOUNT, 'EXPENSE', '-0.05', 'EUR']],
            array_map(static fn (BookedMovementFact $fact): array => [$fact->id, $fact->accountId, $fact->nature, $fact->amount->value->toString(), $fact->amount->asset->toString()], $facts),
        );
    }

    public function testAnotherWorkspaceNeverSeesTheOwnMovementsAndTheLimitIsHonoured(): void
    {
        $this->seedTransaction('01', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, '10.00', 'INCOME', 'BOOKED', '2026-03-01');
        $this->seedTransaction('02', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, '20.00', 'INCOME', 'BOOKED', '2026-03-02');
        $this->seedTransaction('03', WorkspaceFixture::OWN_WORKSPACE, self::OWN_ACCOUNT, '30.00', 'INCOME', 'BOOKED', '2026-03-03');
        $reader = new DbalBookedMovementReader($this->connection);
        $from = new \DateTimeImmutable('2026-03-01');
        $to = new \DateTimeImmutable('2026-03-31');

        self::assertSame([], $reader->between(WorkspaceFixture::other(), $from, $to, 100));
        $limited = $reader->between(WorkspaceFixture::own(), $from, $to, 2);
        self::assertSame(['10.00', '20.00'], array_map(static fn (BookedMovementFact $fact): string => $fact->amount->value->toString(), $limited), 'Oldest first, deterministic, at most the limit.');
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
