<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates exclusive account groups and a workspace-scoped primary group link.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE account_groups (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                label VARCHAR(80) NOT NULL,
                normalized_label TEXT GENERATED ALWAYS AS (lower(btrim(label))) STORED,
                parent_id UUID DEFAULT NULL,
                sort_order SMALLINT NOT NULL DEFAULT 0,
                depth SMALLINT NOT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                archived_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT account_groups_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT account_groups_scoped_identity UNIQUE (id, workspace_id),
                CONSTRAINT account_groups_parent_fk FOREIGN KEY (parent_id, workspace_id)
                    REFERENCES account_groups (id, workspace_id) ON DELETE RESTRICT,
                CONSTRAINT account_groups_label_present CHECK (btrim(label) = label AND label <> ''),
                CONSTRAINT account_groups_label_bounded CHECK (char_length(label) <= 80),
                CONSTRAINT account_groups_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id),
                CONSTRAINT account_groups_sort_order_valid CHECK (sort_order BETWEEN 0 AND 32767),
                CONSTRAINT account_groups_depth_valid CHECK (depth BETWEEN 1 AND 8),
                CONSTRAINT account_groups_version_valid CHECK (version > 0)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE FUNCTION account_groups_validate_invariants() RETURNS TRIGGER AS $$
            DECLARE
                parent_depth SMALLINT;
                cursor_id UUID;
                visited SMALLINT := 0;
            BEGIN
                IF NEW.parent_id IS NULL THEN
                    IF NEW.depth <> 1 THEN
                        RAISE EXCEPTION 'a root group must have depth 1';
                    END IF;
                ELSE
                    SELECT depth INTO parent_depth
                    FROM account_groups
                    WHERE workspace_id = NEW.workspace_id AND id = NEW.parent_id;

                    IF parent_depth IS NULL OR NEW.depth <> parent_depth + 1 THEN
                        RAISE EXCEPTION 'a child group must follow its parent depth';
                    END IF;

                    cursor_id := NEW.parent_id;
                    WHILE cursor_id IS NOT NULL LOOP
                        IF cursor_id = NEW.id THEN
                            RAISE EXCEPTION 'a group tree cannot contain a cycle';
                        END IF;
                        visited := visited + 1;
                        IF visited > 8 THEN
                            RAISE EXCEPTION 'a group tree is too deep';
                        END IF;
                        SELECT parent_id INTO cursor_id
                        FROM account_groups
                        WHERE workspace_id = NEW.workspace_id AND id = cursor_id;
                    END LOOP;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER account_groups_invariants
                BEFORE INSERT OR UPDATE ON account_groups
                FOR EACH ROW EXECUTE FUNCTION account_groups_validate_invariants()
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX account_groups_active_sibling_label_unique
                ON account_groups (
                    workspace_id,
                    COALESCE(parent_id, '00000000-0000-0000-0000-000000000000'::UUID),
                    normalized_label
                )
                WHERE archived_at IS NULL
            SQL);
        $this->addSql('CREATE INDEX account_groups_tree_index ON account_groups (workspace_id, parent_id, sort_order, normalized_label)');
        $this->addSql('CREATE INDEX account_groups_archived_index ON account_groups (workspace_id, archived_at)');

        $this->addSql(<<<'SQL'
            ALTER TABLE account_financial_accounts
                ADD COLUMN primary_group_id UUID DEFAULT NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE account_financial_accounts
                ADD CONSTRAINT account_financial_accounts_primary_group_fk
                    FOREIGN KEY (primary_group_id, workspace_id)
                    REFERENCES account_groups (id, workspace_id) ON DELETE RESTRICT
            SQL);
        $this->addSql('CREATE INDEX account_financial_accounts_primary_group_index ON account_financial_accounts (workspace_id, primary_group_id)');

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

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE account_group_tags');
        $this->addSql('ALTER TABLE account_financial_accounts DROP CONSTRAINT account_financial_accounts_primary_group_fk');
        $this->addSql('DROP INDEX account_financial_accounts_primary_group_index');
        $this->addSql('ALTER TABLE account_financial_accounts DROP COLUMN primary_group_id');
        $this->addSql('DROP TRIGGER account_groups_invariants ON account_groups');
        $this->addSql('DROP FUNCTION account_groups_validate_invariants()');
        $this->addSql('DROP TABLE account_groups');
    }
}
