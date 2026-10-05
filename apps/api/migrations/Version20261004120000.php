<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the euro-fund instrument type and the life-insurance contract, its fee terms and its euro-fund rates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portfolio_instruments ALTER COLUMN instrument_type TYPE VARCHAR(10)');
        $this->addSql('ALTER TABLE portfolio_instruments DROP CONSTRAINT portfolio_instruments_type_valid');
        $this->addSql("ALTER TABLE portfolio_instruments ADD CONSTRAINT portfolio_instruments_type_valid CHECK (instrument_type IN ('EQUITY', 'ETF', 'FUND', 'BOND', 'OTHER', 'EURO_FUND'))");
        $this->addSql('ALTER TABLE portfolio_instruments DROP CONSTRAINT portfolio_instruments_identifier_present');
        $this->addSql("ALTER TABLE portfolio_instruments ADD CONSTRAINT portfolio_instruments_identifier_present CHECK (isin IS NOT NULL OR ticker IS NOT NULL OR instrument_type = 'EURO_FUND')");
        $this->addSql("ALTER TABLE portfolio_instruments ADD CONSTRAINT portfolio_instruments_euro_fund_unidentified CHECK (instrument_type <> 'EURO_FUND' OR (isin IS NULL AND ticker IS NULL AND venue_mic IS NULL))");

        $this->addSql(<<<'SQL'
            CREATE TABLE portfolio_life_insurance_contracts (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                account_id UUID NOT NULL,
                insurer VARCHAR(80) NOT NULL,
                distributor VARCHAR(80) DEFAULT NULL,
                commercial_name VARCHAR(120) DEFAULT NULL,
                contract_number_suffix VARCHAR(4) DEFAULT NULL,
                subscribed_on DATE NOT NULL,
                tax_start_on DATE NOT NULL,
                management_mode VARCHAR(8) NOT NULL,
                status VARCHAR(6) NOT NULL,
                closed_on DATE DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                created_by UUID NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                version INTEGER NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT portfolio_life_insurance_contracts_workspace_fk FOREIGN KEY (workspace_id) REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT portfolio_life_insurance_contracts_scoped_identity UNIQUE (id, workspace_id),
                CONSTRAINT portfolio_life_insurance_contracts_account_fk FOREIGN KEY (account_id, workspace_id) REFERENCES account_financial_accounts (id, workspace_id) ON DELETE RESTRICT,
                CONSTRAINT portfolio_life_insurance_contracts_account_unique UNIQUE (account_id),
                CONSTRAINT portfolio_life_insurance_contracts_mode_valid CHECK (management_mode IN ('FREE', 'MANAGED', 'PILOTED')),
                CONSTRAINT portfolio_life_insurance_contracts_status_valid CHECK (status IN ('OPEN', 'CLOSED')),
                CONSTRAINT portfolio_life_insurance_contracts_closed_coherent CHECK ((status = 'CLOSED') = (closed_on IS NOT NULL)),
                CONSTRAINT portfolio_life_insurance_contracts_suffix_valid CHECK (contract_number_suffix IS NULL OR contract_number_suffix ~ '^[A-Z0-9]{4}$'),
                CONSTRAINT portfolio_life_insurance_contracts_insurer_valid CHECK (char_length(insurer) BETWEEN 1 AND 80),
                CONSTRAINT portfolio_life_insurance_contracts_version_positive CHECK (version > 0)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE portfolio_life_insurance_fee_terms (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                contract_id UUID NOT NULL,
                valid_from DATE NOT NULL,
                entry_fee_rate_value NUMERIC(50, 24) DEFAULT NULL,
                entry_fee_rate_scale SMALLINT DEFAULT NULL,
                euro_fund_management_fee_rate_value NUMERIC(50, 24) DEFAULT NULL,
                euro_fund_management_fee_rate_scale SMALLINT DEFAULT NULL,
                unit_linked_management_fee_rate_value NUMERIC(50, 24) DEFAULT NULL,
                unit_linked_management_fee_rate_scale SMALLINT DEFAULT NULL,
                switch_fee_rate_value NUMERIC(50, 24) DEFAULT NULL,
                switch_fee_rate_scale SMALLINT DEFAULT NULL,
                switch_fee_fixed_value NUMERIC(50, 24) DEFAULT NULL,
                switch_fee_fixed_scale SMALLINT DEFAULT NULL,
                source_note VARCHAR(200) DEFAULT NULL,
                recorded_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                recorded_by UUID NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT portfolio_life_insurance_fee_terms_workspace_fk FOREIGN KEY (workspace_id) REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT portfolio_life_insurance_fee_terms_scoped_identity UNIQUE (id, workspace_id),
                CONSTRAINT portfolio_life_insurance_fee_terms_contract_fk FOREIGN KEY (contract_id, workspace_id) REFERENCES portfolio_life_insurance_contracts (id, workspace_id) ON DELETE CASCADE,
                CONSTRAINT portfolio_life_insurance_fee_terms_version_unique UNIQUE (contract_id, valid_from),
                CONSTRAINT portfolio_life_insurance_fee_terms_entry_scale CHECK ((entry_fee_rate_value IS NULL) = (entry_fee_rate_scale IS NULL) AND (entry_fee_rate_scale IS NULL OR entry_fee_rate_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_life_insurance_fee_terms_euro_scale CHECK ((euro_fund_management_fee_rate_value IS NULL) = (euro_fund_management_fee_rate_scale IS NULL) AND (euro_fund_management_fee_rate_scale IS NULL OR euro_fund_management_fee_rate_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_life_insurance_fee_terms_unit_scale CHECK ((unit_linked_management_fee_rate_value IS NULL) = (unit_linked_management_fee_rate_scale IS NULL) AND (unit_linked_management_fee_rate_scale IS NULL OR unit_linked_management_fee_rate_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_life_insurance_fee_terms_switch_scale CHECK ((switch_fee_rate_value IS NULL) = (switch_fee_rate_scale IS NULL) AND (switch_fee_rate_scale IS NULL OR switch_fee_rate_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_life_insurance_fee_terms_fixed_scale CHECK ((switch_fee_fixed_value IS NULL) = (switch_fee_fixed_scale IS NULL) AND (switch_fee_fixed_scale IS NULL OR switch_fee_fixed_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_life_insurance_fee_terms_rates_bounded CHECK (
                    (entry_fee_rate_value IS NULL OR entry_fee_rate_value BETWEEN 0 AND 100)
                    AND (euro_fund_management_fee_rate_value IS NULL OR euro_fund_management_fee_rate_value BETWEEN 0 AND 100)
                    AND (unit_linked_management_fee_rate_value IS NULL OR unit_linked_management_fee_rate_value BETWEEN 0 AND 100)
                    AND (switch_fee_rate_value IS NULL OR switch_fee_rate_value BETWEEN 0 AND 100)
                    AND (switch_fee_fixed_value IS NULL OR switch_fee_fixed_value >= 0)
                ),
                CONSTRAINT portfolio_life_insurance_fee_terms_note_valid CHECK (source_note IS NULL OR char_length(source_note) BETWEEN 1 AND 200)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE portfolio_euro_fund_rates (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                contract_id UUID NOT NULL,
                instrument_id UUID NOT NULL,
                year SMALLINT NOT NULL,
                served_rate_value NUMERIC(50, 24) NOT NULL,
                served_rate_scale SMALLINT NOT NULL,
                bonus_rate_value NUMERIC(50, 24) DEFAULT NULL,
                bonus_rate_scale SMALLINT DEFAULT NULL,
                basis VARCHAR(24) NOT NULL,
                source_note VARCHAR(200) DEFAULT NULL,
                recorded_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                recorded_by UUID NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT portfolio_euro_fund_rates_workspace_fk FOREIGN KEY (workspace_id) REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT portfolio_euro_fund_rates_scoped_identity UNIQUE (id, workspace_id),
                CONSTRAINT portfolio_euro_fund_rates_contract_fk FOREIGN KEY (contract_id, workspace_id) REFERENCES portfolio_life_insurance_contracts (id, workspace_id) ON DELETE CASCADE,
                CONSTRAINT portfolio_euro_fund_rates_instrument_fk FOREIGN KEY (instrument_id, workspace_id) REFERENCES portfolio_instruments (id, workspace_id) ON DELETE RESTRICT,
                CONSTRAINT portfolio_euro_fund_rates_year_unique UNIQUE (contract_id, instrument_id, year),
                CONSTRAINT portfolio_euro_fund_rates_basis_valid CHECK (basis IN ('NET_OF_MANAGEMENT_FEES', 'GROSS_OF_MANAGEMENT_FEES', 'UNKNOWN')),
                CONSTRAINT portfolio_euro_fund_rates_served_scale CHECK (served_rate_scale BETWEEN 0 AND 24),
                CONSTRAINT portfolio_euro_fund_rates_bonus_scale CHECK ((bonus_rate_value IS NULL) = (bonus_rate_scale IS NULL) AND (bonus_rate_scale IS NULL OR bonus_rate_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_euro_fund_rates_bounded CHECK (served_rate_value BETWEEN -100 AND 100 AND (bonus_rate_value IS NULL OR bonus_rate_value BETWEEN 0 AND 100)),
                CONSTRAINT portfolio_euro_fund_rates_note_valid CHECK (source_note IS NULL OR char_length(source_note) BETWEEN 1 AND 200)
            )
            SQL);
        $this->addSql('CREATE INDEX portfolio_euro_fund_rates_contract_idx ON portfolio_euro_fund_rates (workspace_id, contract_id, year DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE portfolio_euro_fund_rates');
        $this->addSql('DROP TABLE portfolio_life_insurance_fee_terms');
        $this->addSql('DROP TABLE portfolio_life_insurance_contracts');
        $this->addSql('ALTER TABLE portfolio_instruments DROP CONSTRAINT portfolio_instruments_euro_fund_unidentified');
        $this->addSql('ALTER TABLE portfolio_instruments DROP CONSTRAINT portfolio_instruments_identifier_present');
        $this->addSql('ALTER TABLE portfolio_instruments ADD CONSTRAINT portfolio_instruments_identifier_present CHECK (isin IS NOT NULL OR ticker IS NOT NULL)');
        $this->addSql('ALTER TABLE portfolio_instruments DROP CONSTRAINT portfolio_instruments_type_valid');
        $this->addSql("ALTER TABLE portfolio_instruments ADD CONSTRAINT portfolio_instruments_type_valid CHECK (instrument_type IN ('EQUITY', 'ETF', 'FUND', 'BOND', 'OTHER'))");
        $this->addSql('ALTER TABLE portfolio_instruments ALTER COLUMN instrument_type TYPE VARCHAR(8)');
    }
}
