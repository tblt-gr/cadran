<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow one active current account as the primary transaction default per workspace.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_financial_accounts ADD is_primary BOOLEAN NOT NULL DEFAULT false');
        $this->addSql("ALTER TABLE account_financial_accounts ADD CONSTRAINT account_primary_eligible CHECK (NOT is_primary OR (kind = 'CURRENT' AND valuation_mode = 'TRANSACTIONS' AND closed_on IS NULL AND archived_at IS NULL))");
        $this->addSql('CREATE UNIQUE INDEX account_one_primary_per_workspace ON account_financial_accounts (workspace_id) WHERE is_primary = true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX account_one_primary_per_workspace');
        $this->addSql('ALTER TABLE account_financial_accounts DROP CONSTRAINT account_primary_eligible');
        $this->addSql('ALTER TABLE account_financial_accounts DROP COLUMN is_primary');
    }
}
