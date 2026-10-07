<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow atomic life-insurance support switches.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portfolio_operations DROP CONSTRAINT portfolio_operations_type_valid');
        $this->addSql("ALTER TABLE portfolio_operations ADD CONSTRAINT portfolio_operations_type_valid CHECK (operation_type IN ('DEPOSIT', 'WITHDRAWAL', 'BUY', 'SELL', 'DIVIDEND', 'INTEREST', 'FEE', 'TAX', 'SECURITY_TRANSFER_IN', 'SECURITY_TRANSFER_OUT', 'SPLIT', 'MERGER', 'SPIN_OFF', 'PREMIUM', 'PARTIAL_SURRENDER', 'FULL_SURRENDER', 'SWITCH'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portfolio_operations DROP CONSTRAINT portfolio_operations_type_valid');
        $this->addSql("ALTER TABLE portfolio_operations ADD CONSTRAINT portfolio_operations_type_valid CHECK (operation_type IN ('DEPOSIT', 'WITHDRAWAL', 'BUY', 'SELL', 'DIVIDEND', 'INTEREST', 'FEE', 'TAX', 'SECURITY_TRANSFER_IN', 'SECURITY_TRANSFER_OUT', 'SPLIT', 'MERGER', 'SPIN_OFF', 'PREMIUM', 'PARTIAL_SURRENDER', 'FULL_SURRENDER'))");
    }
}
