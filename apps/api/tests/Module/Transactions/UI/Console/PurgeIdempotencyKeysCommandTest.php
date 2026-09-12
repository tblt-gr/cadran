<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Console;

use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeIdempotencyKeysCommandTest extends KernelTestCase
{
    private const string PAST = '2000-01-01 00:00:00+00';
    private const string FUTURE = '2100-01-01 00:00:00+00';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->databaseReady = true;
        $this->fixture->reset();
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testItPurgesOnlyExpiredKeysAcrossEveryWorkspace(): void
    {
        $this->seedKey('expired-own-key-0001', WorkspaceFixture::OWN_WORKSPACE, self::PAST);
        $this->seedKey('expired-other-key-01', WorkspaceFixture::OTHER_WORKSPACE, self::PAST);
        $this->seedKey('live-own-key-00001', WorkspaceFixture::OWN_WORKSPACE, self::FUTURE);
        $this->seedKey('live-other-key-0001', WorkspaceFixture::OTHER_WORKSPACE, self::FUTURE);

        $tester = $this->runPurge();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Purged 2 expired transaction idempotency key(s).', $tester->getDisplay());
        self::assertSame(2, $this->keyCount());
        self::assertSame(
            ['live-other-key-0001', 'live-own-key-00001'],
            $this->remainingKeys(),
        );
    }

    public function testABatchLargerThanTheCommandsPageSizeIsFullyPurgedAndCountedOnce(): void
    {
        $total = 1_000 + 7;
        for ($i = 0; $i < $total; ++$i) {
            $this->seedKey(sprintf('expired-batch-key-%04d', $i), WorkspaceFixture::OWN_WORKSPACE, self::PAST, $this->uuidFor($i));
        }
        $this->seedKey('live-batch-key-00001', WorkspaceFixture::OWN_WORKSPACE, self::FUTURE);

        $tester = $this->runPurge();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(sprintf('Purged %d expired transaction idempotency key(s).', $total), $tester->getDisplay());
        self::assertSame(1, $this->keyCount());
        self::assertSame(['live-batch-key-00001'], $this->remainingKeys());
    }

    private function runPurge(): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('cadran:transactions:purge-idempotency-keys'));
        $tester->execute([]);

        return $tester;
    }

    private function seedKey(string $key, string $workspaceId, string $expiresAt, ?string $id = null): void
    {
        $this->connection->insert('transaction_idempotency_keys', [
            'id' => $id ?? $this->uuidFor(random_int(0, PHP_INT_MAX)),
            'workspace_id' => $workspaceId,
            'use_case' => 'transaction.create',
            'idempotency_key' => $key,
            'request_fingerprint' => hash('sha256', $key),
            'status' => 'COMPLETED',
            'response_status' => 201,
            'response_body' => '{}',
            'created_at' => '2026-03-14 09:12:04+00',
            'completed_at' => '2026-03-14 09:12:04+00',
            'expires_at' => $expiresAt,
        ]);
    }

    private function keyCount(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM transaction_idempotency_keys');
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    /** @return list<string> */
    private function remainingKeys(): array
    {
        $keys = $this->connection->fetchFirstColumn('SELECT idempotency_key FROM transaction_idempotency_keys ORDER BY idempotency_key');

        return array_map(static function (mixed $value): string {
            self::assertIsString($value);

            return $value;
        }, $keys);
    }

    private function uuidFor(int $n): string
    {
        return sprintf('00000000-0000-7000-8000-%012d', $n % 1_000_000_000_000);
    }
}
