<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indexes the absolute amount of transactions so unsigned amount bounds and amount searches stay bounded.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX transaction_transactions_abs_amount_index ON transaction_transactions (workspace_id, abs(amount_value))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX transaction_transactions_abs_amount_index');
    }
}
