<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds trigram text-search indexes and a change watermark index for the transaction search and filter surface.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE INDEX transaction_transactions_label_trgm_index ON transaction_transactions USING GIN (normalized_label gin_trgm_ops)');
        $this->addSql('CREATE INDEX transaction_transactions_counterparty_trgm_index ON transaction_transactions USING GIN (lower(counterparty) gin_trgm_ops)');
        // The keyset cursor embeds a (updated_at, id) watermark so a later page
        // can detect a background change; this index serves that check.
        $this->addSql('CREATE INDEX transaction_transactions_watermark_index ON transaction_transactions (workspace_id, updated_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX transaction_transactions_watermark_index');
        $this->addSql('DROP INDEX transaction_transactions_counterparty_trgm_index');
        $this->addSql('DROP INDEX transaction_transactions_label_trgm_index');
    }
}
