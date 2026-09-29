<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the per-workspace emergency-fund policy.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE budget_emergency_fund_policies (
                workspace_id UUID NOT NULL,
                essential_mode VARCHAR(16) NOT NULL,
                essential_category_ids JSONB NOT NULL,
                reference_mode VARCHAR(24) NOT NULL,
                trailing_months SMALLINT NULL,
                target_months NUMERIC(50, 24) NOT NULL,
                version INTEGER NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_by UUID NOT NULL,
                PRIMARY KEY (workspace_id),
                CONSTRAINT budget_emergency_fund_policies_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT budget_emergency_fund_policies_essential_mode_valid
                    CHECK (essential_mode IN ('AXIS', 'CATEGORIES')),
                CONSTRAINT budget_emergency_fund_policies_categories_valid CHECK (
                    jsonb_typeof(essential_category_ids) = 'array'
                    AND (
                        (essential_mode = 'AXIS' AND jsonb_array_length(essential_category_ids) = 0)
                        OR (essential_mode = 'CATEGORIES' AND jsonb_array_length(essential_category_ids) BETWEEN 1 AND 50)
                    )
                ),
                CONSTRAINT budget_emergency_fund_policies_reference_mode_valid
                    CHECK (reference_mode IN ('PREVIOUS_YEAR', 'TRAILING_MONTHS')),
                CONSTRAINT budget_emergency_fund_policies_trailing_months_valid CHECK (
                    (reference_mode = 'PREVIOUS_YEAR' AND trailing_months IS NULL)
                    OR (reference_mode = 'TRAILING_MONTHS' AND trailing_months BETWEEN 1 AND 24)
                ),
                CONSTRAINT budget_emergency_fund_policies_target_months_valid
                    CHECK (target_months >= 0 AND target_months <= 60 AND target_months = round(target_months, 1)),
                CONSTRAINT budget_emergency_fund_policies_version_valid CHECK (version > 0)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE budget_emergency_fund_policies');
    }
}
