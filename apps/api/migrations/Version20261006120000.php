<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds atomic support legs and observed life-insurance withholdings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portfolio_operations DROP CONSTRAINT portfolio_operations_type_valid');
        $this->addSql("ALTER TABLE portfolio_operations ADD CONSTRAINT portfolio_operations_type_valid CHECK (operation_type IN ('DEPOSIT', 'WITHDRAWAL', 'BUY', 'SELL', 'DIVIDEND', 'INTEREST', 'FEE', 'TAX', 'SECURITY_TRANSFER_IN', 'SECURITY_TRANSFER_OUT', 'SPLIT', 'MERGER', 'SPIN_OFF', 'PREMIUM', 'PARTIAL_SURRENDER', 'FULL_SURRENDER'))");
        $this->addSql('ALTER TABLE portfolio_operations ADD withheld_income_tax_value NUMERIC(50,24) DEFAULT NULL, ADD withheld_income_tax_scale SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE portfolio_operations ADD CONSTRAINT portfolio_operations_withheld_income_tax_valid CHECK ((withheld_income_tax_value IS NULL) = (withheld_income_tax_scale IS NULL) AND (withheld_income_tax_scale IS NULL OR withheld_income_tax_scale BETWEEN 0 AND 24) AND (withheld_income_tax_value IS NULL OR withheld_income_tax_value >= 0))');
        $this->addSql('ALTER TABLE portfolio_operations ADD withheld_social_contributions_value NUMERIC(50,24) DEFAULT NULL, ADD withheld_social_contributions_scale SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE portfolio_operations ADD CONSTRAINT portfolio_operations_withheld_social_contributions_valid CHECK ((withheld_social_contributions_value IS NULL) = (withheld_social_contributions_scale IS NULL) AND (withheld_social_contributions_scale IS NULL OR withheld_social_contributions_scale BETWEEN 0 AND 24) AND (withheld_social_contributions_value IS NULL OR withheld_social_contributions_value >= 0))');
        $this->addSql(<<<'SQL'
            CREATE TABLE portfolio_operation_legs (
                id UUID PRIMARY KEY,
                workspace_id UUID NOT NULL,
                operation_id UUID NOT NULL,
                leg_index SMALLINT NOT NULL CHECK (leg_index BETWEEN 1 AND 20),
                direction VARCHAR(3) NOT NULL CHECK (direction IN ('IN', 'OUT')),
                instrument_id UUID NOT NULL,
                quantity_value NUMERIC(50,24) NOT NULL CHECK (quantity_value > 0),
                quantity_scale SMALLINT NOT NULL CHECK (quantity_scale BETWEEN 0 AND 24),
                unit_price_value NUMERIC(50,24) NOT NULL CHECK (unit_price_value > 0),
                unit_price_scale SMALLINT NOT NULL CHECK (unit_price_scale BETWEEN 0 AND 24),
                amount_value NUMERIC(50,24) NOT NULL CHECK (amount_value > 0),
                amount_scale SMALLINT NOT NULL CHECK (amount_scale BETWEEN 0 AND 24),
                rounding_residual_value NUMERIC(50,24) DEFAULT NULL,
                rounding_residual_scale SMALLINT DEFAULT NULL,
                CONSTRAINT portfolio_operation_legs_residual_valid CHECK ((rounding_residual_value IS NULL) = (rounding_residual_scale IS NULL) AND (rounding_residual_scale IS NULL OR rounding_residual_scale BETWEEN 0 AND 24)),
                CONSTRAINT portfolio_operation_legs_operation_fk FOREIGN KEY (operation_id, workspace_id) REFERENCES portfolio_operations (id, workspace_id) ON DELETE CASCADE,
                CONSTRAINT portfolio_operation_legs_instrument_fk FOREIGN KEY (instrument_id, workspace_id) REFERENCES portfolio_instruments (id, workspace_id),
                UNIQUE (operation_id, leg_index),
                UNIQUE (operation_id, direction, instrument_id)
            )
            SQL);
        $this->addSql('CREATE INDEX portfolio_operation_legs_workspace_operation ON portfolio_operation_legs (workspace_id, operation_id)');
        $this->addSql('CREATE INDEX portfolio_operation_legs_workspace_instrument ON portfolio_operation_legs (workspace_id, instrument_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE portfolio_operation_legs');
        $this->addSql('ALTER TABLE portfolio_operations DROP withheld_income_tax_value, DROP withheld_income_tax_scale, DROP withheld_social_contributions_value, DROP withheld_social_contributions_scale');
        $this->addSql('ALTER TABLE portfolio_operations DROP CONSTRAINT portfolio_operations_type_valid');
        $this->addSql("ALTER TABLE portfolio_operations ADD CONSTRAINT portfolio_operations_type_valid CHECK (operation_type IN ('DEPOSIT', 'WITHDRAWAL', 'BUY', 'SELL', 'DIVIDEND', 'INTEREST', 'FEE', 'TAX', 'SECURITY_TRANSFER_IN', 'SECURITY_TRANSFER_OUT', 'SPLIT', 'MERGER', 'SPIN_OFF'))");
    }
}
