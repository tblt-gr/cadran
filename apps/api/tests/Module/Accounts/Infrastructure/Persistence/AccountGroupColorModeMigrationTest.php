<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Domain\AccountGroupColor;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AccountGroupColorModeMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\\Version20261027120000';
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $atHead = true;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->migrate('down');
        $this->atHead = false;
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        if (!$this->atHead) {
            $this->migrate('up');
        }
        parent::tearDown();
    }

    public function testCustomColorsSurviveUntouchedSubGroupDefaultsBecomeAutoAndSlotsFollowCreation(): void
    {
        $root = $this->insert('c1', 'Root', null, '2026-10-01 12:00:00+00', '#123456');
        $plainRoot = $this->insert('c2', 'Plain', null, '2026-10-01 12:00:00+00', null);
        $migratedDefault = $this->insert('c3', 'Migrated', $root, '2026-10-03 12:00:00+00', AccountGroupColor::defaultFor($this->id('c3')));
        $customChild = $this->insert('c4', 'Custom', $root, '2026-10-02 12:00:00+00', '#ABCDEF');
        $archivedChild = $this->insert('c5', 'Archived', $root, '2026-10-01 12:00:00+00', null, archived: true);
        $otherParentChild = $this->insert('c6', 'Elsewhere', $plainRoot, '2026-10-09 12:00:00+00', null);
        $otherWorkspace = $this->insert('c7', 'Foreign', null, '2026-10-01 12:00:00+00', null, WorkspaceFixture::OTHER_WORKSPACE);

        $this->migrate('up');
        $this->atHead = true;

        $colors = $this->connection->fetchAllKeyValue('SELECT id, color FROM account_groups');
        $modes = $this->connection->fetchAllKeyValue('SELECT id, color_mode FROM account_groups');
        $slots = $this->connection->fetchAllKeyValue('SELECT id, color_slot FROM account_groups');
        self::assertSame('#123456', $colors[$root]);
        self::assertSame('CUSTOM', $modes[$root]);
        self::assertEquals(0, $slots[$root]);
        self::assertNull($colors[$plainRoot]);
        self::assertSame('AUTO', $modes[$plainRoot]);
        self::assertNull($colors[$migratedDefault]);
        self::assertSame('AUTO', $modes[$migratedDefault]);
        self::assertSame('#ABCDEF', $colors[$customChild]);
        self::assertSame('CUSTOM', $modes[$customChild]);
        self::assertEquals(0, $slots[$archivedChild]);
        self::assertEquals(1, $slots[$customChild]);
        self::assertEquals(2, $slots[$migratedDefault]);
        self::assertEquals(0, $slots[$otherParentChild]);
        self::assertSame('AUTO', $modes[$otherWorkspace]);
    }

    public function testTheDatabaseRejectsIncoherentModes(): void
    {
        $root = $this->insert('d1', 'Root', null, '2026-10-01 12:00:00+00', null);
        $this->migrate('up');
        $this->atHead = true;

        foreach ([['color_mode' => 'PARENT'], ['color_mode' => 'CUSTOM'], ['color_mode' => 'RAINBOW'], ['color' => '#123456'], ['color_slot' => -1], ['color_slot' => 40000]] as $change) {
            try {
                $this->connection->update('account_groups', $change, ['id' => $root]);
                self::fail('The database must reject an incoherent color mode.');
            } catch (DbalException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function id(string $suffix): string
    {
        return '00000000-0000-7000-8000-0000000000'.$suffix;
    }

    private function insert(string $suffix, string $label, ?string $parentId, string $createdAt, ?string $color, string $workspace = WorkspaceFixture::OWN_WORKSPACE, bool $archived = false): string
    {
        $id = $this->id($suffix);
        $this->connection->insert('account_groups', [
            'id' => $id,
            'workspace_id' => $workspace,
            'label' => $label,
            'color' => $color,
            'sort_order' => 0,
            'parent_id' => $parentId,
            'depth' => null === $parentId ? 1 : 2,
            'version' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'archived_at' => $archived ? '2026-10-02 12:00:00+00' : null,
        ]);

        return $id;
    }

    private function migrate(string $direction): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('doctrine:migrations:execute'));
        $tester->execute(['versions' => [self::MIGRATION], '--'.$direction => true, '--no-interaction' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        self::ensureKernelShutdown();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
    }
}
