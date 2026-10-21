<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist the order of accounts inside each primary group, with one concurrency version per group.';
    }

    public function up(Schema $schema): void
    {
        // The ungrouped section has no group row; the nil UUID stands in for it.
        $this->addSql(<<<'SQL'
            CREATE TABLE account_overview_orders (
                workspace_id UUID NOT NULL,
                group_key UUID NOT NULL,
                version INTEGER NOT NULL CHECK (version >= 0),
                PRIMARY KEY (workspace_id, group_key),
                CONSTRAINT account_overview_orders_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE account_overview_positions (
                workspace_id UUID NOT NULL,
                account_id UUID NOT NULL,
                group_key UUID NOT NULL,
                position INTEGER NOT NULL CHECK (position >= 1),
                PRIMARY KEY (workspace_id, account_id),
                CONSTRAINT account_overview_positions_account_fk FOREIGN KEY (account_id, workspace_id)
                    REFERENCES account_financial_accounts (id, workspace_id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX account_overview_positions_group_index ON account_overview_positions (workspace_id, group_key, position)');

        // Existing accounts keep the order they were already shown in: label, then identifier.
        $this->addSql(<<<'SQL'
            INSERT INTO account_overview_positions (workspace_id, account_id, group_key, position)
            SELECT workspace_id, id, COALESCE(primary_group_id, '00000000-0000-0000-0000-000000000000'::UUID),
                row_number() OVER (
                    PARTITION BY workspace_id, COALESCE(primary_group_id, '00000000-0000-0000-0000-000000000000'::UUID)
                    ORDER BY normalized_label, id
                )
            FROM account_financial_accounts
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE account_overview_positions');
        $this->addSql('DROP TABLE account_overview_orders');
    }
}
