<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Records the takeover of the positions already held by a securities account, one per account.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE portfolio_takeovers (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                account_id UUID NOT NULL,
                taken_over_on DATE NOT NULL,
                valuation_id UUID NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT portfolio_takeovers_workspace_fk FOREIGN KEY (workspace_id) REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT portfolio_takeovers_account_fk FOREIGN KEY (account_id, workspace_id) REFERENCES account_financial_accounts (id, workspace_id) ON DELETE RESTRICT,
                CONSTRAINT portfolio_takeovers_valuation_fk FOREIGN KEY (valuation_id, workspace_id) REFERENCES portfolio_valuations (id, workspace_id) ON DELETE RESTRICT,
                CONSTRAINT portfolio_takeovers_account_unique UNIQUE (workspace_id, account_id)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE portfolio_takeovers');
    }
}
