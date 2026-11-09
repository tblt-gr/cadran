<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds total income and total spending goal metrics.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget_goals DROP CONSTRAINT budget_goals_metric_valid');
        $this->addSql("ALTER TABLE budget_goals ADD CONSTRAINT budget_goals_metric_valid CHECK (metric IN ('NET_SAVINGS_TRANSFERS', 'BUDGET_SURPLUS', 'CATEGORY_SPENDING', 'AXIS_SPENDING', 'ACCOUNT_CONTRIBUTIONS', 'SAVINGS_RATE', 'NET_SAVINGS_RATE', 'NET_WORTH', 'ACCOUNT_BALANCE', 'TOTAL_INCOME', 'TOTAL_SPENDING'))");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne("SELECT count(*) FROM budget_goals WHERE metric IN ('TOTAL_INCOME', 'TOTAL_SPENDING')") > 0, 'Total income and spending goals must be removed before reverting their schema.');
        $this->addSql('ALTER TABLE budget_goals DROP CONSTRAINT budget_goals_metric_valid');
        $this->addSql("ALTER TABLE budget_goals ADD CONSTRAINT budget_goals_metric_valid CHECK (metric IN ('NET_SAVINGS_TRANSFERS', 'BUDGET_SURPLUS', 'CATEGORY_SPENDING', 'AXIS_SPENDING', 'ACCOUNT_CONTRIBUTIONS', 'SAVINGS_RATE', 'NET_SAVINGS_RATE', 'NET_WORTH', 'ACCOUNT_BALANCE'))");
    }
}
