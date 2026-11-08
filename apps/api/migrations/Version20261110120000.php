<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the optional tolerance of a goal definition; every existing definition stays strict.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget_goal_versions ADD tolerance NUMERIC(50, 24) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE budget_goal_versions ADD tolerance_scale SMALLINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE budget_goal_versions ADD CONSTRAINT budget_goal_versions_tolerance_valid CHECK (tolerance >= 0 AND tolerance_scale BETWEEN 0 AND 8)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget_goal_versions DROP CONSTRAINT budget_goal_versions_tolerance_valid');
        $this->addSql('ALTER TABLE budget_goal_versions DROP tolerance_scale');
        $this->addSql('ALTER TABLE budget_goal_versions DROP tolerance');
    }
}
