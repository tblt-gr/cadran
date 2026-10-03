<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the immutable portfolio operations of investment accounts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portfolio_instruments ADD CONSTRAINT portfolio_instruments_scoped_identity UNIQUE (id, workspace_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE portfolio_operations (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                account_id UUID NOT NULL,
                operation_type VARCHAR(24) NOT NULL,
                trade_date DATE NOT NULL,
                settlement_date DATE DEFAULT NULL,
                sequence INTEGER NOT NULL,
                instrument_id UUID DEFAULT NULL,
                counter_instrument_id UUID DEFAULT NULL,
                asset_code VARCHAR(12) NOT NULL,
                quantity_value NUMERIC(50, 24) DEFAULT NULL,
                quantity_scale SMALLINT DEFAULT NULL,
                unit_price_value NUMERIC(50, 24) DEFAULT NULL,
                unit_price_scale SMALLINT DEFAULT NULL,
                gross_amount_value NUMERIC(50, 24) DEFAULT NULL,
                gross_amount_scale SMALLINT DEFAULT NULL,
                fees_value NUMERIC(50, 24) NOT NULL,
                fees_scale SMALLINT NOT NULL,
                taxes_value NUMERIC(50, 24) NOT NULL,
                taxes_scale SMALLINT NOT NULL,
                cash_amount_value NUMERIC(50, 24) NOT NULL,
                cash_amount_scale SMALLINT NOT NULL,
                cost_basis_value NUMERIC(50, 24) DEFAULT NULL,
                cost_basis_scale SMALLINT DEFAULT NULL,
                counter_quantity_value NUMERIC(50, 24) DEFAULT NULL,
                counter_quantity_scale SMALLINT DEFAULT NULL,
                ratio_numerator_value NUMERIC(50, 24) DEFAULT NULL,
                ratio_numerator_scale SMALLINT DEFAULT NULL,
                ratio_denominator_value NUMERIC(50, 24) DEFAULT NULL,
                ratio_denominator_scale SMALLINT DEFAULT NULL,
                cost_allocation_ratio_value NUMERIC(50, 24) DEFAULT NULL,
                cost_allocation_ratio_scale SMALLINT DEFAULT NULL,
                rounding_residual_value NUMERIC(50, 24) DEFAULT NULL,
                rounding_residual_scale SMALLINT DEFAULT NULL,
                reference VARCHAR(64) DEFAULT NULL,
                note VARCHAR(200) DEFAULT NULL,
                linked_transaction_id UUID DEFAULT NULL,
                state VARCHAR(8) NOT NULL,
                voided_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                voided_by UUID DEFAULT NULL,
                void_reason VARCHAR(200) DEFAULT NULL,
                void_kind VARCHAR(8) DEFAULT NULL,
                corrects_operation_id UUID DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                created_by UUID NOT NULL,
                version INTEGER NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT portfolio_operations_workspace_fk FOREIGN KEY (workspace_id) REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT portfolio_operations_scoped_identity UNIQUE (id, workspace_id),
                CONSTRAINT portfolio_operations_account_fk FOREIGN KEY (account_id, workspace_id) REFERENCES account_financial_accounts (id, workspace_id) ON DELETE RESTRICT,
                CONSTRAINT portfolio_operations_instrument_fk FOREIGN KEY (instrument_id, workspace_id) REFERENCES portfolio_instruments (id, workspace_id) ON DELETE RESTRICT,
                CONSTRAINT portfolio_operations_counter_instrument_fk FOREIGN KEY (counter_instrument_id, workspace_id) REFERENCES portfolio_instruments (id, workspace_id) ON DELETE RESTRICT,
                CONSTRAINT portfolio_operations_asset_fk FOREIGN KEY (asset_code) REFERENCES reference_assets (code),
                CONSTRAINT portfolio_operations_corrects_fk FOREIGN KEY (corrects_operation_id, workspace_id) REFERENCES portfolio_operations (id, workspace_id),
                CONSTRAINT portfolio_operations_type_valid CHECK (operation_type IN ('DEPOSIT', 'WITHDRAWAL', 'BUY', 'SELL', 'DIVIDEND', 'INTEREST', 'FEE', 'TAX', 'SECURITY_TRANSFER_IN', 'SECURITY_TRANSFER_OUT', 'SPLIT', 'MERGER', 'SPIN_OFF')),
                CONSTRAINT portfolio_operations_sequence_positive CHECK (sequence > 0),
                CONSTRAINT portfolio_operations_settlement_valid CHECK (settlement_date IS NULL OR settlement_date >= trade_date),
                CONSTRAINT portfolio_operations_state_valid CHECK (state IN ('ACTIVE', 'VOIDED')),
                CONSTRAINT portfolio_operations_void_kind_valid CHECK (void_kind IS NULL OR void_kind IN ('VOIDED', 'REPLACED')),
                CONSTRAINT portfolio_operations_void_markers_valid CHECK ((state = 'VOIDED') = (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_kind IS NOT NULL) AND (state = 'VOIDED' OR void_reason IS NULL)),
                CONSTRAINT portfolio_operations_version_positive CHECK (version > 0),
                CONSTRAINT portfolio_operations_quantity_scale_valid CHECK ((quantity_value IS NULL) = (quantity_scale IS NULL) AND (quantity_scale IS NULL OR quantity_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_operations_unit_price_scale_valid CHECK ((unit_price_value IS NULL) = (unit_price_scale IS NULL) AND (unit_price_scale IS NULL OR unit_price_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_operations_gross_amount_scale_valid CHECK ((gross_amount_value IS NULL) = (gross_amount_scale IS NULL) AND (gross_amount_scale IS NULL OR gross_amount_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_operations_fees_scale_valid CHECK ((fees_value IS NULL) = (fees_scale IS NULL) AND (fees_scale IS NULL OR fees_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_operations_taxes_scale_valid CHECK ((taxes_value IS NULL) = (taxes_scale IS NULL) AND (taxes_scale IS NULL OR taxes_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_operations_cash_amount_scale_valid CHECK ((cash_amount_value IS NULL) = (cash_amount_scale IS NULL) AND (cash_amount_scale IS NULL OR cash_amount_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_operations_cost_basis_scale_valid CHECK ((cost_basis_value IS NULL) = (cost_basis_scale IS NULL) AND (cost_basis_scale IS NULL OR cost_basis_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_operations_counter_quantity_scale_valid CHECK ((counter_quantity_value IS NULL) = (counter_quantity_scale IS NULL) AND (counter_quantity_scale IS NULL OR counter_quantity_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_operations_ratio_numerator_scale_valid CHECK ((ratio_numerator_value IS NULL) = (ratio_numerator_scale IS NULL) AND (ratio_numerator_scale IS NULL OR ratio_numerator_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_operations_ratio_denominator_scale_valid CHECK ((ratio_denominator_value IS NULL) = (ratio_denominator_scale IS NULL) AND (ratio_denominator_scale IS NULL OR ratio_denominator_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_operations_cost_allocation_ratio_scale_valid CHECK ((cost_allocation_ratio_value IS NULL) = (cost_allocation_ratio_scale IS NULL) AND (cost_allocation_ratio_scale IS NULL OR cost_allocation_ratio_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_operations_rounding_residual_scale_valid CHECK ((rounding_residual_value IS NULL) = (rounding_residual_scale IS NULL) AND (rounding_residual_scale IS NULL OR rounding_residual_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_operations_text_valid CHECK ((reference IS NULL OR char_length(reference) BETWEEN 1 AND 64) AND (note IS NULL OR char_length(note) BETWEEN 1 AND 200))
            )
            SQL);
        $this->addSql("CREATE UNIQUE INDEX portfolio_operations_active_order_unique ON portfolio_operations (account_id, trade_date, sequence) WHERE state = 'ACTIVE'");
        $this->addSql("CREATE UNIQUE INDEX portfolio_operations_active_link_unique ON portfolio_operations (workspace_id, linked_transaction_id) WHERE state = 'ACTIVE' AND linked_transaction_id IS NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX portfolio_operations_corrects_unique ON portfolio_operations (corrects_operation_id) WHERE corrects_operation_id IS NOT NULL');
        $this->addSql('CREATE INDEX portfolio_operations_account_order_idx ON portfolio_operations (workspace_id, account_id, trade_date, sequence)');
        $this->addSql('CREATE INDEX portfolio_operations_instrument_idx ON portfolio_operations (workspace_id, instrument_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE portfolio_operations');
        $this->addSql('ALTER TABLE portfolio_instruments DROP CONSTRAINT portfolio_instruments_scoped_identity');
    }
}
