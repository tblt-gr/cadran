<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the workspace investment instrument reference.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE portfolio_instruments (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                name VARCHAR(120) NOT NULL,
                normalized_name VARCHAR(120) NOT NULL,
                isin CHAR(12) DEFAULT NULL,
                ticker VARCHAR(12) DEFAULT NULL,
                venue_mic CHAR(4) DEFAULT NULL,
                instrument_type VARCHAR(8) NOT NULL,
                asset_class VARCHAR(16) DEFAULT NULL,
                region VARCHAR(16) DEFAULT NULL,
                currency_code VARCHAR(12) NOT NULL,
                quantity_precision SMALLINT NOT NULL,
                price_precision SMALLINT NOT NULL,
                source VARCHAR(8) NOT NULL,
                reviewed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                reviewed_by UUID DEFAULT NULL,
                status VARCHAR(8) NOT NULL,
                used_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                created_by UUID NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                version INTEGER NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT portfolio_instruments_workspace_fk FOREIGN KEY (workspace_id) REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT portfolio_instruments_currency_fk FOREIGN KEY (currency_code) REFERENCES reference_assets (code),
                CONSTRAINT portfolio_instruments_identifier_present CHECK (isin IS NOT NULL OR ticker IS NOT NULL),
                CONSTRAINT portfolio_instruments_isin_format CHECK (isin IS NULL OR isin ~ '^[A-Z]{2}[A-Z0-9]{9}[0-9]$'),
                CONSTRAINT portfolio_instruments_ticker_format CHECK (ticker IS NULL OR ticker ~ '^[A-Z0-9][A-Z0-9.\-]{0,11}$'),
                CONSTRAINT portfolio_instruments_venue_format CHECK (venue_mic IS NULL OR venue_mic ~ '^[A-Z0-9]{4}$'),
                CONSTRAINT portfolio_instruments_type_valid CHECK (instrument_type IN ('EQUITY', 'ETF', 'FUND', 'BOND', 'OTHER')),
                CONSTRAINT portfolio_instruments_class_valid CHECK (asset_class IS NULL OR asset_class IN ('EQUITY', 'BOND', 'MONEY_MARKET', 'REAL_ESTATE', 'COMMODITY', 'MIXED', 'OTHER')),
                CONSTRAINT portfolio_instruments_region_valid CHECK (region IS NULL OR region IN ('FRANCE', 'EUROPE', 'NORTH_AMERICA', 'ASIA_PACIFIC', 'EMERGING', 'WORLD', 'OTHER')),
                CONSTRAINT portfolio_instruments_precision_valid CHECK (quantity_precision BETWEEN 0 AND 12 AND price_precision BETWEEN 0 AND 12),
                CONSTRAINT portfolio_instruments_source_valid CHECK (source IN ('MANUAL', 'IMPORT')),
                CONSTRAINT portfolio_instruments_review_valid CHECK ((reviewed_at IS NULL) = (reviewed_by IS NULL)),
                CONSTRAINT portfolio_instruments_status_valid CHECK (status IN ('ACTIVE', 'INACTIVE')),
                CONSTRAINT portfolio_instruments_version_positive CHECK (version > 0)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX portfolio_instruments_workspace_isin_unique ON portfolio_instruments (workspace_id, isin) WHERE isin IS NOT NULL');
        $this->addSql('CREATE INDEX portfolio_instruments_workspace_name_idx ON portfolio_instruments (workspace_id, normalized_name)');
        $this->addSql('CREATE INDEX portfolio_instruments_workspace_ticker_idx ON portfolio_instruments (workspace_id, upper(ticker)) WHERE ticker IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE portfolio_instruments');
    }
}
