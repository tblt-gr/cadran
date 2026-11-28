<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indexes the lowercase user label so the transaction text search stays bounded.';
    }

    public function up(Schema $schema): void
    {
        // Not partial: a NULL adds no entry to the GIN index. pg_trgm exists since Version20260920120000.
        $this->addSql('CREATE INDEX transaction_transactions_user_label_trgm_index ON transaction_transactions USING GIN (lower(user_label) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX transaction_transactions_user_label_trgm_index');
    }
}
