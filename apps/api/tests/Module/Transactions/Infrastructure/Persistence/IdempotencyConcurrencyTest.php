<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IdempotencyConcurrencyTest extends KernelTestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string TARGET_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string DUPLICATE_ORIGINAL = '00000000-0000-7000-8000-0000000000f1';
    private const string REFUND_ORIGINAL = '00000000-0000-7000-8000-0000000000f2';

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
        $this->seedAccount(self::ACCOUNT, 'Source');
        $this->seedAccount(self::TARGET_ACCOUNT, 'Target');
        $this->seedOriginal(self::DUPLICATE_ORIGINAL, 'Concurrent duplicate seed', '-4.20');
        $this->seedOriginal(self::REFUND_ORIGINAL, 'Concurrent refund seed', '-10.00');
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testParallelMoneyMovementRequestsExecuteTheirBusinessOperationOnlyOnce(): void
    {
        foreach ([
            'create' => '', 'duplicate' => self::DUPLICATE_ORIGINAL,
            'refund' => self::REFUND_ORIGINAL, 'transfer' => '',
        ] as $case => $originalId) {
            $responses = $this->runInParallel($case, $originalId);
            self::assertSame([201, 201], array_column($responses, 'status'));
            $replays = array_column($responses, 'replayed');
            sort($replays);
            self::assertSame([false, true], $replays);
        }

        self::assertSame(1, $this->transactionCount("raw_label = 'Concurrent idempotent create'"));
        self::assertSame(1, $this->transactionCount('id <> ?', [self::DUPLICATE_ORIGINAL], "raw_label = 'Concurrent duplicate seed'"));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM transaction_refunds WHERE original_transaction_id = ?', [self::REFUND_ORIGINAL]));
        self::assertSame(2, $this->transactionCount("raw_label = 'Concurrent idempotent transfer'"));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM transaction_transfers'));
    }

    /** @return list<array{status: int, replayed: bool}> */
    private function runInParallel(string $case, string $originalId): array
    {
        $barrier = sys_get_temp_dir().'/cadran-idempotency-'.bin2hex(random_bytes(8));
        $key = 'parallel-'.$case.'-idempotency-key';
        $processes = [];
        try {
            foreach ([0, 1] as $worker) {
                $processes[] = proc_open(
                    [PHP_BINARY, 'tests/Support/ConcurrentIdempotencyWorker.php', $case, $key, $originalId, $barrier, (string) $worker],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 5), null,
                );
                self::assertIsResource($processes[$worker]);
                $processes[$worker] = [$processes[$worker], $pipes];
            }
            $deadline = microtime(true) + 10;
            while (!file_exists($barrier.'.owner') && microtime(true) < $deadline) {
                usleep(1_000);
            }
            self::assertFileExists($barrier.'.owner');
            usleep(100_000);
            file_put_contents($barrier.'.release', '');
            $responses = [];
            foreach ($processes as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                self::assertSame(0, proc_close($process), $stderr);
                /** @var array{status: int, replayed: bool} $response */
                $response = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
                $responses[] = $response;
            }

            return $responses;
        } finally {
            foreach (glob($barrier.'.*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    /** @param list<string> $parameters */
    private function transactionCount(string $condition, array $parameters = [], string $prefix = ''): int
    {
        $where = '' === $prefix ? $condition : $prefix.' AND '.$condition;
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM transaction_transactions WHERE '.$where, $parameters);
        self::assertTrue(is_int($value) || is_string($value));

        return (int) $value;
    }

    private function seedAccount(string $id, string $label): void
    {
        $this->connection->insert('account_financial_accounts', ['id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => $label, 'asset_code' => 'EUR', 'kind' => 'CURRENT', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => false, 'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01', 'closed_on' => null, 'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00'], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function seedOriginal(string $id, string $label, string $amount): void
    {
        $this->connection->insert('transaction_transactions', ['id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => self::ACCOUNT, 'asset_code' => 'EUR', 'amount_value' => $amount, 'amount_scale' => 2, 'state' => 'BOOKED', 'nature' => 'EXPENSE', 'source' => 'MANUAL', 'booked_on' => '2026-03-14', 'raw_label' => $label, 'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00']);
    }
}
