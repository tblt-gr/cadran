<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Defaults new report preferences to hidden goal marks without changing saved choices.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reporting_annual_report_preferences ALTER COLUMN goal_marks SET DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reporting_annual_report_preferences ALTER COLUMN goal_marks SET DEFAULT TRUE');
    }
}
