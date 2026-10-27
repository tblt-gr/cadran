<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs the real upgrade from account tags to sub-group membership on seeded
 * rows: the promotion rule is a SQL tie-break the regular migration gate never
 * exercises with data.
 */
final class AccountTagMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\\Version20261026120000';

    // Own workspace tree: R > (Bourse, Immobilier > Locatif, Alpha), Bourse > Actions.
    private const string ROOT = '00000000-0000-7000-8000-00000000f001';
    private const string BOURSE = '00000000-0000-7000-8000-00000000f0ff';
    private const string IMMOBILIER = '00000000-0000-7000-8000-00000000f003';
    private const string ALPHA = '00000000-0000-7000-8000-00000000f004';
    private const string LOCATIF = '00000000-0000-7000-8000-00000000f005';
    private const string ACTIONS = '00000000-0000-7000-8000-00000000f006';
    private const string ARCHIVED_CHILD = '00000000-0000-7000-8000-00000000f007';
    private const string UNRELATED = '00000000-0000-7000-8000-00000000f008';
    private const string OTHER_ROOT = '00000000-0000-7000-8000-00000000f101';
    private const string OTHER_CHILD = '00000000-0000-7000-8000-00000000f102';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $migrationAtHead = true;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->runMigration('down');
        $this->migrationAtHead = false;
        $this->fixture->seed();
        $this->seedTree();
    }

    protected function tearDown(): void
    {
        if (!$this->migrationAtHead) {
            $this->runMigration('up');
            $this->migrationAtHead = true;
        }
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testATagOnAChildPromotesItToThePrimaryGroup(): void
    {
        $account = $this->account('a1', self::ROOT, [self::IMMOBILIER]);

        $this->migrate();

        self::assertSame(self::IMMOBILIER, $this->primaryOf($account));
        self::assertSame('2', $this->versionOf($account));
    }

    public function testTheDeepestDescendantTagBeatsAShallowerOne(): void
    {
        $account = $this->account('a2', self::ROOT, [self::BOURSE, self::LOCATIF]);

        $this->migrate();

        self::assertSame(self::LOCATIF, $this->primaryOf($account));
    }

    public function testATieAtEqualDepthFollowsTheSavedOrderNotTheIdentifier(): void
    {
        $bySortOrder = $this->account('a3', self::ROOT, [self::IMMOBILIER, self::BOURSE]);
        $byLabel = $this->account('a4', self::ROOT, [self::IMMOBILIER, self::ALPHA]);
        $byAncestor = $this->account('a5', self::ROOT, [self::LOCATIF, self::ACTIONS]);

        $this->migrate();

        // Bourse has the highest identifier but the lowest sort order.
        self::assertSame(self::BOURSE, $this->primaryOf($bySortOrder));
        // Same sort order: the label decides, Alpha before Immobilier.
        self::assertSame(self::ALPHA, $this->primaryOf($byLabel));
        // Both at depth 3: the order of their ancestors decides, Bourse before Immobilier.
        self::assertSame(self::ACTIONS, $this->primaryOf($byAncestor));
    }

    public function testAnArchivedDescendantAndAnUnrelatedTagLeaveThePrimaryGroupUntouched(): void
    {
        $archived = $this->account('a6', self::ROOT, [self::ARCHIVED_CHILD]);
        $unrelated = $this->account('a7', self::ROOT, [self::UNRELATED]);

        $this->migrate();

        self::assertSame(self::ROOT, $this->primaryOf($archived));
        self::assertSame(self::ROOT, $this->primaryOf($unrelated));
        self::assertSame('1', $this->versionOf($archived));
    }

    public function testAnAccountWithoutAPrimaryGroupKeepsNone(): void
    {
        $account = $this->account('a8', null, [self::IMMOBILIER]);

        $this->migrate();

        self::assertNull($this->primaryOf($account));
        self::assertSame('1', $this->versionOf($account));
    }

    public function testAnAccountWithoutTagsIsNeitherChangedNorAudited(): void
    {
        $account = $this->account('a9', self::ROOT, []);

        $this->migrate();

        self::assertSame(self::ROOT, $this->primaryOf($account));
        self::assertSame('1', $this->versionOf($account));
        self::assertSame('0', $this->scalar('SELECT count(*) FROM audit_events WHERE entity_id = ?', [$account]));
    }

    public function testAuditEventsRecordBeforeAndAfterWithoutAnyLabel(): void
    {
        $account = $this->account('aa', self::ROOT, [self::IMMOBILIER, self::BOURSE]);
        $unchanged = $this->account('ab', self::ROOT, [self::UNRELATED]);

        $this->migrate();

        $row = $this->connection->fetchAssociative(
            'SELECT workspace_id, actor_id, event_type, entity_type, before_json::text AS before_json, after_json::text AS after_json'
            .' FROM audit_events WHERE entity_id = ?',
            [$account],
        );
        self::assertIsArray($row);
        self::assertSame(WorkspaceFixture::OWN_WORKSPACE, $row['workspace_id']);
        self::assertNull($row['actor_id']);
        self::assertSame('account.grouping_migrated', $row['event_type']);
        self::assertSame('account', $row['entity_type']);
        $beforeJson = $row['before_json'];
        $afterJson = $row['after_json'];
        self::assertIsString($beforeJson);
        self::assertIsString($afterJson);
        $before = self::decode($beforeJson);
        $keys = array_keys($before);
        sort($keys);
        self::assertSame(['primaryGroupId', 'tagGroupIds'], $keys);
        self::assertSame(self::ROOT, $before['primaryGroupId']);
        $tags = $before['tagGroupIds'];
        self::assertIsArray($tags);
        sort($tags);
        self::assertSame([self::IMMOBILIER, self::BOURSE], $tags);
        self::assertSame(['primaryGroupId' => self::BOURSE], self::decode($afterJson));
        self::assertStringNotContainsString('Bourse', $beforeJson.$afterJson);

        // An account whose tags were all dropped is still audited, with its primary group kept.
        self::assertSame(
            ['primaryGroupId' => self::ROOT],
            self::decode($this->scalar('SELECT after_json::text FROM audit_events WHERE entity_id = ?', [$unchanged])),
        );
    }

    public function testEachWorkspaceIsMigratedFromItsOwnTree(): void
    {
        $own = $this->account('ac', self::ROOT, [self::IMMOBILIER]);
        $other = $this->account('ad', self::OTHER_ROOT, [self::OTHER_CHILD], WorkspaceFixture::OTHER_WORKSPACE);

        $this->migrate();

        self::assertSame(self::IMMOBILIER, $this->primaryOf($own));
        self::assertSame(self::OTHER_CHILD, $this->primaryOf($other));
        self::assertSame(
            WorkspaceFixture::OTHER_WORKSPACE,
            $this->connection->fetchOne('SELECT workspace_id FROM audit_events WHERE entity_id = ?', [$other]),
        );
    }

    public function testTheTagTableIsDroppedAndDownRecreatesItEmpty(): void
    {
        $this->account('ae', self::ROOT, [self::IMMOBILIER]);

        $this->migrate();
        self::assertFalse($this->tagTableExists());

        $this->runMigration('down');
        $this->migrationAtHead = false;
        self::assertTrue($this->tagTableExists());
        self::assertSame('0', $this->scalar('SELECT count(*) FROM account_group_tags', []));
    }

    /** @return array<mixed> */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function migrate(): void
    {
        $this->runMigration('up');
        $this->migrationAtHead = true;
    }

    private function tagTableExists(): bool
    {
        return null !== $this->connection->fetchOne("SELECT to_regclass('public.account_group_tags')");
    }

    private function seedTree(): void
    {
        $own = WorkspaceFixture::OWN_WORKSPACE;
        $this->group(self::ROOT, $own, 'Investissement', null, 1, 0);
        $this->group(self::BOURSE, $own, 'Bourse', self::ROOT, 2, 0);
        $this->group(self::IMMOBILIER, $own, 'Immobilier', self::ROOT, 2, 1);
        $this->group(self::ALPHA, $own, 'Alpha', self::ROOT, 2, 1);
        $this->group(self::LOCATIF, $own, 'Locatif', self::IMMOBILIER, 3, 0);
        $this->group(self::ACTIONS, $own, 'Actions', self::BOURSE, 3, 0);
        $this->group(self::ARCHIVED_CHILD, $own, 'Ancien', self::ROOT, 2, 2, archived: true);
        $this->group(self::UNRELATED, $own, 'Autre', null, 1, 1);
        $other = WorkspaceFixture::OTHER_WORKSPACE;
        $this->group(self::OTHER_ROOT, $other, 'Autre racine', null, 1, 0);
        $this->group(self::OTHER_CHILD, $other, 'Autre enfant', self::OTHER_ROOT, 2, 0);
    }

    private function group(string $id, string $workspace, string $label, ?string $parent, int $depth, int $sortOrder, bool $archived = false): void
    {
        $this->connection->insert('account_groups', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => $label, 'parent_id' => $parent,
            'sort_order' => $sortOrder, 'depth' => $depth, 'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00', 'updated_at' => '2026-09-01 12:00:00+00',
            'archived_at' => $archived ? '2026-09-02 12:00:00+00' : null,
        ]);
    }

    /** @param list<string> $tags */
    private function account(string $suffix, ?string $primary, array $tags, string $workspace = WorkspaceFixture::OWN_WORKSPACE): string
    {
        $id = '00000000-0000-7000-8000-0000000a'.str_pad($suffix, 4, '0', STR_PAD_LEFT);
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => 'Account '.$suffix, 'asset_code' => 'EUR',
            'kind' => 'SAVINGS', 'product_code' => null, 'product_model_id' => null, 'institution' => null,
            'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => null !== $primary, 'include_in_emergency_fund' => false,
            'opened_on' => '2026-01-10', 'closed_on' => null, 'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00', 'updated_at' => '2026-09-01 12:00:00+00',
            'primary_group_id' => $primary,
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
        foreach ($tags as $tag) {
            $this->connection->insert('account_group_tags', ['workspace_id' => $workspace, 'account_id' => $id, 'group_id' => $tag]);
        }

        return $id;
    }

    private function primaryOf(string $account): ?string
    {
        $value = $this->connection->fetchOne('SELECT primary_group_id FROM account_financial_accounts WHERE id = ?', [$account]);

        return is_string($value) ? $value : null;
    }

    private function versionOf(string $account): string
    {
        return $this->scalar('SELECT version FROM account_financial_accounts WHERE id = ?', [$account]);
    }

    /** @param list<string> $parameters */
    private function scalar(string $sql, array $parameters): string
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertIsScalar($value);

        return (string) $value;
    }

    private function runMigration(string $direction): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('doctrine:migrations:execute'));
        $tester->execute([
            'versions' => [self::MIGRATION],
            '--'.$direction => true,
            '--no-interaction' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        // Doctrine freezes an executed migration instance: reboot for a fresh one.
        self::ensureKernelShutdown();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
    }
}
