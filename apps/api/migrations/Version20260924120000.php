<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the monthly recap visibility preferences of one workspace.';
    }

    public function up(Schema $schema): void
    {
        // One row per workspace: the selection is a display preference, not a
        // policy, so it never needs a history. The version column carries the
        // optimistic lock that keeps two open tabs from silently overwriting
        // each other.
        $this->addSql(<<<'SQL'
            CREATE TABLE reporting_recap_preferences (
                workspace_id UUID NOT NULL,
                visible_category_ids JSONB NOT NULL,
                visible_axes JSONB NOT NULL,
                version INTEGER NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (workspace_id),
                CONSTRAINT reporting_recap_preferences_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE CASCADE,
                CONSTRAINT reporting_recap_preferences_version_valid CHECK (version > 0),
                CONSTRAINT reporting_recap_preferences_categories_valid
                    CHECK (jsonb_typeof(visible_category_ids) = 'array' AND jsonb_array_length(visible_category_ids) <= 100),
                CONSTRAINT reporting_recap_preferences_axes_valid
                    CHECK (jsonb_typeof(visible_axes) = 'array' AND jsonb_array_length(visible_axes) <= 100)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reporting_recap_preferences');
    }
}
