<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record observed euro-fund income, management fees and contributions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portfolio_operations DROP CONSTRAINT portfolio_operations_type_valid');
        $this->addSql("ALTER TABLE portfolio_operations ADD CONSTRAINT portfolio_operations_type_valid CHECK (operation_type IN ('DEPOSIT', 'WITHDRAWAL', 'BUY', 'SELL', 'DIVIDEND', 'INTEREST', 'FEE', 'TAX', 'SECURITY_TRANSFER_IN', 'SECURITY_TRANSFER_OUT', 'SPLIT', 'MERGER', 'SPIN_OFF', 'PREMIUM', 'PARTIAL_SURRENDER', 'FULL_SURRENDER', 'SWITCH', 'EURO_FUND_INTEREST', 'PROFIT_SHARING', 'MANAGEMENT_FEE', 'SOCIAL_CONTRIBUTION'))");
        $this->addSql("ALTER TABLE portfolio_operations ADD interest_basis VARCHAR(24) NULL CHECK (interest_basis IN ('NET_OF_MANAGEMENT_FEES','GROSS_OF_MANAGEMENT_FEES','UNKNOWN')), ADD rate_year SMALLINT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne("SELECT count(*) FROM portfolio_operations WHERE operation_type IN ('EURO_FUND_INTEREST', 'PROFIT_SHARING', 'MANAGEMENT_FEE', 'SOCIAL_CONTRIBUTION')") > 0, 'Observed insurance operations must be exported before rolling back this migration.');
        $this->addSql('ALTER TABLE portfolio_operations DROP interest_basis, DROP rate_year');
        $this->addSql('ALTER TABLE portfolio_operations DROP CONSTRAINT portfolio_operations_type_valid');
        $this->addSql("ALTER TABLE portfolio_operations ADD CONSTRAINT portfolio_operations_type_valid CHECK (operation_type IN ('DEPOSIT', 'WITHDRAWAL', 'BUY', 'SELL', 'DIVIDEND', 'INTEREST', 'FEE', 'TAX', 'SECURITY_TRANSFER_IN', 'SECURITY_TRANSFER_OUT', 'SPLIT', 'MERGER', 'SPIN_OFF', 'PREMIUM', 'PARTIAL_SURRENDER', 'FULL_SURRENDER', 'SWITCH'))");
    }
}
