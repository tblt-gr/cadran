<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Infrastructure\Persistence;

use App\Module\Identity\Infrastructure\Persistence\DbalOwnerSessionRegistry;
use App\Module\Identity\Infrastructure\Persistence\SessionHandlerFactory;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Session revocation against the real session table.
 *
 * The rows are written by the shipped PdoSessionHandler rather than by hand, so
 * the column names and identifiers the DELETE is judged on are the ones the
 * handler actually produces. Which of them survive is what this asserts; that a
 * live request's session identifier reaches the same column is covered by
 * {@see \App\Tests\Module\Identity\UI\Http\SessionRevocationTest}.
 *
 * Every handler call is bracketed by open/close. The handler's default lock mode
 * keeps a transaction open from a write until close, holding a row lock on
 * everything it wrote; in production each session is written by its own request
 * on its own connection, so only the caller's row — the one revocation
 * deliberately spares — is ever locked.
 */
final class DbalOwnerSessionRegistryTest extends KernelTestCase
{
    private Connection $connection;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();

        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->databaseReady = true;
        $this->clearSessions();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->clearSessions();
        }

        parent::tearDown();
    }

    public function testItDropsEveryOtherSessionAndSparesTheOneNamed(): void
    {
        $this->writeSession('caller-session');
        $this->writeSession('another-browser');
        $this->writeSession('a-stolen-cookie');

        $this->revoke('caller-session');

        self::assertSame(['caller-session'], $this->storedSessionIds());
    }

    public function testTheSparedSessionKeepsItsData(): void
    {
        // Revocation must not corrupt the record it spares: the caller stays
        // signed in on it for the rest of the request and beyond.
        $this->writeSession('caller-session', 'the-payload');
        $this->writeSession('another-browser');

        $this->revoke('caller-session');

        self::assertSame('the-payload', $this->readSession('caller-session'));
    }

    public function testWithoutASessionToSpareEveryRecordGoes(): void
    {
        // A console run has no session of its own to keep.
        $this->writeSession('another-browser');
        $this->writeSession('a-stolen-cookie');

        $this->revoke(null);

        self::assertSame([], $this->storedSessionIds());
    }

    public function testRevokingWithNothingStoredIsANoOp(): void
    {
        $this->revoke('caller-session');

        self::assertSame([], $this->storedSessionIds());
    }

    /**
     * The production call site runs inside the transaction that replaces the
     * password hash, and `SET LOCAL lock_timeout` is only meaningful there.
     */
    private function revoke(?string $sessionIdToKeep): void
    {
        $registry = new DbalOwnerSessionRegistry($this->connection);
        $this->connection->transactional(static function () use ($registry, $sessionIdToKeep): void {
            $registry->revokeAllExcept($sessionIdToKeep);
        });
    }

    private function writeSession(string $id, string $data = 'payload'): void
    {
        $handler = SessionHandlerFactory::create($this->connection);
        $handler->open('', 'cadran_session');
        self::assertTrue($handler->write($id, $data));
        $handler->close();
    }

    private function readSession(string $id): string
    {
        $handler = SessionHandlerFactory::create($this->connection);
        $handler->open('', 'cadran_session');
        $data = $handler->read($id);
        $handler->close();

        return $data;
    }

    /** @return list<string> */
    private function storedSessionIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT sess_id FROM sessions ORDER BY sess_id');

        return $ids;
    }

    private function clearSessions(): void
    {
        $this->connection->executeStatement('DELETE FROM sessions');
    }
}
