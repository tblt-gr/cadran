<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Promotes account tags that name a sub-group of the primary group into the primary membership and drops account_group_tags. down() recreates the table empty: dropped tags are not restored.';
    }

    public function up(Schema $schema): void
    {
        // Sort key of a group among its siblings, mirroring AccountGroupTree::depthFirst
        // (sort order, lower-cased label, identifier). chr(1) keeps a label that is a
        // prefix of another one ahead of it, as a byte-wise comparison does.
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE account_grouping_migration ON COMMIT DROP AS
            WITH RECURSIVE descendants AS (
                SELECT a.workspace_id, a.id AS account_id, child.id AS group_id, 1 AS level,
                    ARRAY[(lpad(child.sort_order::text, 5, '0') || chr(1) || child.normalized_label || chr(1) || child.id::text) COLLATE "C"] AS sort_path
                FROM account_financial_accounts a
                JOIN account_groups child ON child.workspace_id = a.workspace_id AND child.parent_id = a.primary_group_id
                WHERE a.primary_group_id IS NOT NULL
                    AND child.archived_at IS NULL
                    AND EXISTS (SELECT 1 FROM account_group_tags t WHERE t.workspace_id = a.workspace_id AND t.account_id = a.id)
                UNION ALL
                SELECT d.workspace_id, d.account_id, child.id, d.level + 1,
                    d.sort_path || ((lpad(child.sort_order::text, 5, '0') || chr(1) || child.normalized_label || chr(1) || child.id::text) COLLATE "C")
                FROM descendants d
                JOIN account_groups child ON child.workspace_id = d.workspace_id AND child.parent_id = d.group_id
                WHERE child.archived_at IS NULL AND d.level < 8
            ),
            chosen AS (
                SELECT DISTINCT ON (d.account_id) d.account_id, d.group_id
                FROM descendants d
                JOIN account_group_tags t
                    ON t.workspace_id = d.workspace_id AND t.account_id = d.account_id AND t.group_id = d.group_id
                ORDER BY d.account_id, d.level DESC, d.sort_path COLLATE "C"
            )
            SELECT a.workspace_id, a.id AS account_id, a.primary_group_id AS before_primary,
                (SELECT jsonb_agg(t.group_id::text ORDER BY t.group_id::text)
                    FROM account_group_tags t
                    WHERE t.workspace_id = a.workspace_id AND t.account_id = a.id) AS before_tags,
                chosen.group_id AS promoted
            FROM account_financial_accounts a
            LEFT JOIN chosen ON chosen.account_id = a.id
            WHERE EXISTS (SELECT 1 FROM account_group_tags t WHERE t.workspace_id = a.workspace_id AND t.account_id = a.id)
            SQL);

        // Identifiers only: no label and no amount ever reaches the audit trail.
        $this->addSql(<<<'SQL'
            INSERT INTO audit_events (id, workspace_id, actor_id, event_type, entity_type, entity_id, before_json, after_json, occurred_at)
            SELECT gen_random_uuid(), workspace_id, NULL, 'account.grouping_migrated', 'account', account_id,
                jsonb_build_object('primaryGroupId', before_primary, 'tagGroupIds', before_tags),
                jsonb_build_object('primaryGroupId', COALESCE(promoted, before_primary)),
                clock_timestamp()
            FROM account_grouping_migration
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE account_financial_accounts a
            SET primary_group_id = m.promoted, version = a.version + 1, updated_at = now()
            FROM account_grouping_migration m
            WHERE m.promoted IS NOT NULL AND a.workspace_id = m.workspace_id AND a.id = m.account_id
            SQL);
        $this->addSql('DROP TABLE account_grouping_migration');
        $this->addSql('DROP TABLE account_group_tags');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE account_group_tags (
                workspace_id UUID NOT NULL,
                account_id UUID NOT NULL,
                group_id UUID NOT NULL,
                PRIMARY KEY (workspace_id, account_id, group_id),
                CONSTRAINT account_group_tags_account_fk FOREIGN KEY (account_id, workspace_id)
                    REFERENCES account_financial_accounts (id, workspace_id) ON DELETE CASCADE,
                CONSTRAINT account_group_tags_group_fk FOREIGN KEY (group_id, workspace_id)
                    REFERENCES account_groups (id, workspace_id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql('CREATE INDEX account_group_tags_group_index ON account_group_tags (workspace_id, group_id)');
    }
}
