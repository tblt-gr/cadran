<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lifts a transaction split to a bounded, deterministic multi-category allocation with analytic axes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction_splits ADD COLUMN analytic_axes JSONB NOT NULL DEFAULT \'[]\'::JSONB');
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_splits
                ADD CONSTRAINT transaction_splits_axes_valid CHECK (
                    jsonb_typeof(analytic_axes) = 'array'
                    AND analytic_axes <@ '["DISCRETIONARY", "ESSENTIAL", "FIXED", "PERSONAL",
                                           "PROFESSIONAL", "VARIABLE"]'::JSONB
                )
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_splits
                ADD CONSTRAINT transaction_splits_category_once UNIQUE (transaction_id, category_id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction_splits DROP CONSTRAINT transaction_splits_category_once');
        $this->addSql('ALTER TABLE transaction_splits DROP CONSTRAINT transaction_splits_axes_valid');
        $this->addSql('ALTER TABLE transaction_splits DROP COLUMN analytic_axes');
    }
}
