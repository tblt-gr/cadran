<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the frozen monthly report snapshots and the annual report column preferences.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE reporting_month_snapshots (
                workspace_id UUID NOT NULL,
                month CHAR(7) NOT NULL,
                closure_id UUID NOT NULL,
                policy_version INTEGER NOT NULL,
                schema_version INTEGER NOT NULL,
                payload JSONB NOT NULL,
                captured_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (workspace_id, month, closure_id),
                CONSTRAINT reporting_month_snapshots_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT reporting_month_snapshots_month_valid CHECK (month ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
                CONSTRAINT reporting_month_snapshots_versions_valid CHECK (policy_version >= 1 AND schema_version >= 1)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE reporting_annual_report_preferences (
                workspace_id UUID NOT NULL,
                columns JSONB NOT NULL,
                incomplete_months VARCHAR(8) NOT NULL,
                version INTEGER NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (workspace_id),
                CONSTRAINT reporting_annual_report_preferences_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT reporting_annual_report_preferences_columns_valid
                    CHECK (jsonb_typeof(columns) = 'array' AND jsonb_array_length(columns) <= 40),
                CONSTRAINT reporting_annual_report_preferences_incomplete_valid
                    CHECK (incomplete_months IN ('exclude', 'include')),
                CONSTRAINT reporting_annual_report_preferences_version_valid CHECK (version > 0)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reporting_annual_report_preferences');
        $this->addSql('DROP TABLE reporting_month_snapshots');
    }
}
