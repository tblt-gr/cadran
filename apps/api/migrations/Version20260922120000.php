<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the monthly period closures that refuse ordinary writes on a verified month.';
    }

    public function up(Schema $schema): void
    {
        // One row per closing. Reopening stamps the row instead of deleting it,
        // so a month closed twice keeps both rows and only the active one, the
        // one never reopened, refuses writes.
        $this->addSql(<<<'SQL'
            CREATE TABLE account_period_closures (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                year SMALLINT NOT NULL,
                month SMALLINT NOT NULL,
                closed_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                closed_by UUID NOT NULL,
                reopened_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                reopen_reason VARCHAR(200) DEFAULT NULL,
                version INTEGER NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT account_period_closures_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT account_period_closures_author_fk FOREIGN KEY (closed_by)
                    REFERENCES identity_users (id) ON DELETE RESTRICT,
                CONSTRAINT account_period_closures_year_valid CHECK (year BETWEEN 1900 AND 2999),
                CONSTRAINT account_period_closures_month_valid CHECK (month BETWEEN 1 AND 12),
                CONSTRAINT account_period_closures_version_valid CHECK (version > 0),
                CONSTRAINT account_period_closures_reopening_complete
                    CHECK ((reopened_at IS NULL) = (reopen_reason IS NULL)),
                CONSTRAINT account_period_closures_reopening_ordered
                    CHECK (reopened_at IS NULL OR reopened_at >= closed_at),
                CONSTRAINT account_period_closures_reason_valid
                    CHECK (reopen_reason IS NULL OR (btrim(reopen_reason) = reopen_reason AND reopen_reason <> ''))
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX account_period_closures_active_unique ON account_period_closures (workspace_id, year, month) WHERE reopened_at IS NULL');
        // The business days an idempotent write touched: a replay after a
        // closing re-checks them instead of re-serving a write no longer allowed.
        $this->addSql('ALTER TABLE transaction_idempotency_keys ADD COLUMN period_days JSONB DEFAULT NULL');
        $this->addSql("ALTER TABLE transaction_idempotency_keys ADD CONSTRAINT transaction_idempotency_keys_period_days_valid CHECK (period_days IS NULL OR (jsonb_typeof(period_days) = 'array' AND jsonb_array_length(period_days) <= 8))");
        $this->addSql('CREATE INDEX account_period_closures_year_index ON account_period_closures (workspace_id, year, month DESC, closed_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction_idempotency_keys DROP COLUMN period_days');
        $this->addSql('DROP TABLE account_period_closures');
    }
}
