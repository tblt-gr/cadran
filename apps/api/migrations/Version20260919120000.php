<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds workspace-scoped transaction recurrences, their expected occurrences and dismissed candidates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_recurrences (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                account_id UUID NOT NULL,
                label VARCHAR(80) NOT NULL,
                counterparty VARCHAR(80) DEFAULT NULL,
                expected_amount_value NUMERIC(50,24) NOT NULL,
                expected_amount_scale SMALLINT NOT NULL,
                asset_code VARCHAR(12) NOT NULL,
                amount_tolerance_value NUMERIC(50,24) NOT NULL,
                amount_tolerance_scale SMALLINT NOT NULL,
                interval_kind VARCHAR(9) NOT NULL,
                day_of_period SMALLINT NOT NULL,
                next_expected_on DATE NOT NULL,
                confirmed_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                archived_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT transaction_recurrences_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT transaction_recurrences_account_fk FOREIGN KEY (workspace_id, account_id)
                    REFERENCES account_financial_accounts (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_recurrences_asset_fk FOREIGN KEY (asset_code)
                    REFERENCES reference_assets (code) ON DELETE RESTRICT,
                CONSTRAINT transaction_recurrences_workspace_id_unique UNIQUE (workspace_id, id),
                CONSTRAINT transaction_recurrences_interval_valid
                    CHECK (interval_kind IN ('WEEKLY', 'MONTHLY', 'QUARTERLY', 'YEARLY')),
                CONSTRAINT transaction_recurrences_day_valid CHECK (
                    (interval_kind = 'WEEKLY' AND day_of_period BETWEEN 1 AND 7)
                    OR (interval_kind <> 'WEEKLY' AND day_of_period BETWEEN 1 AND 31)
                ),
                CONSTRAINT transaction_recurrences_amount_not_zero CHECK (expected_amount_value <> 0),
                CONSTRAINT transaction_recurrences_tolerance_valid CHECK (amount_tolerance_value >= 0),
                CONSTRAINT transaction_recurrences_amount_scale_valid
                    CHECK (expected_amount_scale BETWEEN 0 AND 24),
                CONSTRAINT transaction_recurrences_tolerance_scale_valid
                    CHECK (amount_tolerance_scale BETWEEN 0 AND 24),
                CONSTRAINT transaction_recurrences_label_present
                    CHECK (btrim(label) = label AND label <> ''),
                CONSTRAINT transaction_recurrences_version_valid CHECK (version > 0)
            )
            SQL);
        // Listing and the horizon refresh both read the live recurrences of one
        // workspace in schedule order.
        $this->addSql('CREATE INDEX transaction_recurrences_schedule_index ON transaction_recurrences (workspace_id, archived_at, next_expected_on, id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_recurrence_occurrences (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                recurrence_id UUID NOT NULL,
                expected_on DATE NOT NULL,
                expected_amount_value NUMERIC(50,24) NOT NULL,
                expected_amount_scale SMALLINT NOT NULL,
                amount_tolerance_value NUMERIC(50,24) NOT NULL,
                amount_tolerance_scale SMALLINT NOT NULL,
                matched_transaction_id UUID DEFAULT NULL,
                matched_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                status VARCHAR(8) NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT transaction_recurrence_occurrences_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT transaction_recurrence_occurrences_recurrence_fk
                    FOREIGN KEY (workspace_id, recurrence_id)
                    REFERENCES transaction_recurrences (workspace_id, id) ON DELETE CASCADE,
                CONSTRAINT transaction_recurrence_occurrences_transaction_fk
                    FOREIGN KEY (workspace_id, matched_transaction_id)
                    REFERENCES transaction_transactions (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_recurrence_occurrences_unique UNIQUE (recurrence_id, expected_on),
                CONSTRAINT transaction_recurrence_occurrences_transaction_unique UNIQUE (matched_transaction_id),
                CONSTRAINT transaction_recurrence_occurrences_status_valid
                    CHECK (status IN ('EXPECTED', 'RECEIVED')),
                CONSTRAINT transaction_recurrence_occurrences_amount_not_zero
                    CHECK (expected_amount_value <> 0),
                CONSTRAINT transaction_recurrence_occurrences_tolerance_valid
                    CHECK (amount_tolerance_value >= 0),
                CONSTRAINT transaction_recurrence_occurrences_amount_scale_valid
                    CHECK (expected_amount_scale BETWEEN 0 AND 24),
                CONSTRAINT transaction_recurrence_occurrences_tolerance_scale_valid
                    CHECK (amount_tolerance_scale BETWEEN 0 AND 24),
                CONSTRAINT transaction_recurrence_occurrences_match_shape
                    CHECK ((status = 'RECEIVED') = (matched_transaction_id IS NOT NULL)
                           AND (status = 'RECEIVED') = (matched_at IS NOT NULL))
            )
            SQL);
        // Matching looks for the nearest unmatched occurrence of a workspace
        // around a booked date, and the timeline reads one recurrence in order.
        $this->addSql('CREATE INDEX transaction_recurrence_occurrences_timeline_index ON transaction_recurrence_occurrences (workspace_id, recurrence_id, expected_on)');
        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_recurrence_dismissals (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                candidate_fingerprint CHAR(64) NOT NULL,
                dismissed_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT transaction_recurrence_dismissals_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT transaction_recurrence_dismissals_unique
                    UNIQUE (workspace_id, candidate_fingerprint),
                CONSTRAINT transaction_recurrence_dismissals_fingerprint_valid
                    CHECK (candidate_fingerprint ~ '^[0-9a-f]{64}$')
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE transaction_recurrence_dismissals');
        $this->addSql('DROP TABLE transaction_recurrence_occurrences');
        $this->addSql('DROP TABLE transaction_recurrences');
    }
}
