<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Infrastructure\Persistence\DbalAccountRepository;
use App\Module\Catalog\Domain\AccountKind;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PrimaryAccountPersistenceTest extends KernelTestCase
{
    private const string FIRST = '00000000-0000-7000-8000-0000000000d1';
    private const string SECOND = '00000000-0000-7000-8000-0000000000d2';
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private DbalAccountRepository $accounts;

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
        $this->accounts = new DbalAccountRepository($connection);
        foreach ([self::FIRST, self::SECOND] as $id) {
            $this->accounts->add(AccountFixture::account(productCode: null, kind: AccountKind::CURRENT, id: $id, workspace: WorkspaceFixture::OWN_WORKSPACE, label: $id));
        }
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testDatabaseRejectsTwoPrimariesInOneWorkspace(): void
    {
        $this->connection->executeStatement('UPDATE account_financial_accounts SET is_primary = true WHERE workspace_id = :workspace_id AND id = :id', ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => self::FIRST]);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->executeStatement('UPDATE account_financial_accounts SET is_primary = true WHERE workspace_id = :workspace_id AND id = :id', ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => self::SECOND]);
    }

    public function testSwitchIsScopedAndAStaleSwitchCannotClearTheDefault(): void
    {
        $first = $this->accounts->find(WorkspaceFixture::own(), self::FIRST);
        $second = $this->accounts->find(WorkspaceFixture::own(), self::SECOND);
        self::assertNotNull($first);
        self::assertNotNull($second);
        $now = new \DateTimeImmutable('2026-09-05T10:00:00Z');
        self::assertTrue($this->accounts->update($first->withPrimary(true, $now), 1));
        self::assertTrue($this->accounts->update($second->withPrimary(true, $now), 1));
        self::assertFalse($this->accounts->update($first->withPrimary(true, $now), 1));
        self::assertTrue($this->accounts->find(WorkspaceFixture::own(), self::SECOND)?->isPrimary);
        self::assertNull($this->accounts->findForUpdate(WorkspaceFixture::other(), self::SECOND));
        $foreign = AccountFixture::account(productCode: null, kind: AccountKind::CURRENT, id: '00000000-0000-7000-8000-0000000000d3', workspace: WorkspaceFixture::OTHER_WORKSPACE);
        $this->accounts->add($foreign);
        self::assertTrue($this->accounts->update($foreign->withPrimary(true, $now), 1));
        self::assertTrue($this->accounts->find(WorkspaceFixture::own(), self::SECOND)?->isPrimary);
        self::assertTrue($this->accounts->find(WorkspaceFixture::other(), $foreign->id)?->isPrimary);
        self::assertNull($this->accounts->findForUpdate(WorkspaceFixture::own(), $foreign->id));
    }

    public function testParallelSwitchesLeaveOnePrimaryAndInvalidatePreviousVersion(): void
    {
        $barrier = sys_get_temp_dir().'/cadran-primary-'.bin2hex(random_bytes(8));
        $handles = [];
        $pipesByWorker = [];
        try {
            foreach ([self::FIRST, self::SECOND] as $worker => $id) {
                $process = proc_open(
                    [PHP_BINARY, 'tests/Support/ConcurrentPrimaryAccountWorker.php', $id, $barrier, (string) $worker],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 5), null,
                );
                self::assertIsResource($process);
                $handles[$worker] = $process;
                $pipesByWorker[$worker] = $pipes;
            }
            foreach ($handles as $worker => $process) {
                $stdout = stream_get_contents($pipesByWorker[$worker][1]);
                $stderr = stream_get_contents($pipesByWorker[$worker][2]);
                self::assertSame(0, proc_close($process), (string) $stderr);
                self::assertSame('ok', $stdout);
            }
        } finally {
            foreach (glob($barrier.'.*') ?: [] as $file) {
                unlink($file);
            }
        }
        $rows = $this->connection->fetchAllAssociative('SELECT is_primary, version FROM account_financial_accounts WHERE workspace_id = :workspace_id ORDER BY version', ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE]);
        self::assertEquals([2, 3], array_column($rows, 'version'));
        self::assertEquals(1, $this->connection->fetchOne('SELECT count(*) FROM account_financial_accounts WHERE workspace_id = :workspace_id AND is_primary = true', ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE]));
    }
}
