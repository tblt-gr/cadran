<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unifies budget plans, targets and goals. A pre-migration backup is mandatory: the plan tables are
 * dropped, and the down migration is destructive (it recreates empty plan tables and deletes every
 * goal the former model cannot express).
 *
 * The migration refuses to run, before touching any data, when a target of an ACTIVE or CLOSED plan
 * cannot be carried over exactly (more than eight decimals, a ratio above one, or a workspace without
 * owner). Draft plans are the only rows skipped.
 */
final class Version20261108120000 extends AbstractMigration
{
    /**
     * Targets of an ACTIVE or CLOSED plan whose value fits a goal: at most eight
     * decimals and a ratio of at most one, in a workspace that has an owner.
     */
    private const string MIGRATABLE_TARGETS = <<<'SQL'
        FROM budget_targets t
        JOIN budget_plans p ON p.workspace_id = t.workspace_id AND p.id = t.plan_id
        JOIN identity_workspace_memberships m ON m.workspace_id = t.workspace_id AND m.role = 'OWNER'
        WHERE p.state IN ('ACTIVE', 'CLOSED')
          AND ((t.value_type = 'AMOUNT' AND t.amount_scale <= 8)
            OR (t.value_type = 'RATIO' AND t.ratio_scale <= 8 AND t.ratio_value <= 1))
        SQL;

    /** @var array{migrated: int, drafts: int, descendants: int} */
    private array $counts = ['migrated' => 0, 'drafts' => 0, 'descendants' => 0];

    public function getDescription(): string
    {
        return 'Unifies budget plans, budget targets and goals into monthly or yearly goals.';
    }

    public function preUp(Schema $schema): void
    {
        $count = fn (string $sql): int => (int) $this->connection->fetchOne($sql);
        $live = "FROM budget_targets t JOIN budget_plans p ON p.workspace_id = t.workspace_id AND p.id = t.plan_id WHERE p.state <> 'DRAFT'";
        $noOwner = $count('SELECT count(*) '.$live." AND NOT EXISTS (SELECT 1 FROM identity_workspace_memberships m WHERE m.workspace_id = t.workspace_id AND m.role = 'OWNER')");
        $unrepresentable = $count('SELECT count(*) '.$live.' AND EXISTS (SELECT 1 FROM identity_workspace_memberships m WHERE m.workspace_id = t.workspace_id)'.
            " AND NOT ((t.value_type = 'AMOUNT' AND t.amount_scale <= 8) OR (t.value_type = 'RATIO' AND t.ratio_scale <= 8 AND t.ratio_value <= 1))");
        $this->abortIf(
            $noOwner > 0 || $unrepresentable > 0,
            sprintf('Goals unification refused before any change: %d targets whose value does not fit a goal (more than 8 decimals or a ratio above 1), %d targets in a workspace without owner. Fix them, and keep a backup before retrying.', $unrepresentable, $noOwner),
        );
        $this->counts = [
            'migrated' => $count('SELECT count(*) '.self::MIGRATABLE_TARGETS),
            'drafts' => $count("SELECT count(*) FROM budget_targets t JOIN budget_plans p ON p.workspace_id = t.workspace_id AND p.id = t.plan_id WHERE p.state = 'DRAFT'"),
            'descendants' => $count("SELECT count(*) FROM budget_targets t JOIN budget_plans p ON p.workspace_id = t.workspace_id AND p.id = t.plan_id WHERE p.state <> 'DRAFT' AND t.scope_type <> 'AXIS' AND EXISTS (SELECT 1 FROM category_categories c WHERE c.workspace_id = t.workspace_id AND c.parent_id::text = t.scope_id)"),
        ];
    }

