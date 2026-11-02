<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plans upcoming transactions within the month: booking marker on transactions and the per-workspace interface preferences.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_transactions
                ADD COLUMN auto_book BOOLEAN NOT NULL DEFAULT false,
                ADD CONSTRAINT transaction_transactions_auto_book_pending
                    CHECK (NOT auto_book OR state = 'PENDING')
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX transaction_transactions_auto_book_index
                ON transaction_transactions (workspace_id, booked_on) WHERE auto_book
            SQL);
        // Pending rows a recurrence generated are already booked by date: they carry the marker.
        $this->addSql(<<<'SQL'
            UPDATE transaction_transactions SET auto_book = true
                WHERE state = 'PENDING' AND source = 'RECURRENCE' AND review_reason IS NULL
            SQL);
        // One row per workspace, created on the first save; no row means the defaults apply. The
        // version is the optimistic lock that keeps two open tabs from silently overwriting each other.
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_interface_preferences (
                workspace_id UUID NOT NULL,
                include_upcoming BOOLEAN NOT NULL DEFAULT true,
                version INTEGER NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (workspace_id),
                CONSTRAINT identity_interface_preferences_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT identity_interface_preferences_version_valid CHECK (version > 0)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_interface_preferences');
        $this->addSql('DROP INDEX transaction_transactions_auto_book_index');
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_transactions
                DROP CONSTRAINT transaction_transactions_auto_book_pending,
                DROP COLUMN auto_book
            SQL);
    }
}
