<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds workspace-scoped categorization rules, one-shot previews and split provenance.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_categorization_rules (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                label VARCHAR(80) NOT NULL,
                priority SMALLINT NOT NULL,
                account_scope JSONB NOT NULL DEFAULT '[]'::JSONB,
                conditions JSONB NOT NULL,
                target_category_id UUID NOT NULL,
                target_axes JSONB NOT NULL DEFAULT '[]'::JSONB,
                target_counterparty VARCHAR(80) DEFAULT NULL,
                effective_from DATE NOT NULL,
                effective_to DATE DEFAULT NULL,
                active BOOLEAN NOT NULL DEFAULT TRUE,
                deactivated_reason VARCHAR(32) DEFAULT NULL,
                applied_count INTEGER NOT NULL DEFAULT 0,
                version INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                archived_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT transaction_categorization_rules_workspace_id_unique UNIQUE (workspace_id, id),
                CONSTRAINT transaction_categorization_rules_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT transaction_categorization_rules_category_fk FOREIGN KEY (workspace_id, target_category_id)
                    REFERENCES category_categories (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_categorization_rules_priority_valid CHECK (priority BETWEEN 1 AND 999),
                CONSTRAINT transaction_categorization_rules_label_present CHECK (btrim(label) = label AND label <> ''),
                CONSTRAINT transaction_categorization_rules_period_ordered CHECK (effective_to IS NULL OR effective_to >= effective_from),
                CONSTRAINT transaction_categorization_rules_conditions_shape CHECK (jsonb_typeof(conditions) = 'object' AND length(conditions::text) <= 8192),
                CONSTRAINT transaction_categorization_rules_scope_shape CHECK (jsonb_typeof(account_scope) = 'array' AND jsonb_array_length(account_scope) <= 20),
                CONSTRAINT transaction_categorization_rules_axes_valid CHECK (jsonb_typeof(target_axes) = 'array' AND target_axes <@ '["DISCRETIONARY", "ESSENTIAL", "FIXED", "PERSONAL", "PROFESSIONAL", "VARIABLE"]'::JSONB),
                CONSTRAINT transaction_categorization_rules_deactivation_reason_valid CHECK (deactivated_reason IS NULL OR deactivated_reason IN ('USER', 'PATTERN_BUDGET_EXCEEDED', 'CATEGORY_ARCHIVED')),
                CONSTRAINT transaction_categorization_rules_version_valid CHECK (version > 0)
            )
            SQL);
        $this->addSql('CREATE INDEX transaction_categorization_rules_order_index ON transaction_categorization_rules (workspace_id, priority, created_at, id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_categorization_previews (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                preview_token CHAR(64) NOT NULL,
                rule_id UUID DEFAULT NULL,
                from_date DATE NOT NULL,
                to_date DATE NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                expires_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                consumed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT transaction_categorization_previews_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT transaction_categorization_previews_rule_fk FOREIGN KEY (workspace_id, rule_id)
                    REFERENCES transaction_categorization_rules (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_categorization_previews_token_valid CHECK (preview_token ~ '^[0-9a-f]{64}$'),
                CONSTRAINT transaction_categorization_previews_period_valid CHECK (to_date >= from_date),
                CONSTRAINT transaction_categorization_previews_expiry_valid CHECK (expires_at > created_at)
            )
            SQL);
        $this->addSql('CREATE INDEX transaction_categorization_previews_lookup_index ON transaction_categorization_previews (workspace_id, preview_token, created_at DESC)');
        $this->addSql("ALTER TABLE transaction_splits ADD categorization_origin VARCHAR(6) NOT NULL DEFAULT 'MANUAL', ADD categorization_rule_id UUID DEFAULT NULL");
        $this->addSql("ALTER TABLE transaction_splits ADD CONSTRAINT transaction_splits_origin_valid CHECK (categorization_origin IN ('MANUAL', 'RULE'))");
        $this->addSql("ALTER TABLE transaction_splits ADD CONSTRAINT transaction_splits_rule_matches_origin CHECK ((categorization_origin = 'RULE') = (categorization_rule_id IS NOT NULL))");
        $this->addSql('ALTER TABLE transaction_splits ADD CONSTRAINT transaction_splits_rule_fk FOREIGN KEY (workspace_id, categorization_rule_id) REFERENCES transaction_categorization_rules (workspace_id, id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction_splits DROP CONSTRAINT transaction_splits_rule_fk, DROP CONSTRAINT transaction_splits_rule_matches_origin, DROP CONSTRAINT transaction_splits_origin_valid, DROP COLUMN categorization_rule_id, DROP COLUMN categorization_origin');
        $this->addSql('DROP TABLE transaction_categorization_previews');
        $this->addSql('DROP TABLE transaction_categorization_rules');
    }
}
