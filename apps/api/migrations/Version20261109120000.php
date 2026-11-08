<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the goal colouring setting to the annual report preferences.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reporting_annual_report_preferences ADD goal_marks BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reporting_annual_report_preferences DROP goal_marks');
    }
}
