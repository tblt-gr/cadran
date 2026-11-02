<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lets a recurrence create its own transactions: auto-create flag, inherited category, note and payment method, and the instalment-to-transaction link.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_recurrences
                ADD COLUMN auto_create BOOLEAN NOT NULL DEFAULT false,
                ADD COLUMN category_id UUID DEFAULT NULL,
                ADD COLUMN note VARCHAR(500) DEFAULT NULL,
                ADD COLUMN payment_method VARCHAR(16) DEFAULT NULL,
                ADD CONSTRAINT transaction_recurrences_category_fk
                    FOREIGN KEY (workspace_id, category_id)
                    REFERENCES category_categories (workspace_id, id) ON DELETE RESTRICT,
                ADD CONSTRAINT transaction_recurrences_payment_method_valid
                    CHECK (payment_method IS NULL OR payment_method IN
                        ('CARD', 'TRANSFER', 'DIRECT_DEBIT', 'CHECK', 'CASH', 'OTHER')),
                ADD CONSTRAINT transaction_recurrences_note_not_blank
                    CHECK (note IS NULL OR btrim(note) <> '')
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_recurrence_occurrences
                ADD COLUMN generated_transaction_id UUID DEFAULT NULL,
                ADD COLUMN generated_version INTEGER DEFAULT NULL,
                ADD CONSTRAINT transaction_recurrence_occurrences_generated_shape
                    CHECK ((generated_transaction_id IS NULL) = (generated_version IS NULL)),
                ADD CONSTRAINT transaction_recurrence_occurrences_generated_unique
                    UNIQUE (generated_transaction_id),
                ADD CONSTRAINT transaction_recurrence_occurrences_generated_fk
                    FOREIGN KEY (workspace_id, generated_transaction_id)
                    REFERENCES transaction_transactions (workspace_id, id) ON DELETE RESTRICT
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX transaction_recurrence_occurrences_ungenerated_index
                ON transaction_recurrence_occurrences (workspace_id, expected_on)
                WHERE status = 'EXPECTED' AND generated_transaction_id IS NULL
            SQL);
        // 'RECURRENCE' no longer fits the original VARCHAR(8) column.
        $this->addSql('ALTER TABLE transaction_transactions ALTER COLUMN source TYPE VARCHAR(16)');
        $this->addSql('ALTER TABLE transaction_transactions DROP CONSTRAINT transaction_transactions_source_valid');
        $this->addSql("ALTER TABLE transaction_transactions ADD CONSTRAINT transaction_transactions_source_valid CHECK (source IN ('MANUAL', 'IMPORT', 'PROVIDER', 'RECURRENCE'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction_transactions DROP CONSTRAINT transaction_transactions_source_valid');
        // Lossy by design: the old constraint has no RECURRENCE, so those rows fall back to MANUAL.
        $this->addSql("UPDATE transaction_transactions SET source = 'MANUAL' WHERE source = 'RECURRENCE'");
        $this->addSql("ALTER TABLE transaction_transactions ADD CONSTRAINT transaction_transactions_source_valid CHECK (source IN ('MANUAL', 'IMPORT', 'PROVIDER'))");
        $this->addSql('ALTER TABLE transaction_transactions ALTER COLUMN source TYPE VARCHAR(8)');
        $this->addSql('DROP INDEX transaction_recurrence_occurrences_ungenerated_index');
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_recurrence_occurrences
                DROP CONSTRAINT transaction_recurrence_occurrences_generated_fk,
                DROP CONSTRAINT transaction_recurrence_occurrences_generated_unique,
                DROP CONSTRAINT transaction_recurrence_occurrences_generated_shape,
                DROP COLUMN generated_version,
                DROP COLUMN generated_transaction_id
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_recurrences
                DROP CONSTRAINT transaction_recurrences_note_not_blank,
                DROP CONSTRAINT transaction_recurrences_payment_method_valid,
                DROP CONSTRAINT transaction_recurrences_category_fk,
                DROP COLUMN payment_method,
                DROP COLUMN note,
                DROP COLUMN category_id,
                DROP COLUMN auto_create
            SQL);
    }
}
