<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Holds an unmatched incoming movement under review, with its candidate pending rows and its resolution link.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction_transactions ADD COLUMN review_reason VARCHAR(32) DEFAULT NULL');
        $this->addSql("ALTER TABLE transaction_transactions ADD CONSTRAINT transaction_transactions_review_reason_valid CHECK (review_reason IS NULL OR (state = 'PENDING' AND review_reason IN ('AMBIGUOUS_MATCH', 'NO_MATCH')))");
        // The stable external identifier is what makes a second delivery of the
        // same provider movement recognisable instead of duplicated. It is
        // unique per account only where it exists: most rows carry none.
        $this->addSql('CREATE UNIQUE INDEX transaction_transactions_source_ref_unique ON transaction_transactions (workspace_id, account_id, source_ref) WHERE source_ref IS NOT NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_reconciliation_candidates (
                workspace_id UUID NOT NULL,
                transaction_id UUID NOT NULL,
                candidate_transaction_id UUID NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (workspace_id, transaction_id, candidate_transaction_id),
                CONSTRAINT transaction_reconciliation_candidates_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT transaction_reconciliation_candidates_reviewed_fk FOREIGN KEY (workspace_id, transaction_id)
                    REFERENCES transaction_transactions (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_reconciliation_candidates_candidate_fk FOREIGN KEY (workspace_id, candidate_transaction_id)
                    REFERENCES transaction_transactions (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_reconciliation_candidates_distinct CHECK (transaction_id <> candidate_transaction_id)
            )
        SQL);
        $this->addSql('CREATE INDEX transaction_reconciliation_candidates_candidate_index ON transaction_reconciliation_candidates (workspace_id, candidate_transaction_id)');
        // The reviewed row is voided rather than deleted, so the movement it
        // settled stays readable from it, the way a refund keeps its original.
        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_reconciliations (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                reviewed_transaction_id UUID NOT NULL,
                matched_transaction_id UUID NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT transaction_reconciliations_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT transaction_reconciliations_reviewed_fk FOREIGN KEY (workspace_id, reviewed_transaction_id)
                    REFERENCES transaction_transactions (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_reconciliations_matched_fk FOREIGN KEY (workspace_id, matched_transaction_id)
                    REFERENCES transaction_transactions (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_reconciliations_reviewed_unique UNIQUE (reviewed_transaction_id),
                CONSTRAINT transaction_reconciliations_distinct CHECK (reviewed_transaction_id <> matched_transaction_id)
            )
        SQL);
        $this->addSql('CREATE INDEX transaction_reconciliations_matched_index ON transaction_reconciliations (workspace_id, matched_transaction_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE transaction_reconciliations');
        $this->addSql('DROP TABLE transaction_reconciliation_candidates');
        $this->addSql('DROP INDEX transaction_transactions_source_ref_unique');
        $this->addSql('ALTER TABLE transaction_transactions DROP CONSTRAINT transaction_transactions_review_reason_valid');
        $this->addSql('ALTER TABLE transaction_transactions DROP COLUMN review_reason');
    }
}
