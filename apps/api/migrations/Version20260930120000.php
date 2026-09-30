<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marks append-only goal versions as retroactive corrections.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget_goal_versions ADD retroactive BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE budget_goal_versions ADD CONSTRAINT budget_goal_versions_correction_valid CHECK ((retroactive = FALSE AND reason IS NULL) OR (retroactive = TRUE AND btrim(reason) = reason AND length(reason) BETWEEN 3 AND 200))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget_goal_versions DROP CONSTRAINT budget_goal_versions_correction_valid');
        $this->addSql('ALTER TABLE budget_goal_versions DROP retroactive');
    }
}
