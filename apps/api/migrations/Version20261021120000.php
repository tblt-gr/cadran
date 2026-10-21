<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve existing active-root chart palette assignments and add optional group colors.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE account_groups ADD color VARCHAR(7) DEFAULT NULL CHECK (color ~ '^#[0-9A-F]{6}$')");
        // The old chart indexed active roots after filtering, independently in each workspace.
        $this->addSql(<<<'SQL'
            WITH palette AS (
                SELECT workspace_id, id,
                    (row_number() OVER (PARTITION BY workspace_id ORDER BY sort_order, normalized_label, id) - 1) % 4 AS palette_index
                FROM account_groups WHERE depth = 1 AND archived_at IS NULL
            )
            UPDATE account_groups AS groups
            SET color = (ARRAY['#486DF0', '#803078', '#58B8C0', '#F0A8D8'])[palette.palette_index::int + 1]
            FROM palette WHERE groups.workspace_id = palette.workspace_id AND groups.id = palette.id
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_groups DROP color');
    }
}
