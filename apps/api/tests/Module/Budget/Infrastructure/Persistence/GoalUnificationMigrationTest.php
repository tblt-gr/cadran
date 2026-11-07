<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Infrastructure\Persistence;

use App\Module\Foundation\Domain\ExactDecimal;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Exception\AbortMigration;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exercises the real upgrade boundary of the goals unification on a database that still holds budget
 * plans, targets and the two former goal kinds: the migration is the only place those rows can be read.
 */
final class GoalUnificationMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\\Version20261108120000';
    private const string AT = '2026-01-01 12:00:00+00';
    private const string ESSENTIAL_PLAN = '00000000-0000-7000-8000-0000000000a1';
    private const string DRAFT_PLAN = '00000000-0000-7000-8000-0000000000a2';
    private const string YEAR_PLAN = '00000000-0000-7000-8000-0000000000a3';
    private const string WIDE_PLAN = '00000000-0000-7000-8000-0000000000a4';
    private const string ANNUAL_GOAL = '00000000-0000-7000-8000-0000000000b1';
    private const string ROLLING_OPEN = '00000000-0000-7000-8000-0000000000b2';
    private const string ROLLING_CLOSED = '00000000-0000-7000-8000-0000000000b3';
    private const string PARENT = '00000000-0000-7000-8000-0000000000c1';
    private const string CLOSED_ANNUAL = '00000000-0000-7000-8000-0000000000b4';
    private const string CHILD = '00000000-0000-7000-8000-0000000000c2';
    private const string LONELY = '00000000-0000-7000-8000-0000000000c3';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $unified = true;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $this->adopt();
        $this->fixture->reset();
        $this->migrate('down');
        $this->unified = false;
        $this->fixture->seed();
        $this->seedLegacyData();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        if (!$this->unified) {
            $this->connection->executeStatement('DELETE FROM budget_targets');
            $this->connection->executeStatement('DELETE FROM budget_plans');
            $this->connection->executeStatement('DELETE FROM budget_goal_versions');
            $this->connection->executeStatement('DELETE FROM budget_goals');
            $this->migrate('up');
        }
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testItMigratesTheWorkedExamplesSkipsDraftsAndPrintsCountsOnly(): void
    {
        $output = $this->migrate('up');
        $this->unified = true;

        self::assertSame('0', $this->scalar("SELECT count(*) FROM pg_tables WHERE tablename IN ('budget_plans', 'budget_targets')"));
        self::assertStringContainsString('2 targets migrated, 1 draft targets skipped, 1 category targets on a category with descendants', $output);
        foreach (['Essentiel', 'Parent', 'Brouillon', '0.40', '1200.00', '6000.00', '0.123456789'] as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }

        // Worked example 1: a month plan axis ratio becomes a one-off monthly goal.
        $goal = $this->goalByLabel('Essentiel');
        self::assertSame('MONTHLY', $goal['periodicity']);
        self::assertSame('2026-10', $goal['start_period']);
        self::assertSame('2026-10', $goal['end_period']);
        self::assertSame('AXIS_SPENDING', $goal['metric']);
        self::assertSame('AXIS', $goal['scope_type']);
        self::assertSame('ESSENTIAL', $goal['scope_ref']);
        self::assertSame(WorkspaceFixture::OWNER_ID, $goal['created_by']);
        $version = $this->versionOf($goal['id']);
        self::assertSame(1, $version['version_no']);
        self::assertSame('INCOME_SHARE', $version['value_type']);
        self::assertSame('2026-10', $version['effective_from']);
        self::assertNull($version['target_amount']);
        self::assertSame(0, ExactDecimal::compareForResponse(self::text($version['ratio']), '0.40'));
        self::assertSame(2, $version['ratio_scale']);

        // Worked example 2: an annual goal keeps its amount and its twelve schedule parts.
        $annual = $this->goalById(self::ANNUAL_GOAL);
        self::assertSame('YEARLY', $annual['periodicity']);
        self::assertSame('2026', $annual['start_period']);
        self::assertSame('2026', $annual['end_period']);
        $annualVersion = $this->versionOf(self::ANNUAL_GOAL);
        self::assertSame('AMOUNT', $annualVersion['value_type']);
        self::assertSame(0, ExactDecimal::compareForResponse(self::text($annualVersion['target_amount']), '6000.00'));
        self::assertSame('12', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts WHERE goal_version_id = ?', [$annualVersion['id']]));
        self::assertSame('12', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts WHERE goal_version_id = ? AND amount = 500', [$annualVersion['id']]));

        // A closed year plan on a parent category: yearly amount, label from the category.
        $closed = $this->goalByLabel('Parent');
        self::assertSame('YEARLY', $closed['periodicity']);
        self::assertSame('2026', $closed['start_period']);
        self::assertSame('CATEGORY_SPENDING', $closed['metric']);
        self::assertSame(self::PARENT, $closed['scope_ref']);
        self::assertSame('2026-01', $this->versionOf($closed['id'])['effective_from']);

        // Former rolling goals: open stays open, closed ends in the month of its closing.
        self::assertNull($this->goalById(self::ROLLING_OPEN)['end_period']);
        self::assertSame('2026-05', $this->goalById(self::ROLLING_CLOSED)['end_period']);
        self::assertSame('2026-01', $this->goalById(self::ROLLING_CLOSED)['start_period']);
        self::assertSame('ROLLING_INCOME_SHARE', $this->versionOf(self::ROLLING_CLOSED)['value_type']);

        // A closed annual goal ends with the year of its closing, within its own years.
        self::assertSame('2024', $this->goalById(self::CLOSED_ANNUAL)['start_period']);
        self::assertSame('2025', $this->goalById(self::CLOSED_ANNUAL)['end_period']);

        // The draft plan produces nothing.
        self::assertSame('0', $this->scalar("SELECT count(*) FROM budget_goals WHERE label IN ('Brouillon', 'Seule')"));
        self::assertSame('6', $this->scalar('SELECT count(*) FROM budget_goals'));
    }

    public function testDownRecreatesEmptyPlanTablesAndRestoresTheFormerKinds(): void
    {
        $this->migrate('up');
        $this->unified = true;

        $this->migrate('down');
        $this->unified = false;

        self::assertSame('2', $this->scalar("SELECT count(*) FROM pg_tables WHERE tablename IN ('budget_plans', 'budget_targets')"));
        self::assertSame('0', $this->scalar('SELECT count(*) FROM budget_plans'));
        self::assertSame('ANNUAL', $this->scalar('SELECT kind FROM budget_goals WHERE id = ?', [self::ANNUAL_GOAL]));
        self::assertSame('2026', $this->scalar('SELECT year FROM budget_goal_versions WHERE goal_id = ?', [self::ANNUAL_GOAL]));
        self::assertSame('ROLLING_RATIO', $this->scalar('SELECT kind FROM budget_goals WHERE id = ?', [self::ROLLING_OPEN]));
        self::assertSame('ACTIVE', $this->scalar('SELECT state FROM budget_goals WHERE id = ?', [self::ROLLING_OPEN]));
        self::assertSame('CLOSED', $this->scalar('SELECT state FROM budget_goals WHERE id = ?', [self::ROLLING_CLOSED]));
        // The monthly income share has no former representation: it is removed with its version.
        self::assertSame('0', $this->scalar("SELECT count(*) FROM budget_goals WHERE label = 'Essentiel'"));
        // Both annual goals (three versions), the yearly amount from the closed plan and the two former rolling goals remain.
        self::assertSame('6', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
    }

    public function testDownAlsoRemovesMonthlyRollingSharesOnMetricsTheFormerModelRefused(): void
    {
        $this->migrate('up');
        $this->unified = true;
        foreach ([['BUDGET_SURPLUS', 'WORKSPACE', null], ['ACCOUNT_CONTRIBUTIONS', 'ACCOUNT', null]] as $index => [$metric, $scope]) {
            $id = sprintf('00000000-0000-7000-8000-0000000000f%d', $index);
            $this->connection->insert('budget_goals', ['id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => 'Nouveau '.$metric, 'periodicity' => 'MONTHLY', 'metric' => $metric, 'scope_type' => $scope, 'scope_ref' => 'ACCOUNT' === $scope ? self::LONELY : null, 'asset_code' => 'EUR', 'start_period' => '2026-01', 'end_period' => null, 'created_at' => self::AT, 'created_by' => WorkspaceFixture::OWNER_ID, 'version' => 1]);
            $this->connection->insert('budget_goal_versions', ['id' => sprintf('00000000-0000-7000-8000-0000000001f%d', $index), 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'goal_id' => $id, 'version_no' => 1, 'effective_from' => '2026-01', 'value_type' => 'ROLLING_INCOME_SHARE', 'target_amount' => null, 'target_scale' => null, 'ratio' => '0.1', 'ratio_scale' => 1, 'schedule_mode' => null, 'reason' => null, 'retroactive' => false, 'created_at' => self::AT, 'created_by' => WorkspaceFixture::OWNER_ID], ['retroactive' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
        }

        $this->migrate('down');
        $this->unified = false;

        self::assertSame('0', $this->scalar("SELECT count(*) FROM budget_goals WHERE label LIKE 'Nouveau %'"));
    }

    public function testItRefusesBeforeAnyChangeWhenATargetDoesNotFitAGoalAndNamesOnlyCounts(): void
    {
        // Ten decimals do not fit a goal definition (eight at most): never rounded, never skipped.
        $this->connection->insert('budget_plans', ['id' => self::WIDE_PLAN, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'period_type' => 'MONTH', 'period' => '2026-09-01', 'asset_code' => 'EUR', 'state' => 'ACTIVE', 'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT]);
        $this->target('00000000-0000-7000-8000-0000000000d4', self::WIDE_PLAN, 'CATEGORY', self::LONELY, 'AMOUNT', '0.1234567891', 10);
        $this->target('00000000-0000-7000-8000-0000000000d5', self::WIDE_PLAN, 'CATEGORY', self::PARENT, 'RATIO', '1.5', 1);

        [$status, $display] = $this->tryMigratingUp();

        self::assertNotSame(Command::SUCCESS, $status);
        self::assertStringContainsString('2 targets whose value does not fit a goal', $display);
        self::assertStringContainsString('0 targets in a workspace without owner', $display);
        foreach (['0.1234567891', 'Parent', 'Seule'] as $secret) {
            self::assertStringNotContainsString($secret, $display);
        }
        self::assertSame('2', $this->scalar("SELECT count(*) FROM pg_tables WHERE tablename IN ('budget_plans', 'budget_targets')"));
        self::assertSame('5', $this->scalar('SELECT count(*) FROM budget_targets'));
        self::assertSame('0', $this->scalar("SELECT count(*) FROM information_schema.columns WHERE table_name = 'budget_goals' AND column_name = 'periodicity'"));
    }

    public function testItRefusesBeforeAnyChangeWhenAWorkspaceHasNoOwner(): void
    {
        $this->connection->executeStatement("DELETE FROM identity_workspace_memberships WHERE workspace_id = ? AND role = 'OWNER'", [WorkspaceFixture::OWN_WORKSPACE]);

        [$status, $display] = $this->tryMigratingUp();

        self::assertNotSame(Command::SUCCESS, $status);
        self::assertStringContainsString('0 targets whose value does not fit a goal', $display);
        self::assertStringContainsString('2 targets in a workspace without owner', $display);
        self::assertSame('2', $this->scalar("SELECT count(*) FROM pg_tables WHERE tablename IN ('budget_plans', 'budget_targets')"));
    }

    private function seedLegacyData(): void
    {
        $own = WorkspaceFixture::OWN_WORKSPACE;
        foreach ([[self::PARENT, null, 'Parent'], [self::CHILD, self::PARENT, 'Enfant'], [self::LONELY, null, 'Seule']] as [$id, $parent, $label]) {
            $this->connection->insert('category_categories', ['id' => $id, 'workspace_id' => $own, 'type' => 'EXPENSE', 'label' => $label, 'parent_id' => $parent, 'icon' => null, 'color' => null, 'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => null === $parent ? 1 : 2, 'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT, 'archived_at' => null]);
        }
        foreach ([[self::ESSENTIAL_PLAN, 'MONTH', '2026-10-01', 'ACTIVE'], [self::DRAFT_PLAN, 'MONTH', '2026-11-01', 'DRAFT'], [self::YEAR_PLAN, 'YEAR', '2026-01-01', 'CLOSED']] as [$id, $type, $period, $state]) {
            $this->connection->insert('budget_plans', ['id' => $id, 'workspace_id' => $own, 'period_type' => $type, 'period' => $period, 'asset_code' => 'EUR', 'state' => $state, 'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT]);
        }
        $this->target('00000000-0000-7000-8000-0000000000d1', self::ESSENTIAL_PLAN, 'AXIS', 'ESSENTIAL', 'RATIO', '0.40', 2);
        $this->target('00000000-0000-7000-8000-0000000000d2', self::DRAFT_PLAN, 'CATEGORY', self::LONELY, 'AMOUNT', '10.00', 2, 'Brouillon');
        $this->target('00000000-0000-7000-8000-0000000000d3', self::YEAR_PLAN, 'CATEGORY', self::PARENT, 'AMOUNT', '1200.00', 2);

        // A closed annual goal over 2024 to 2026, closed during 2025.
        $this->connection->insert('budget_goals', ['id' => self::CLOSED_ANNUAL, 'workspace_id' => $own, 'label' => 'Ancien', 'kind' => 'ANNUAL', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope_type' => 'WORKSPACE', 'scope_ref' => null, 'asset_code' => 'EUR', 'state' => 'CLOSED', 'created_at' => self::AT, 'created_by' => WorkspaceFixture::OWNER_ID, 'closed_at' => '2025-06-10 10:00:00+00', 'version' => 1]);
        foreach ([[2024, 1, '0000000000e4'], [2026, 2, '0000000000e5']] as [$year, $number, $suffix]) {
            $this->connection->insert('budget_goal_versions', ['id' => '00000000-0000-7000-8000-'.$suffix, 'workspace_id' => $own, 'goal_id' => self::CLOSED_ANNUAL, 'version_no' => $number, 'effective_from' => $year.'-01', 'year' => $year, 'target_amount' => '100.00', 'target_scale' => 2, 'ratio' => null, 'ratio_scale' => null, 'schedule_mode' => null, 'reason' => null, 'created_at' => self::AT, 'created_by' => WorkspaceFixture::OWNER_ID]);
        }

        $this->connection->insert('budget_goals', ['id' => self::ANNUAL_GOAL, 'workspace_id' => $own, 'label' => 'Surplus', 'kind' => 'ANNUAL', 'metric' => 'BUDGET_SURPLUS', 'scope_type' => 'WORKSPACE', 'scope_ref' => null, 'asset_code' => 'EUR', 'state' => 'ACTIVE', 'created_at' => self::AT, 'created_by' => WorkspaceFixture::OWNER_ID, 'closed_at' => null, 'version' => 1]);
        $this->connection->transactional(function (): void {
            $this->connection->insert('budget_goal_versions', ['id' => '00000000-0000-7000-8000-0000000000e1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'goal_id' => self::ANNUAL_GOAL, 'version_no' => 1, 'effective_from' => '2026-01', 'year' => 2026, 'target_amount' => '6000.00', 'target_scale' => 2, 'ratio' => null, 'ratio_scale' => null, 'schedule_mode' => 'LINEAR', 'reason' => null, 'created_at' => self::AT, 'created_by' => WorkspaceFixture::OWNER_ID]);
            for ($month = 1; $month <= 12; ++$month) {
                $this->connection->insert('budget_goal_schedule_parts', ['goal_version_id' => '00000000-0000-7000-8000-0000000000e1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'month_index' => $month, 'amount' => '500.00', 'amount_scale' => 2]);
            }
        });
        foreach ([[self::ROLLING_OPEN, 'ACTIVE', null, '0000000000e2'], [self::ROLLING_CLOSED, 'CLOSED', '2026-05-20 10:00:00+00', '0000000000e3']] as [$id, $state, $closedAt, $versionSuffix]) {
            $this->connection->insert('budget_goals', ['id' => $id, 'workspace_id' => $own, 'label' => 'Rolling '.$state, 'kind' => 'ROLLING_RATIO', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope_type' => 'WORKSPACE', 'scope_ref' => null, 'asset_code' => 'EUR', 'state' => $state, 'created_at' => self::AT, 'created_by' => WorkspaceFixture::OWNER_ID, 'closed_at' => $closedAt, 'version' => 1]);
            $this->connection->insert('budget_goal_versions', ['id' => '00000000-0000-7000-8000-'.$versionSuffix, 'workspace_id' => $own, 'goal_id' => $id, 'version_no' => 1, 'effective_from' => '2026-01', 'year' => null, 'target_amount' => null, 'target_scale' => null, 'ratio' => '0.2', 'ratio_scale' => 1, 'schedule_mode' => null, 'reason' => null, 'created_at' => self::AT, 'created_by' => WorkspaceFixture::OWNER_ID]);
        }
    }

    private function target(string $id, string $plan, string $scope, string $scopeId, string $type, string $value, int $scale, string $unused = ''): void
    {
        $this->connection->insert('budget_targets', ['id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'plan_id' => $plan, 'scope_type' => $scope, 'scope_id' => $scopeId, 'value_type' => $type, 'amount_value' => 'AMOUNT' === $type ? $value : null, 'amount_scale' => 'AMOUNT' === $type ? $scale : null, 'ratio_value' => 'RATIO' === $type ? $value : null, 'ratio_scale' => 'RATIO' === $type ? $scale : null, 'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT]);
    }

    /** @return array<string, mixed> */
    private function goalByLabel(string $label): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM budget_goals WHERE label = ?', [$label]);
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function goalById(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM budget_goals WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function versionOf(mixed $goalId): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM budget_goal_versions WHERE goal_id = ?', [$goalId]);
        self::assertIsArray($row);

        return $row;
    }

    /** @param list<mixed> $parameters */
    private function scalar(string $sql, array $parameters = []): string
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertIsScalar($value);

        return (string) $value;
    }

    private static function text(mixed $value): string
    {
        self::assertIsScalar($value);

        return (string) $value;
    }

    private function adopt(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
    }

    /** @return array{0: int, 1: string} */
    private function tryMigratingUp(): array
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('doctrine:migrations:execute'));
        try {
            $tester->execute(['versions' => [self::MIGRATION], '--up' => true, '--no-interaction' => true]);
            $result = [$tester->getStatusCode(), $tester->getDisplay()];
        } catch (AbortMigration $abort) {
            $result = [Command::FAILURE, $abort->getMessage()];
        }
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->adopt();

        return $result;
    }

    private function migrate(string $direction): string
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('doctrine:migrations:execute'));
        $tester->execute(['versions' => [self::MIGRATION], '--'.$direction => true, '--no-interaction' => true]);
        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);

        // Doctrine freezes an executed migration instance: reboot, as the separate console processes do.
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->adopt();

        return $display;
    }
}
