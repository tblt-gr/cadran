<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forbids category ancestry cycles in the database and records dated category replacements.';
    }

    public function up(Schema $schema): void
    {
        // CAT-001 only had to forbid a self-parent because no endpoint could reparent a category.
        // A move can, so the ancestry is now walked on every write: the application check and this
        // one must both fail before a cycle can reach the table.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION category_categories_validate_invariants() RETURNS TRIGGER AS $$
            DECLARE
                parent_depth SMALLINT;
                cursor_id UUID;
                visited SMALLINT := 0;
            BEGIN
                IF NEW.parent_id IS NULL THEN
                    IF NEW.depth <> 1 THEN
                        RAISE EXCEPTION 'a root category must have depth 1';
                    END IF;
                ELSE
                    SELECT depth INTO parent_depth
                    FROM category_categories
                    WHERE workspace_id = NEW.workspace_id AND id = NEW.parent_id AND type = NEW.type;

                    IF parent_depth IS NULL OR NEW.depth <> parent_depth + 1 THEN
                        RAISE EXCEPTION 'a child category must follow its same-type parent depth';
                    END IF;

                    cursor_id := NEW.parent_id;
                    WHILE cursor_id IS NOT NULL LOOP
                        IF cursor_id = NEW.id THEN
                            RAISE EXCEPTION 'a category tree cannot contain a cycle';
                        END IF;
                        visited := visited + 1;
                        IF visited > 8 THEN
                            RAISE EXCEPTION 'a category tree is too deep';
                        END IF;
                        SELECT parent_id INTO cursor_id
                        FROM category_categories
                        WHERE workspace_id = NEW.workspace_id AND id = cursor_id;
                    END LOOP;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM jsonb_array_elements_text(NEW.default_analytic_axes) AS axis(value)
                    GROUP BY axis.value
                    HAVING count(*) > 1
                ) THEN
                    RAISE EXCEPTION 'category analytic axes must be unique';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.used_at IS NOT NULL AND NEW.type <> OLD.type THEN
                    RAISE EXCEPTION 'a used category cannot change type';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        // A merge redirects the whole history and archives its source, so it carries no date. A
        // replacement redirects from a date onwards and leaves the source usable before it. Both
        // are the same redirection, which is why they share one table and one uniqueness rule.
        $this->addSql(<<<'SQL'
            CREATE TABLE category_replacements (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                source_category_id UUID NOT NULL,
                target_category_id UUID NOT NULL,
                kind VARCHAR(16) NOT NULL,
                effective_from DATE DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT category_replacements_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT category_replacements_source_fk FOREIGN KEY (workspace_id, source_category_id)
                    REFERENCES category_categories (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT category_replacements_target_fk FOREIGN KEY (workspace_id, target_category_id)
                    REFERENCES category_categories (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT category_replacements_distinct CHECK (source_category_id <> target_category_id),
                CONSTRAINT category_replacements_kind_valid CHECK (kind IN ('MERGE', 'REPLACEMENT')),
                CONSTRAINT category_replacements_date_matches_kind CHECK (
                    (kind = 'MERGE' AND effective_from IS NULL)
                    OR (kind = 'REPLACEMENT' AND effective_from IS NOT NULL)
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX category_replacements_source_unique
                ON category_replacements (workspace_id, source_category_id)
            SQL);
        $this->addSql('CREATE INDEX category_replacements_target_index ON category_replacements (workspace_id, target_category_id)');

        $this->addSql(<<<'SQL'
            CREATE FUNCTION category_replacements_validate_invariants() RETURNS TRIGGER AS $$
            DECLARE
                source_type VARCHAR(8);
                target_type VARCHAR(8);
                target_archived_at TIMESTAMP(6) WITH TIME ZONE;
                cursor_id UUID;
                visited SMALLINT := 0;
            BEGIN
                SELECT type INTO source_type
                FROM category_categories
                WHERE workspace_id = NEW.workspace_id AND id = NEW.source_category_id;

                SELECT type, archived_at INTO target_type, target_archived_at
                FROM category_categories
                WHERE workspace_id = NEW.workspace_id AND id = NEW.target_category_id;

                IF source_type IS DISTINCT FROM target_type THEN
                    RAISE EXCEPTION 'a category replacement must stay inside one category type';
                END IF;

                IF target_archived_at IS NOT NULL THEN
                    RAISE EXCEPTION 'a category replacement must target an active category';
                END IF;

                cursor_id := NEW.target_category_id;
                WHILE cursor_id IS NOT NULL LOOP
                    IF cursor_id = NEW.source_category_id THEN
                        RAISE EXCEPTION 'a category replacement chain cannot contain a cycle';
                    END IF;
                    visited := visited + 1;
                    IF visited > 16 THEN
                        RAISE EXCEPTION 'a category replacement chain is too long';
                    END IF;
                    SELECT target_category_id INTO cursor_id
                    FROM category_replacements
                    WHERE workspace_id = NEW.workspace_id AND source_category_id = cursor_id;
                END LOOP;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER category_replacements_invariants
                BEFORE INSERT OR UPDATE ON category_replacements
                FOR EACH ROW EXECUTE FUNCTION category_replacements_validate_invariants()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER category_replacements_invariants ON category_replacements');
        $this->addSql('DROP FUNCTION category_replacements_validate_invariants()');
        $this->addSql('DROP TABLE category_replacements');

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION category_categories_validate_invariants() RETURNS TRIGGER AS $$
            DECLARE
                parent_depth SMALLINT;
            BEGIN
                IF NEW.parent_id IS NULL THEN
                    IF NEW.depth <> 1 THEN
                        RAISE EXCEPTION 'a root category must have depth 1';
                    END IF;
                ELSE
                    SELECT depth INTO parent_depth
                    FROM category_categories
                    WHERE workspace_id = NEW.workspace_id AND id = NEW.parent_id AND type = NEW.type;

                    IF parent_depth IS NULL OR NEW.depth <> parent_depth + 1 THEN
                        RAISE EXCEPTION 'a child category must follow its same-type parent depth';
                    END IF;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM jsonb_array_elements_text(NEW.default_analytic_axes) AS axis(value)
                    GROUP BY axis.value
                    HAVING count(*) > 1
                ) THEN
                    RAISE EXCEPTION 'category analytic axes must be unique';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.used_at IS NOT NULL AND NEW.type <> OLD.type THEN
                    RAISE EXCEPTION 'a used category cannot change type';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
    }
}
