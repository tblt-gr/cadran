<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add independent nullable monthly recap account selection without changing saved flow preferences.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reporting_recap_preferences ADD visible_account_ids JSONB DEFAULT NULL');
        $this->addSql("ALTER TABLE reporting_recap_preferences ADD CONSTRAINT reporting_recap_preferences_accounts_valid CHECK (visible_account_ids IS NULL OR (jsonb_typeof(visible_account_ids) = 'array' AND jsonb_array_length(visible_account_ids) <= 6))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reporting_recap_preferences DROP visible_account_ids');
    }
}
