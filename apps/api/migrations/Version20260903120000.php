<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates workspace-scoped financial accounts with lifecycle dates, valuation mode and inclusion policies.';
    }

    public function up(Schema $schema): void
    {
        // The database repeats the aggregate invariants rather than trusting
        // the application to be the only writer: a migration, a fixture or a
        // future import reaching this table gets the same refusals.
        $this->addSql(<<<'SQL'
            CREATE TABLE account_financial_accounts (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                label VARCHAR(80) NOT NULL,
                normalized_label TEXT GENERATED ALWAYS AS (lower(btrim(label))) STORED,
                asset_code VARCHAR(12) NOT NULL,
                kind VARCHAR(24) NOT NULL,
                masked_identifier VARCHAR(8) DEFAULT NULL,
                valuation_mode VARCHAR(16) NOT NULL,
                liquidity_level VARCHAR(16) NOT NULL,
                include_in_net_worth BOOLEAN NOT NULL DEFAULT TRUE,
                include_in_emergency_fund BOOLEAN NOT NULL DEFAULT FALSE,
                opened_on DATE NOT NULL,
                closed_on DATE DEFAULT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                used_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                archived_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT account_financial_accounts_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                -- The asset reference is global and read-only, so an account can
                -- only be denominated in a unit the application knows how to
                -- store and round.
                CONSTRAINT account_financial_accounts_asset_fk FOREIGN KEY (asset_code)
                    REFERENCES reference_assets (code) ON DELETE RESTRICT,
                CONSTRAINT account_financial_accounts_label_present CHECK (btrim(label) = label AND label <> ''),
                CONSTRAINT account_financial_accounts_label_bounded CHECK (char_length(label) <= 80),
                -- Eight characters cannot hold an IBAN or a card number, which
                -- is what keeps a full banking identifier out of this table.
                CONSTRAINT account_financial_accounts_masked_identifier_valid
                    CHECK (masked_identifier IS NULL OR masked_identifier ~ '^[A-Z0-9]{2,8}$'),
                CONSTRAINT account_financial_accounts_kind_valid CHECK (kind IN (
                    'CURRENT', 'SAVINGS', 'PORTFOLIO', 'INSURANCE_CONTRACT',
                    'EMPLOYEE_BENEFIT', 'CASH', 'REAL_ASSET', 'LIABILITY')),
                CONSTRAINT account_financial_accounts_valuation_mode_valid
                    CHECK (valuation_mode IN ('TRANSACTIONS', 'SNAPSHOTS', 'PORTFOLIO')),
                CONSTRAINT account_financial_accounts_liquidity_level_valid
                    CHECK (liquidity_level IN ('IMMEDIATE', 'SHORT_TERM', 'MEDIUM_TERM', 'LONG_TERM', 'ILLIQUID')),
                CONSTRAINT account_financial_accounts_portfolio_valuation_holds_positions CHECK (
                    valuation_mode <> 'PORTFOLIO'
                    OR kind IN ('PORTFOLIO', 'INSURANCE_CONTRACT', 'EMPLOYEE_BENEFIT')
                ),
                CONSTRAINT account_financial_accounts_emergency_fund_counted
                    CHECK (NOT include_in_emergency_fund OR include_in_net_worth),
                -- The day of the last write is the row's own notion of "now".
                -- Creation and update are compared together so a later
                -- correction can still record a past opening date.
                CONSTRAINT account_financial_accounts_opened_on_bounded CHECK (
                    opened_on >= DATE '1900-01-01'
                    AND opened_on <= GREATEST((created_at AT TIME ZONE 'UTC')::DATE, (updated_at AT TIME ZONE 'UTC')::DATE)
                ),
                CONSTRAINT account_financial_accounts_closed_after_opening
                    CHECK (closed_on IS NULL OR closed_on >= opened_on),
                CONSTRAINT account_financial_accounts_closed_not_in_future CHECK (
                    closed_on IS NULL
                    OR closed_on <= GREATEST((created_at AT TIME ZONE 'UTC')::DATE, (updated_at AT TIME ZONE 'UTC')::DATE)
                ),
                CONSTRAINT account_financial_accounts_version_valid CHECK (version > 0)
            )
            SQL);

        // Two live accounts sharing a label are indistinguishable in a picker,
        // an import mapping or a report legend. Archiving frees the label.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX account_financial_accounts_active_label_unique
                ON account_financial_accounts (workspace_id, normalized_label)
                WHERE archived_at IS NULL
            SQL);
        $this->addSql('CREATE INDEX account_financial_accounts_listing_index ON account_financial_accounts (workspace_id, archived_at, kind, normalized_label)');
        // PostgreSQL does not index a referencing foreign-key column on its own;
        // this keeps the ON DELETE RESTRICT check on reference_assets off a scan.
        $this->addSql('CREATE INDEX account_financial_accounts_asset_index ON account_financial_accounts (asset_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE account_financial_accounts');
    }
}
