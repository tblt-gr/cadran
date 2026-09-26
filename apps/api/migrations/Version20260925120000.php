<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the versioned metric policies and their append-only activation history.';
    }

    public function up(Schema $schema): void
    {
        // Version 1 is built into the code and has no row; workspace versions start
        // at 2. policy_version carries no foreign key for that reason: the
        // application checks that a version exists before it activates it.
        $this->addSql(<<<'SQL'
            CREATE TABLE reporting_metric_policies (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                version INTEGER NOT NULL,
                label VARCHAR(80) NOT NULL,
                cash_excluded_account_kinds JSONB NOT NULL,
                savings_rate_formula VARCHAR(64) NOT NULL,
                net_savings_rate_formula VARCHAR(64) NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                created_by UUID NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT reporting_metric_policies_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT reporting_metric_policies_version_valid CHECK (version >= 2),
                CONSTRAINT reporting_metric_policies_kinds_valid
                    CHECK (jsonb_typeof(cash_excluded_account_kinds) = 'array' AND jsonb_array_length(cash_excluded_account_kinds) <= 8),
                CONSTRAINT reporting_metric_policies_workspace_version_unique UNIQUE (workspace_id, version)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE reporting_metric_policy_activations (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                policy_version INTEGER NOT NULL,
                active_from TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                reason VARCHAR(200) DEFAULT NULL,
                created_by UUID NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT reporting_metric_policy_activations_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT reporting_metric_policy_activations_version_valid CHECK (policy_version >= 1),
                CONSTRAINT reporting_metric_policy_activations_instant_unique UNIQUE (workspace_id, active_from)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reporting_metric_policy_activations');
        $this->addSql('DROP TABLE reporting_metric_policies');
    }
}
