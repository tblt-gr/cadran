<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the portfolio valuations and the position snapshots frozen with them.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE portfolio_valuations (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                account_id UUID NOT NULL,
                valued_on DATE NOT NULL,
                asset_code VARCHAR(12) NOT NULL,
                cash_value NUMERIC(50, 24) NOT NULL,
                cash_scale SMALLINT NOT NULL,
                derived_cash_value NUMERIC(50, 24) NOT NULL,
                derived_cash_scale SMALLINT NOT NULL,
                securities_value_value NUMERIC(50, 24) DEFAULT NULL,
                securities_value_scale SMALLINT DEFAULT NULL,
                total_value_value NUMERIC(50, 24) DEFAULT NULL,
                total_value_scale SMALLINT DEFAULT NULL,
                observed_total_value NUMERIC(50, 24) DEFAULT NULL,
                observed_total_scale SMALLINT DEFAULT NULL,
                cash_source VARCHAR(8) NOT NULL,
                detail VARCHAR(9) NOT NULL,
                completeness VARCHAR(8) NOT NULL,
                source VARCHAR(8) NOT NULL,
                note VARCHAR(200) DEFAULT NULL,
                supersedes_id UUID DEFAULT NULL,
                superseded_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                withdrawn BOOLEAN DEFAULT FALSE NOT NULL,
                recorded_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                recorded_by UUID NOT NULL,
                version INTEGER NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT portfolio_valuations_workspace_fk FOREIGN KEY (workspace_id) REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT portfolio_valuations_scoped_identity UNIQUE (id, workspace_id),
                CONSTRAINT portfolio_valuations_account_fk FOREIGN KEY (account_id, workspace_id) REFERENCES account_financial_accounts (id, workspace_id) ON DELETE RESTRICT,
                CONSTRAINT portfolio_valuations_asset_fk FOREIGN KEY (asset_code) REFERENCES reference_assets (code),
                CONSTRAINT portfolio_valuations_supersedes_fk FOREIGN KEY (supersedes_id, workspace_id) REFERENCES portfolio_valuations (id, workspace_id),
                CONSTRAINT portfolio_valuations_cash_source_valid CHECK (cash_source IN ('DERIVED', 'OBSERVED')),
                CONSTRAINT portfolio_valuations_detail_valid CHECK (detail IN ('TOTAL', 'POSITIONS')),
                CONSTRAINT portfolio_valuations_completeness_valid CHECK (completeness IN ('COMPLETE', 'PARTIAL')),
                CONSTRAINT portfolio_valuations_source_valid CHECK (source IN ('MANUAL', 'IMPORT')),
                CONSTRAINT portfolio_valuations_complete_figures CHECK (completeness = 'PARTIAL' OR (total_value_value IS NOT NULL AND securities_value_value IS NOT NULL)),
                CONSTRAINT portfolio_valuations_withdrawn_superseded CHECK (NOT withdrawn OR superseded_at IS NOT NULL),
                CONSTRAINT portfolio_valuations_version_positive CHECK (version > 0),
                CONSTRAINT portfolio_valuations_cash_scale_valid CHECK (cash_scale BETWEEN 0 AND 24),
                CONSTRAINT portfolio_valuations_derived_cash_scale_valid CHECK (derived_cash_scale BETWEEN 0 AND 24),
                CONSTRAINT portfolio_valuations_securities_value_scale_valid CHECK ((securities_value_value IS NULL) = (securities_value_scale IS NULL) AND (securities_value_scale IS NULL OR securities_value_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_valuations_total_value_scale_valid CHECK ((total_value_value IS NULL) = (total_value_scale IS NULL) AND (total_value_scale IS NULL OR total_value_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_valuations_observed_total_scale_valid CHECK ((observed_total_value IS NULL) = (observed_total_scale IS NULL) AND (observed_total_scale IS NULL OR observed_total_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_valuations_note_valid CHECK (note IS NULL OR char_length(note) BETWEEN 1 AND 200)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX portfolio_valuations_active_day_unique ON portfolio_valuations (account_id, valued_on) WHERE superseded_at IS NULL');
        $this->addSql('CREATE UNIQUE INDEX portfolio_valuations_supersedes_unique ON portfolio_valuations (supersedes_id) WHERE supersedes_id IS NOT NULL');
        $this->addSql('CREATE INDEX portfolio_valuations_account_day_idx ON portfolio_valuations (workspace_id, account_id, valued_on DESC)');
        $this->addSql(<<<'SQL'
            CREATE TABLE portfolio_position_snapshots (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                valuation_id UUID NOT NULL,
                instrument_id UUID NOT NULL,
                quantity_value NUMERIC(50, 24) NOT NULL,
                quantity_scale SMALLINT NOT NULL,
                unit_price_value NUMERIC(50, 24) DEFAULT NULL,
                unit_price_scale SMALLINT DEFAULT NULL,
                market_value_value NUMERIC(50, 24) DEFAULT NULL,
                market_value_scale SMALLINT DEFAULT NULL,
                average_cost_basis_value NUMERIC(50, 24) DEFAULT NULL,
                average_cost_basis_scale SMALLINT DEFAULT NULL,
                price_date DATE DEFAULT NULL,
                price_source VARCHAR(8) DEFAULT NULL,
                price_quality VARCHAR(12) NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT portfolio_position_snapshots_workspace_fk FOREIGN KEY (workspace_id) REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT portfolio_position_snapshots_valuation_fk FOREIGN KEY (valuation_id, workspace_id) REFERENCES portfolio_valuations (id, workspace_id) ON DELETE RESTRICT,
                CONSTRAINT portfolio_position_snapshots_instrument_fk FOREIGN KEY (instrument_id, workspace_id) REFERENCES portfolio_instruments (id, workspace_id) ON DELETE RESTRICT,
                CONSTRAINT portfolio_position_snapshots_line_unique UNIQUE (valuation_id, instrument_id),
                CONSTRAINT portfolio_position_snapshots_source_valid CHECK (price_source IS NULL OR price_source IN ('MANUAL', 'IMPORT')),
                CONSTRAINT portfolio_position_snapshots_quality_valid CHECK (price_quality IN ('CURRENT', 'STALE', 'MISSING', 'NOT_DETAILED')),
                CONSTRAINT portfolio_position_snapshots_price_positive CHECK (unit_price_value IS NULL OR unit_price_value > 0),
                CONSTRAINT portfolio_position_snapshots_quantity_scale_valid CHECK (quantity_scale BETWEEN 0 AND 24),
                CONSTRAINT portfolio_position_snapshots_unit_price_scale_valid CHECK ((unit_price_value IS NULL) = (unit_price_scale IS NULL) AND (unit_price_scale IS NULL OR unit_price_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_position_snapshots_market_value_scale_valid CHECK ((market_value_value IS NULL) = (market_value_scale IS NULL) AND (market_value_scale IS NULL OR market_value_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_position_snapshots_average_cost_basis_scale_valid CHECK ((average_cost_basis_value IS NULL) = (average_cost_basis_scale IS NULL) AND (average_cost_basis_scale IS NULL OR average_cost_basis_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_position_snapshots_price_coherent CHECK ((unit_price_value IS NULL) = (price_date IS NULL) AND (unit_price_value IS NULL) = (price_source IS NULL))
            )
            SQL);
        $this->addSql('CREATE INDEX portfolio_position_snapshots_instrument_idx ON portfolio_position_snapshots (workspace_id, instrument_id, price_date DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE portfolio_position_snapshots');
        $this->addSql('DROP TABLE portfolio_valuations');
    }
}
