<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Links refund movements to their original expense without rewriting either transaction.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_refunds (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                refund_transaction_id UUID NOT NULL,
                original_transaction_id UUID NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT transaction_refunds_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT transaction_refunds_refund_fk FOREIGN KEY (workspace_id, refund_transaction_id)
                    REFERENCES transaction_transactions (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_refunds_original_fk FOREIGN KEY (workspace_id, original_transaction_id)
                    REFERENCES transaction_transactions (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_refunds_refund_unique UNIQUE (refund_transaction_id),
                CONSTRAINT transaction_refunds_distinct CHECK (refund_transaction_id <> original_transaction_id)
            )
        SQL);
        $this->addSql('CREATE INDEX transaction_refunds_original_index ON transaction_refunds (workspace_id, original_transaction_id)');
        $this->addSql('ALTER TABLE transaction_transactions DROP CONSTRAINT transaction_transactions_sign_matches_nature');
        $this->addSql("ALTER TABLE transaction_transactions ADD CONSTRAINT transaction_transactions_sign_matches_nature CHECK ((nature IN ('INCOME', 'REFUND') AND amount_value > 0) OR (nature IN ('EXPENSE', 'FEE') AND amount_value < 0) OR nature IN ('TRANSFER', 'ADJUSTMENT'))");
        // PostgreSQL cannot change this table while any deferred trigger event is queued.
        // Keeping constraints immediate also checks the ordering backfill as it is written.
        $this->addSql('SET CONSTRAINTS ALL IMMEDIATE');
        $this->addSql('ALTER TABLE transaction_splits ADD COLUMN position SMALLINT NOT NULL DEFAULT 0');
        $this->addSql('WITH ordered AS (SELECT id, row_number() OVER (PARTITION BY transaction_id ORDER BY id) - 1 AS position FROM transaction_splits) UPDATE transaction_splits s SET position = ordered.position FROM ordered WHERE ordered.id = s.id');
        $this->addSql('ALTER TABLE transaction_splits ADD CONSTRAINT transaction_splits_position_valid CHECK (position BETWEEN 0 AND 19)');
        $this->addSql('ALTER TABLE transaction_splits ADD CONSTRAINT transaction_splits_position_unique UNIQUE (transaction_id, position)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction_splits DROP CONSTRAINT transaction_splits_position_unique');
        $this->addSql('ALTER TABLE transaction_splits DROP CONSTRAINT transaction_splits_position_valid');
        $this->addSql('ALTER TABLE transaction_splits DROP COLUMN position');
        $this->addSql('ALTER TABLE transaction_transactions DROP CONSTRAINT transaction_transactions_sign_matches_nature');
        $this->addSql("ALTER TABLE transaction_transactions ADD CONSTRAINT transaction_transactions_sign_matches_nature CHECK ((nature = 'INCOME' AND amount_value > 0) OR (nature IN ('EXPENSE', 'FEE') AND amount_value < 0) OR nature IN ('TRANSFER', 'REFUND', 'ADJUSTMENT'))");
        $this->addSql('DROP TABLE transaction_refunds');
    }
}
