<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the account group color mode and stable color slot; migrated sub-group palette defaults become inherited shades.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE account_groups ADD color_mode VARCHAR(8) NOT NULL DEFAULT 'AUTO' CONSTRAINT account_groups_color_mode_check CHECK (color_mode IN ('AUTO', 'PARENT', 'CUSTOM'))");
        $this->addSql('ALTER TABLE account_groups ADD color_slot SMALLINT NOT NULL DEFAULT 0 CONSTRAINT account_groups_color_slot_check CHECK (color_slot BETWEEN 0 AND 32767)');
        $this->addSql("UPDATE account_groups SET color_mode = 'CUSTOM' WHERE color IS NOT NULL");
        // Same rule as AccountGroupColor::defaultFor: first two hex digits of sha256(id) modulo the palette size.
        $this->addSql(<<<'SQL'
            UPDATE account_groups
            SET color_mode = 'AUTO', color = NULL
            WHERE parent_id IS NOT NULL AND color IS NOT NULL
                AND color = (ARRAY['#486DF0', '#803078', '#58B8C0', '#F0A8D8'])[
                    (get_byte(sha256(convert_to(id::text, 'UTF8')), 0) % 4) + 1
                ]
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE account_groups g
            SET color_slot = ranked.slot
            FROM (
                SELECT id, (row_number() OVER (PARTITION BY workspace_id, parent_id ORDER BY created_at, id) - 1)::smallint AS slot
                FROM account_groups
                WHERE parent_id IS NOT NULL
            ) ranked
            WHERE g.id = ranked.id
            SQL);
        $this->addSql('ALTER TABLE account_groups ADD CONSTRAINT account_groups_color_mode_color_check CHECK ((color_mode = \'CUSTOM\') = (color IS NOT NULL))');
        $this->addSql('ALTER TABLE account_groups ADD CONSTRAINT account_groups_color_mode_parent_check CHECK (color_mode <> \'PARENT\' OR parent_id IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_groups DROP CONSTRAINT IF EXISTS account_groups_color_mode_parent_check');
        $this->addSql('ALTER TABLE account_groups DROP CONSTRAINT IF EXISTS account_groups_color_mode_color_check');
        $this->addSql('ALTER TABLE account_groups DROP color_slot');
        $this->addSql('ALTER TABLE account_groups DROP color_mode');
    }
}
