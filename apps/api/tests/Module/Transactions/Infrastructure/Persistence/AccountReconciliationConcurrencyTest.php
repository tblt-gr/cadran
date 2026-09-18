<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Two simultaneous ADJUST requests on one snapshot must leave exactly one adjustment. */
final class AccountReconciliationConcurrencyTest extends KernelTestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OPENING = '00000000-0000-7000-8000-0000000000b1';
    private const string CLOSING = '00000000-0000-7000-8000-0000000000b2';

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
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        $this->connection->insert('account_financial_accounts', [
            'id' => self::ACCOUNT, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => 'Courant', 'asset_code' => 'EUR',
            'kind' => 'CURRENT', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => false,
            'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01', 'closed_on' => null,
            'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
        foreach ([[self::OPENING, '2026-03-31', '1000.00'], [self::CLOSING, '2026-04-30', '1165.00']] as [$id, $asOf, $amount]) {
            $this->connection->insert('account_balance_snapshots', [
                'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => self::ACCOUNT, 'as_of' => $asOf,
                'amount_value' => $amount, 'amount_literal' => $amount, 'amount_asset' => 'EUR', 'source' => 'MANUAL',
                'reconciliation_status' => 'UNRECONCILED', 'comment' => null, 'version' => 1,
                'recorded_at' => '2026-05-01 10:00:00+00', 'recorded_by' => WorkspaceFixture::OWNER_ID, 'superseded_at' => null,
            ]);
        }
        $this->connection->insert('transaction_transactions', [
            'id' => '00000000-0000-7000-8000-00000000f001', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => self::ACCOUNT,
            'asset_code' => 'EUR', 'amount_value' => '170.25', 'amount_scale' => 2, 'state' => 'BOOKED', 'nature' => 'INCOME',
            'source' => 'MANUAL', 'booked_on' => '2026-04-10', 'raw_label' => 'Seed', 'version' => 1,
            'created_at' => '2026-05-01 10:00:00+00', 'updated_at' => '2026-05-01 10:00:00+00', 'last_editor_id' => WorkspaceFixture::OWNER_ID,
        ]);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testTwoParallelAdjustmentsProduceExactlyOneAdjustmentAndOneConflict(): void
    {
        $barrier = sys_get_temp_dir().'/cadran-reconcile-'.bin2hex(random_bytes(8));
        $handles = [];
        $pipesByWorker = [];
        try {
            foreach ([0, 1] as $worker) {
                $process = proc_open(
                    [PHP_BINARY, 'tests/Support/ConcurrentAccountReconciliationWorker.php', self::ACCOUNT, self::CLOSING, '2026-04-01', 'ADJUST', $barrier, (string) $worker],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 5), null,
                );
                self::assertIsResource($process);
                $handles[$worker] = $process;
                $pipesByWorker[$worker] = $pipes;
            }
            $outcomes = [];
            foreach ($handles as $worker => $process) {
                $stdout = stream_get_contents($pipesByWorker[$worker][1]);
                $stderr = stream_get_contents($pipesByWorker[$worker][2]);
                self::assertSame(0, proc_close($process), (string) $stderr);
                $decoded = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                $outcomes[] = $decoded['outcome'];
            }
        } finally {
            foreach (glob($barrier.'.*') ?: [] as $file) {
                unlink($file);
            }
        }

        sort($outcomes);
        self::assertSame(['App\Module\Transactions\Application\Reconciliation\AccountReconciliationConflict', 'ok'], $outcomes);
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM transaction_transactions WHERE nature = 'ADJUSTMENT'"));
        self::assertSame('RECONCILED', $this->scalar('SELECT reconciliation_status FROM account_balance_snapshots WHERE id = ?', [self::CLOSING]));
    }

    /** @param list<string> $params */
    private function scalar(string $sql, array $params = []): string
    {
        $value = $this->connection->fetchOne($sql, $params);
        self::assertTrue(is_scalar($value), 'Expected a scalar database value.');

        return (string) $value;
    }
}
