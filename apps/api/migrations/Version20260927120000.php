<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds configurable goals and their immutable definition versions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE budget_goals (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                label VARCHAR(80) NOT NULL,
                kind VARCHAR(16) NOT NULL,
                metric VARCHAR(32) NOT NULL,
                scope_type VARCHAR(16) NOT NULL,
                scope_ref VARCHAR(64) DEFAULT NULL,
                asset_code VARCHAR(16) NOT NULL,
                state VARCHAR(8) NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                created_by UUID NOT NULL,
                closed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                version INTEGER NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT budget_goals_workspace_fk FOREIGN KEY (workspace_id) REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT budget_goals_asset_fk FOREIGN KEY (asset_code) REFERENCES reference_assets (code),
                CONSTRAINT budget_goals_workspace_id_unique UNIQUE (workspace_id, id),
                CONSTRAINT budget_goals_kind_valid CHECK (kind IN ('ANNUAL', 'ROLLING_RATIO')),
                CONSTRAINT budget_goals_metric_valid CHECK (metric IN ('NET_SAVINGS_TRANSFERS', 'BUDGET_SURPLUS', 'CATEGORY_SPENDING', 'AXIS_SPENDING', 'ACCOUNT_CONTRIBUTIONS')),
                CONSTRAINT budget_goals_scope_valid CHECK (scope_type IN ('WORKSPACE', 'CATEGORY', 'AXIS', 'ACCOUNT')),
                CONSTRAINT budget_goals_scope_ref_valid CHECK ((scope_type = 'WORKSPACE' AND scope_ref IS NULL) OR (scope_type <> 'WORKSPACE' AND btrim(scope_ref) = scope_ref AND length(scope_ref) BETWEEN 1 AND 64)),
                CONSTRAINT budget_goals_state_valid CHECK (state IN ('ACTIVE', 'CLOSED')),
                CONSTRAINT budget_goals_closed_valid CHECK ((state = 'ACTIVE' AND closed_at IS NULL) OR (state = 'CLOSED' AND closed_at IS NOT NULL)),
                CONSTRAINT budget_goals_label_valid CHECK (btrim(label) = label AND length(label) BETWEEN 1 AND 80),
                CONSTRAINT budget_goals_version_valid CHECK (version > 0)
            )
        SQL);
        $this->addSql('CREATE INDEX budget_goals_listing_index ON budget_goals (workspace_id, state, kind, created_at, id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE budget_goal_versions (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                goal_id UUID NOT NULL,
                version_no INTEGER NOT NULL,
                effective_from CHAR(7) NOT NULL,
                year INTEGER DEFAULT NULL,
                target_amount NUMERIC(50, 24) DEFAULT NULL,
                target_scale SMALLINT DEFAULT NULL,
                ratio NUMERIC(50, 24) DEFAULT NULL,
                ratio_scale SMALLINT DEFAULT NULL,
                schedule_mode VARCHAR(8) DEFAULT NULL,
                reason VARCHAR(200) DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                created_by UUID NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT budget_goal_versions_goal_fk FOREIGN KEY (workspace_id, goal_id) REFERENCES budget_goals (workspace_id, id) ON DELETE CASCADE,
                CONSTRAINT budget_goal_versions_workspace_id_unique UNIQUE (workspace_id, id),
                CONSTRAINT budget_goal_versions_goal_no_unique UNIQUE (goal_id, version_no),
                CONSTRAINT budget_goal_versions_no_valid CHECK (version_no > 0),
                CONSTRAINT budget_goal_versions_month_valid CHECK (effective_from ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
                CONSTRAINT budget_goal_versions_shape_valid CHECK ((year IS NOT NULL AND target_amount IS NOT NULL AND target_scale IS NOT NULL AND ratio IS NULL AND ratio_scale IS NULL) OR (year IS NULL AND target_amount IS NULL AND target_scale IS NULL AND ratio IS NOT NULL AND ratio_scale IS NOT NULL)),
                CONSTRAINT budget_goal_versions_year_valid CHECK (year IS NULL OR year BETWEEN 1900 AND 2999),
                CONSTRAINT budget_goal_versions_amount_valid CHECK (target_amount IS NULL OR target_amount > 0),
                CONSTRAINT budget_goal_versions_ratio_valid CHECK (ratio IS NULL OR (ratio > 0 AND ratio <= 1)),
                CONSTRAINT budget_goal_versions_scales_valid CHECK ((target_scale IS NULL OR target_scale BETWEEN 0 AND 8) AND (ratio_scale IS NULL OR ratio_scale BETWEEN 0 AND 8)),
                CONSTRAINT budget_goal_versions_reason_valid CHECK (reason IS NULL OR length(reason) <= 200)
            )
        SQL);
        $this->addSql('CREATE INDEX budget_goal_versions_resolution_index ON budget_goal_versions (workspace_id, goal_id, effective_from DESC, version_no DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE budget_goal_versions');
        $this->addSql('DROP TABLE budget_goals');
    }
}
