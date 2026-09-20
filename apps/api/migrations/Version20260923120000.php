<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates workspace-scoped budget plans and their category, group or axis targets.';
    }

    public function up(Schema $schema): void
    {
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
                -- A target carries exactly one of the two value shapes: no legacy
                -- 50/30/20-style default can be read off a "missing" branch.
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

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE budget_targets');
        $this->addSql('DROP TABLE budget_plans');
    }
}
