<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds dated stock goal metrics and an explicit direction for stock goals.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget_goals ADD direction VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE budget_goals DROP CONSTRAINT budget_goals_metric_valid');
        $this->addSql("ALTER TABLE budget_goals ADD CONSTRAINT budget_goals_metric_valid CHECK (metric IN ('NET_SAVINGS_TRANSFERS', 'BUDGET_SURPLUS', 'CATEGORY_SPENDING', 'AXIS_SPENDING', 'ACCOUNT_CONTRIBUTIONS', 'SAVINGS_RATE', 'NET_SAVINGS_RATE', 'NET_WORTH', 'ACCOUNT_BALANCE'))");
        $this->addSql("ALTER TABLE budget_goals ADD CONSTRAINT budget_goals_direction_valid CHECK (direction IS NULL OR (metric IN ('NET_WORTH', 'ACCOUNT_BALANCE') AND direction IN ('MINIMUM', 'CEILING')))");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne("SELECT count(*) FROM budget_goals WHERE metric IN ('NET_WORTH', 'ACCOUNT_BALANCE')") > 0, 'Stock goals must be removed before reverting their schema.');
        $this->addSql('ALTER TABLE budget_goals DROP CONSTRAINT budget_goals_direction_valid, DROP direction');
        $this->addSql('ALTER TABLE budget_goals DROP CONSTRAINT budget_goals_metric_valid');
        $this->addSql("ALTER TABLE budget_goals ADD CONSTRAINT budget_goals_metric_valid CHECK (metric IN ('NET_SAVINGS_TRANSFERS', 'BUDGET_SURPLUS', 'CATEGORY_SPENDING', 'AXIS_SPENDING', 'ACCOUNT_CONTRIBUTIONS', 'SAVINGS_RATE', 'NET_SAVINGS_RATE'))");
    }
}
