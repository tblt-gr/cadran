<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the global read-only asset reference with its storage, display and rounding rules.';
    }

    public function up(Schema $schema): void
    {
        // A global, read-only system reference: an asset belongs to no
        // workspace and no request writes this table. New assets and corrected
        // precisions arrive through a later migration, which keeps the
        // reference reviewable in Git rather than editable at runtime.
        $this->addSql(<<<'SQL'
            CREATE TABLE reference_assets (
                code VARCHAR(12) NOT NULL,
                kind VARCHAR(16) NOT NULL,
                display_name VARCHAR(64) NOT NULL,
                storage_precision SMALLINT NOT NULL,
                display_precision SMALLINT NOT NULL,
                rounding_mode VARCHAR(16) NOT NULL,
                PRIMARY KEY (code),
                CONSTRAINT reference_assets_code_format CHECK (code ~ '^[A-Z][A-Z0-9]{1,11}$'),
                CONSTRAINT reference_assets_kind_valid CHECK (kind IN ('FIAT', 'CRYPTO')),
                CONSTRAINT reference_assets_display_name_present CHECK (btrim(display_name) <> ''),
                -- NUMERIC(50,24) is the storage type of every financial figure,
                -- so no asset may claim a scale the column cannot hold.
                CONSTRAINT reference_assets_storage_precision_storable CHECK (storage_precision BETWEEN 0 AND 24),
                -- Showing more decimals than are stored would invent digits.
                CONSTRAINT reference_assets_display_precision_shown CHECK (display_precision BETWEEN 0 AND storage_precision),
                CONSTRAINT reference_assets_rounding_mode_valid CHECK (rounding_mode IN ('HALF_UP', 'HALF_EVEN', 'DOWN'))
            )
            SQL);

        // Storage stays wider than the minor unit for every currency: import
        // files, unit prices and exchange conversions produce sub-cent figures
        // that must be recorded as received rather than rounded on the way in.
        // Display precision follows the ISO 4217 minor unit, and rounding is
        // half up, the French retail and accounting convention.
        $this->addSql(<<<'SQL'
            INSERT INTO reference_assets (code, kind, display_name, storage_precision, display_precision, rounding_mode) VALUES
                ('EUR', 'FIAT', 'Euro', 8, 2, 'HALF_UP'),
                ('USD', 'FIAT', 'United States dollar', 8, 2, 'HALF_UP'),
                ('GBP', 'FIAT', 'Pound sterling', 8, 2, 'HALF_UP'),
                ('CHF', 'FIAT', 'Swiss franc', 8, 2, 'HALF_UP'),
                ('JPY', 'FIAT', 'Japanese yen', 8, 0, 'HALF_UP'),
                ('BTC', 'CRYPTO', 'Bitcoin', 8, 8, 'HALF_UP'),
                ('ETH', 'CRYPTO', 'Ether', 18, 8, 'HALF_UP')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reference_assets');
    }
}
