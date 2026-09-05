<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates dated account balance snapshots with one active row per account, date and source.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE account_balance_snapshots (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                account_id UUID NOT NULL,
                as_of DATE NOT NULL,
                amount_value NUMERIC(50, 24) NOT NULL,
                amount_literal VARCHAR(80) NOT NULL,
                amount_asset VARCHAR(12) NOT NULL,
                source VARCHAR(16) NOT NULL,
                reconciliation_status VARCHAR(16) NOT NULL,
                comment VARCHAR(200) DEFAULT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                recorded_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                recorded_by UUID NOT NULL,
                superseded_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT account_balance_snapshots_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT account_balance_snapshots_account_fk FOREIGN KEY (account_id, workspace_id)
                    REFERENCES account_financial_accounts (id, workspace_id) ON DELETE RESTRICT,
                CONSTRAINT account_balance_snapshots_asset_fk FOREIGN KEY (amount_asset)
                    REFERENCES reference_assets (code) ON DELETE RESTRICT,
                CONSTRAINT account_balance_snapshots_author_fk FOREIGN KEY (recorded_by)
                    REFERENCES identity_users (id) ON DELETE RESTRICT,
                CONSTRAINT account_balance_snapshots_scoped_identity UNIQUE (id, workspace_id),
                CONSTRAINT account_balance_snapshots_source_valid
                    CHECK (source IN ('MANUAL', 'IMPORT', 'BANK_API', 'CALCULATED')),
                CONSTRAINT account_balance_snapshots_reconciliation_valid
                    CHECK (reconciliation_status IN ('UNRECONCILED', 'RECONCILED')),
                CONSTRAINT account_balance_snapshots_literal_canonical
                    CHECK (amount_literal ~ '^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$'),
                CONSTRAINT account_balance_snapshots_comment_present
                    CHECK (comment IS NULL OR (btrim(comment) = comment AND comment <> '')),
                CONSTRAINT account_balance_snapshots_comment_bounded
                    CHECK (comment IS NULL OR char_length(comment) <= 200),
                CONSTRAINT account_balance_snapshots_as_of_bounded
                    CHECK (as_of >= DATE '1900-01-01' AND as_of <= DATE '2100-12-31'),
                CONSTRAINT account_balance_snapshots_not_future
                    CHECK (as_of <= (recorded_at AT TIME ZONE 'UTC')::DATE),
                CONSTRAINT account_balance_snapshots_superseded_after_recording
                    CHECK (superseded_at IS NULL OR superseded_at >= recorded_at),
                CONSTRAINT account_balance_snapshots_version_valid CHECK (version > 0)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX account_balance_snapshots_active_unique
                ON account_balance_snapshots (workspace_id, account_id, as_of, source)
                WHERE superseded_at IS NULL
            SQL);
        $this->addSql('CREATE INDEX account_balance_snapshots_account_date_index ON account_balance_snapshots (workspace_id, account_id, as_of DESC, recorded_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE account_balance_snapshots');
    }
}
