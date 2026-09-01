<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates workspace-scoped typed categories with bounded trees and analytic defaults.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE category_categories (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                type VARCHAR(8) NOT NULL,
                label VARCHAR(80) NOT NULL,
                normalized_label TEXT GENERATED ALWAYS AS (lower(btrim(label))) STORED,
                parent_id UUID DEFAULT NULL,
                icon VARCHAR(32) DEFAULT NULL,
                color CHAR(7) DEFAULT NULL,
                default_analytic_axes JSONB NOT NULL DEFAULT '[]'::JSONB,
                budget_included BOOLEAN NOT NULL DEFAULT TRUE,
                sort_order SMALLINT NOT NULL DEFAULT 0,
                depth SMALLINT NOT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                used_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                archived_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT category_categories_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT category_categories_workspace_id_unique UNIQUE (workspace_id, id),
                CONSTRAINT category_categories_workspace_id_type_unique UNIQUE (workspace_id, id, type),
                CONSTRAINT category_categories_parent_fk FOREIGN KEY (workspace_id, parent_id, type)
                    REFERENCES category_categories (workspace_id, id, type) ON DELETE RESTRICT,
                CONSTRAINT category_categories_type_valid CHECK (type IN ('EXPENSE', 'INCOME')),
                CONSTRAINT category_categories_label_present CHECK (btrim(label) = label AND label <> ''),
                CONSTRAINT category_categories_label_bounded CHECK (char_length(label) <= 80),
                CONSTRAINT category_categories_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id),
                CONSTRAINT category_categories_icon_valid CHECK (icon IS NULL OR icon ~ '^[a-z][a-z0-9-]{0,31}$'),
                CONSTRAINT category_categories_color_valid CHECK (color IS NULL OR color ~ '^#[0-9A-F]{6}$'),
                CONSTRAINT category_categories_axes_valid CHECK (
                    jsonb_typeof(default_analytic_axes) = 'array'
                    AND default_analytic_axes <@ '["DISCRETIONARY", "ESSENTIAL", "FIXED", "PERSONAL", "PROFESSIONAL", "VARIABLE"]'::JSONB
                ),
                CONSTRAINT category_categories_sort_order_valid CHECK (sort_order BETWEEN 0 AND 32767),
                CONSTRAINT category_categories_depth_valid CHECK (depth BETWEEN 1 AND 8),
                CONSTRAINT category_categories_version_valid CHECK (version > 0)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE FUNCTION category_categories_validate_invariants() RETURNS TRIGGER AS $$
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
        $this->addSql(<<<'SQL'
            CREATE TRIGGER category_categories_invariants
                BEFORE INSERT OR UPDATE ON category_categories
                FOR EACH ROW EXECUTE FUNCTION category_categories_validate_invariants()
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX category_categories_active_sibling_label_unique
                ON category_categories (
                    workspace_id,
                    type,
                    COALESCE(parent_id, '00000000-0000-0000-0000-000000000000'::UUID),
                    normalized_label
                )
                WHERE archived_at IS NULL
            SQL);
        $this->addSql('CREATE INDEX category_categories_tree_index ON category_categories (workspace_id, parent_id, sort_order, normalized_label)');
        $this->addSql('CREATE INDEX category_categories_archived_index ON category_categories (workspace_id, archived_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE category_categories');
        $this->addSql('DROP FUNCTION category_categories_validate_invariants()');
    }
}
