<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lets the emergency-fund target be a fixed amount or a share of the annual income.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE budget_emergency_fund_policies
                ADD COLUMN target_mode VARCHAR(16) NOT NULL DEFAULT 'MONTHS',
                ADD COLUMN target_amount NUMERIC(50, 24) NULL,
                ADD COLUMN target_asset_code VARCHAR(12) NULL,
                ADD COLUMN target_ratio NUMERIC(50, 24) NULL,
                ADD CONSTRAINT budget_emergency_fund_policies_target_mode_valid
                    CHECK (target_mode IN ('MONTHS', 'AMOUNT', 'INCOME_RATIO')),
                ADD CONSTRAINT budget_emergency_fund_policies_target_shape_valid CHECK (
                    (target_mode = 'MONTHS' AND target_amount IS NULL AND target_asset_code IS NULL AND target_ratio IS NULL)
                    OR (target_mode = 'AMOUNT' AND target_amount > 0 AND target_asset_code IS NOT NULL AND target_ratio IS NULL)
                    OR (target_mode = 'INCOME_RATIO' AND target_ratio > 0 AND target_ratio <= 1 AND target_amount IS NULL AND target_asset_code IS NULL)
                )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE budget_emergency_fund_policies
                DROP CONSTRAINT budget_emergency_fund_policies_target_shape_valid,
                DROP CONSTRAINT budget_emergency_fund_policies_target_mode_valid,
                DROP COLUMN target_ratio,
                DROP COLUMN target_asset_code,
                DROP COLUMN target_amount,
                DROP COLUMN target_mode
            SQL);
    }
}