    public function postUp(Schema $schema): void
    {
        $this->write(sprintf(
            'Goals unification: %d targets migrated, %d draft targets skipped, %d category targets on a category with descendants.',
            $this->counts['migrated'],
            $this->counts['drafts'],
            $this->counts['descendants'],
        ));
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget_goals ADD periodicity VARCHAR(7) DEFAULT NULL, ADD start_period VARCHAR(7) DEFAULT NULL, ADD end_period VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE budget_goal_versions ADD value_type VARCHAR(20) DEFAULT NULL');

        // D8: annual goals span the years of their versions, rolling goals start at their first month.
        $this->addSql(<<<'SQL'
            UPDATE budget_goals g SET periodicity = 'YEARLY',
                start_period = (SELECT min(v.year)::text FROM budget_goal_versions v WHERE v.goal_id = g.id),
                end_period = (SELECT max(v.year)::text FROM budget_goal_versions v WHERE v.goal_id = g.id)
            WHERE g.kind = 'ANNUAL'
            SQL);
        // A closed annual goal ends with the year of its closing, never before its first year or after its last.
        $this->addSql(<<<'SQL'
            UPDATE budget_goals g SET end_period = greatest(g.start_period, least(g.end_period, to_char(g.closed_at AT TIME ZONE 'UTC', 'YYYY')))
            WHERE g.kind = 'ANNUAL' AND g.state = 'CLOSED'
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE budget_goals g SET periodicity = 'MONTHLY',
                start_period = (SELECT min(v.effective_from) FROM budget_goal_versions v WHERE v.goal_id = g.id),
                end_period = CASE WHEN g.state = 'CLOSED'
                    THEN greatest(to_char(g.closed_at AT TIME ZONE 'UTC', 'YYYY-MM'), (SELECT min(v.effective_from) FROM budget_goal_versions v WHERE v.goal_id = g.id))
                    ELSE NULL END
            WHERE g.kind = 'ROLLING_RATIO'
            SQL);
        $this->addSql("UPDATE budget_goal_versions v SET value_type = CASE WHEN g.kind = 'ANNUAL' THEN 'AMOUNT' ELSE 'ROLLING_INCOME_SHARE' END FROM budget_goals g WHERE g.id = v.goal_id");

        $this->addSql('ALTER TABLE budget_goals ALTER periodicity SET NOT NULL, ALTER start_period SET NOT NULL');
        $this->addSql('ALTER TABLE budget_goal_versions ALTER value_type SET NOT NULL');

        // D9: stage the plan targets while the plan tables still exist.
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE goal_unification_targets AS
            SELECT gen_random_uuid() AS goal_id, gen_random_uuid() AS version_id, t.workspace_id, m.user_id AS owner_id,
                p.period_type, to_char(p.period, 'YYYY-MM') AS first_month, to_char(p.period, 'YYYY') AS first_year,
                p.asset_code, p.created_at, t.scope_type, t.scope_id, t.value_type,
                t.amount_value, t.amount_scale, t.ratio_value, t.ratio_scale,
                CASE WHEN t.scope_type = 'AXIS' THEN CASE t.scope_id
                        WHEN 'DISCRETIONARY' THEN 'Discrétionnaire' WHEN 'ESSENTIAL' THEN 'Essentiel' WHEN 'FIXED' THEN 'Fixe'
                        WHEN 'PERSONAL' THEN 'Personnel' WHEN 'PROFESSIONAL' THEN 'Professionnel' WHEN 'VARIABLE' THEN 'Variable'
                        ELSE 'Axe indisponible' END
                    ELSE coalesce((SELECT c.label FROM category_categories c WHERE c.workspace_id = t.workspace_id AND c.id::text = t.scope_id), 'Catégorie indisponible')
                END AS label
            SQL.' '.self::MIGRATABLE_TARGETS);

