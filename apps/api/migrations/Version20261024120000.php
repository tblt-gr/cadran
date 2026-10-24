<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an optional user display label next to the immutable source label of a transaction.';
    }

    public function up(Schema $schema): void
    {
        // raw_label stays the source label and is not rewritten: every existing row keeps it
        // untouched and starts with no user label, so its displayed label is unchanged.
        $this->addSql('ALTER TABLE transaction_transactions ADD COLUMN user_label VARCHAR(140) NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_transactions
                ADD CONSTRAINT transaction_transactions_user_label_valid
                CHECK (user_label IS NULL OR (btrim(user_label) = user_label AND user_label <> '' AND char_length(user_label) <= 140))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction_transactions DROP CONSTRAINT transaction_transactions_user_label_valid');
        $this->addSql('ALTER TABLE transaction_transactions DROP COLUMN user_label');
    }
}