        $this->addSql('DROP INDEX budget_goals_listing_index');
        $this->addSql(<<<'SQL'
            ALTER TABLE budget_goals
                DROP CONSTRAINT budget_goals_kind_valid,
                DROP CONSTRAINT budget_goals_state_valid,
                DROP CONSTRAINT budget_goals_closed_valid,
                DROP CONSTRAINT budget_goals_metric_valid,
                DROP COLUMN kind, DROP COLUMN state, DROP COLUMN closed_at
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE budget_goals
                ADD CONSTRAINT budget_goals_metric_valid CHECK (metric IN ('NET_SAVINGS_TRANSFERS', 'BUDGET_SURPLUS', 'CATEGORY_SPENDING', 'AXIS_SPENDING', 'ACCOUNT_CONTRIBUTIONS', 'SAVINGS_RATE', 'NET_SAVINGS_RATE')),
                ADD CONSTRAINT budget_goals_periodicity_valid CHECK (periodicity IN ('MONTHLY', 'YEARLY')),
                ADD CONSTRAINT budget_goals_period_format_valid CHECK (
                    (periodicity = 'MONTHLY' AND start_period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$' AND (end_period IS NULL OR end_period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'))
                    OR (periodicity = 'YEARLY' AND start_period ~ '^[0-9]{4}$' AND (end_period IS NULL OR end_period ~ '^[0-9]{4}$'))
                ),
                ADD CONSTRAINT budget_goals_period_order_valid CHECK (end_period IS NULL OR end_period >= start_period)
            SQL);
        $this->addSql('CREATE INDEX budget_goals_listing_index ON budget_goals (workspace_id, periodicity, start_period, created_at, id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE budget_goal_versions
                DROP CONSTRAINT budget_goal_versions_shape_valid,
                DROP CONSTRAINT budget_goal_versions_year_valid,
                DROP CONSTRAINT budget_goal_versions_ratio_valid,
                DROP CONSTRAINT budget_goal_versions_schedule_mode_valid,
                DROP COLUMN year
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE budget_goal_versions
                ADD CONSTRAINT budget_goal_versions_value_type_valid CHECK (value_type IN ('AMOUNT', 'INCOME_SHARE', 'ROLLING_INCOME_SHARE', 'RATE')),
                ADD CONSTRAINT budget_goal_versions_shape_valid CHECK (
                    (value_type = 'AMOUNT' AND target_amount IS NOT NULL AND target_scale IS NOT NULL AND ratio IS NULL AND ratio_scale IS NULL)
                    OR (value_type <> 'AMOUNT' AND target_amount IS NULL AND target_scale IS NULL AND ratio IS NOT NULL AND ratio_scale IS NOT NULL)
                ),
                ADD CONSTRAINT budget_goal_versions_ratio_valid CHECK (
                    ratio IS NULL OR (ratio <= 1 AND (ratio > 0 OR (value_type = 'RATE' AND ratio >= 0)))
                ),
                ADD CONSTRAINT budget_goal_versions_schedule_mode_valid CHECK (schedule_mode IS NULL OR (schedule_mode IN ('LINEAR', 'CUSTOM') AND value_type = 'AMOUNT'))
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO budget_goals (id, workspace_id, label, metric, scope_type, scope_ref, asset_code, created_at, created_by, version, periodicity, start_period, end_period)
            SELECT goal_id, workspace_id, btrim(left(label, 80)),
                CASE scope_type WHEN 'AXIS' THEN 'AXIS_SPENDING' ELSE 'CATEGORY_SPENDING' END,
                CASE scope_type WHEN 'AXIS' THEN 'AXIS' ELSE 'CATEGORY' END,
                scope_id, asset_code, created_at, owner_id, 1,
                CASE period_type WHEN 'MONTH' THEN 'MONTHLY' ELSE 'YEARLY' END,
                CASE period_type WHEN 'MONTH' THEN first_month ELSE first_year END,
                CASE period_type WHEN 'MONTH' THEN first_month ELSE first_year END
            FROM goal_unification_targets
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO budget_goal_versions (id, workspace_id, goal_id, version_no, effective_from, value_type, target_amount, target_scale, ratio, ratio_scale, schedule_mode, reason, created_at, created_by, retroactive)
            SELECT version_id, workspace_id, goal_id, 1, first_month,
                CASE value_type WHEN 'AMOUNT' THEN 'AMOUNT' ELSE 'INCOME_SHARE' END,
                amount_value, amount_scale, ratio_value, ratio_scale, NULL, NULL, created_at, owner_id, FALSE
            FROM goal_unification_targets
            SQL);
        $this->addSql('DROP TABLE goal_unification_targets');
        $this->addSql('DROP TABLE budget_targets');
        $this->addSql('DROP TABLE budget_plans');
    }

    /**
     * Destructive: recreates the dropped columns and the empty plan tables. Goals that the
     * previous model could not express (monthly amounts, income shares, rates,
     * rate metrics) are removed with their versions; plans are not restored.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM budget_goals g
            WHERE g.metric IN ('SAVINGS_RATE', 'NET_SAVINGS_RATE')
               OR (g.periodicity = 'MONTHLY' AND g.metric IN ('BUDGET_SURPLUS', 'ACCOUNT_CONTRIBUTIONS'))
               OR NOT (
                    (g.periodicity = 'YEARLY' AND NOT EXISTS (SELECT 1 FROM budget_goal_versions v WHERE v.goal_id = g.id AND v.value_type <> 'AMOUNT'))
                    OR (g.periodicity = 'MONTHLY' AND NOT EXISTS (SELECT 1 FROM budget_goal_versions v WHERE v.goal_id = g.id AND v.value_type <> 'ROLLING_INCOME_SHARE'))
               )
            SQL);
        $this->addSql("ALTER TABLE budget_goals ADD kind VARCHAR(16) DEFAULT NULL, ADD state VARCHAR(8) NOT NULL DEFAULT 'ACTIVE', ADD closed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL");
        $this->addSql("UPDATE budget_goals SET kind = CASE periodicity WHEN 'YEARLY' THEN 'ANNUAL' ELSE 'ROLLING_RATIO' END");
        $this->addSql("UPDATE budget_goals SET state = 'CLOSED', closed_at = ((end_period || '-01')::date + INTERVAL '1 month' - INTERVAL '1 second') AT TIME ZONE 'UTC' WHERE periodicity = 'MONTHLY' AND end_period IS NOT NULL");
        $this->addSql('ALTER TABLE budget_goals ALTER kind SET NOT NULL, ALTER state DROP DEFAULT');
        $this->addSql('ALTER TABLE budget_goal_versions ADD year INTEGER DEFAULT NULL');
        $this->addSql("UPDATE budget_goal_versions SET year = substr(effective_from, 1, 4)::int WHERE value_type = 'AMOUNT'");

        $this->addSql('DROP INDEX budget_goals_listing_index');
        $this->addSql(<<<'SQL'
            ALTER TABLE budget_goals
                DROP CONSTRAINT budget_goals_metric_valid,
                DROP CONSTRAINT budget_goals_periodicity_valid,
                DROP CONSTRAINT budget_goals_period_format_valid,
                DROP CONSTRAINT budget_goals_period_order_valid,
                DROP COLUMN periodicity, DROP COLUMN start_period, DROP COLUMN end_period
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE budget_goals
                ADD CONSTRAINT budget_goals_kind_valid CHECK (kind IN ('ANNUAL', 'ROLLING_RATIO')),
                ADD CONSTRAINT budget_goals_metric_valid CHECK (metric IN ('NET_SAVINGS_TRANSFERS', 'BUDGET_SURPLUS', 'CATEGORY_SPENDING', 'AXIS_SPENDING', 'ACCOUNT_CONTRIBUTIONS')),
                ADD CONSTRAINT budget_goals_state_valid CHECK (state IN ('ACTIVE', 'CLOSED')),
                ADD CONSTRAINT budget_goals_closed_valid CHECK ((state = 'ACTIVE' AND closed_at IS NULL) OR (state = 'CLOSED' AND closed_at IS NOT NULL))
            SQL);
        $this->addSql('CREATE INDEX budget_goals_listing_index ON budget_goals (workspace_id, state, kind, created_at, id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE budget_goal_versions
                DROP CONSTRAINT budget_goal_versions_value_type_valid,
                DROP CONSTRAINT budget_goal_versions_shape_valid,
                DROP CONSTRAINT budget_goal_versions_ratio_valid,
                DROP CONSTRAINT budget_goal_versions_schedule_mode_valid,
                DROP COLUMN value_type
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE budget_goal_versions
                ADD CONSTRAINT budget_goal_versions_shape_valid CHECK ((year IS NOT NULL AND target_amount IS NOT NULL AND target_scale IS NOT NULL AND ratio IS NULL AND ratio_scale IS NULL) OR (year IS NULL AND target_amount IS NULL AND target_scale IS NULL AND ratio IS NOT NULL AND ratio_scale IS NOT NULL)),
                ADD CONSTRAINT budget_goal_versions_year_valid CHECK (year IS NULL OR year BETWEEN 1900 AND 2999),
                ADD CONSTRAINT budget_goal_versions_ratio_valid CHECK (ratio IS NULL OR (ratio > 0 AND ratio <= 1)),
                ADD CONSTRAINT budget_goal_versions_schedule_mode_valid CHECK (schedule_mode IS NULL OR (schedule_mode IN ('LINEAR', 'CUSTOM') AND target_amount IS NOT NULL))
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE budget_plans (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                period_type VARCHAR(5) NOT NULL,
                period DATE NOT NULL,
                asset_code VARCHAR(12) NOT NULL,
                state VARCHAR(8) NOT NULL DEFAULT 'DRAFT',
                version INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT budget_plans_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT budget_plans_asset_fk FOREIGN KEY (asset_code)
                    REFERENCES reference_assets (code) ON DELETE RESTRICT,
                CONSTRAINT budget_plans_workspace_id_unique UNIQUE (workspace_id, id),
                CONSTRAINT budget_plans_period_type_valid CHECK (period_type IN ('MONTH', 'YEAR')),
                CONSTRAINT budget_plans_state_valid CHECK (state IN ('DRAFT', 'ACTIVE', 'CLOSED')),
                CONSTRAINT budget_plans_period_canonical CHECK (
                    (period_type = 'MONTH' AND EXTRACT(DAY FROM period) = 1)
                    OR (period_type = 'YEAR' AND EXTRACT(MONTH FROM period) = 1 AND EXTRACT(DAY FROM period) = 1)
                ),
                CONSTRAINT budget_plans_version_valid CHECK (version > 0)
            )
            SQL);
        $this->addSql('CREATE INDEX budget_plans_listing_index ON budget_plans (workspace_id, period DESC, id DESC)');
        $this->addSql("CREATE UNIQUE INDEX budget_plans_active_period_unique ON budget_plans (workspace_id, period_type, period) WHERE state = 'ACTIVE'");
        $this->addSql(<<<'SQL'
            CREATE TABLE budget_targets (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                plan_id UUID NOT NULL,
                scope_type VARCHAR(8) NOT NULL,
                scope_id VARCHAR(64) NOT NULL,
                value_type VARCHAR(6) NOT NULL,
                amount_value NUMERIC(50,24) DEFAULT NULL,
                amount_scale SMALLINT DEFAULT NULL,
                ratio_value NUMERIC(50,24) DEFAULT NULL,
                ratio_scale SMALLINT DEFAULT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT budget_targets_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT budget_targets_plan_fk FOREIGN KEY (workspace_id, plan_id)
                    REFERENCES budget_plans (workspace_id, id) ON DELETE CASCADE,
                CONSTRAINT budget_targets_workspace_id_unique UNIQUE (workspace_id, id),
                CONSTRAINT budget_targets_scope_type_valid CHECK (scope_type IN ('CATEGORY', 'GROUP', 'AXIS')),
                CONSTRAINT budget_targets_value_type_valid CHECK (value_type IN ('AMOUNT', 'RATIO')),
                CONSTRAINT budget_targets_value_matches_type CHECK (
                    (value_type = 'AMOUNT' AND amount_value IS NOT NULL AND amount_scale IS NOT NULL AND ratio_value IS NULL AND ratio_scale IS NULL)
                    OR (value_type = 'RATIO' AND ratio_value IS NOT NULL AND ratio_scale IS NOT NULL AND amount_value IS NULL AND amount_scale IS NULL)
                ),
                CONSTRAINT budget_targets_amount_positive CHECK (amount_value IS NULL OR amount_value > 0),
                CONSTRAINT budget_targets_ratio_positive CHECK (ratio_value IS NULL OR ratio_value > 0),
                CONSTRAINT budget_targets_amount_scale_valid CHECK (amount_scale IS NULL OR amount_scale BETWEEN 0 AND 24),
                CONSTRAINT budget_targets_ratio_scale_valid CHECK (ratio_scale IS NULL OR ratio_scale BETWEEN 0 AND 24),
                CONSTRAINT budget_targets_scope_id_present CHECK (btrim(scope_id) = scope_id AND scope_id <> ''),
                CONSTRAINT budget_targets_version_valid CHECK (version > 0)
            )
            SQL);
        $this->addSql('CREATE INDEX budget_targets_plan_index ON budget_targets (workspace_id, plan_id)');
    }
}
