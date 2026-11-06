<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lets an idempotent bulk write keep one booked day per touched month (up to 500) for its replay re-check.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction_idempotency_keys DROP CONSTRAINT transaction_idempotency_keys_period_days_valid');
        $this->addSql("ALTER TABLE transaction_idempotency_keys ADD CONSTRAINT transaction_idempotency_keys_period_days_valid CHECK (period_days IS NULL OR (jsonb_typeof(period_days) = 'array' AND jsonb_array_length(period_days) <= 500))");
    }

    public function down(Schema $schema): void
    {
        // A longer list cannot satisfy the old bound; without days the replay simply skips its period re-check.
        $this->addSql('UPDATE transaction_idempotency_keys SET period_days = NULL WHERE period_days IS NOT NULL AND jsonb_array_length(period_days) > 8');
        $this->addSql('ALTER TABLE transaction_idempotency_keys DROP CONSTRAINT transaction_idempotency_keys_period_days_valid');
        $this->addSql("ALTER TABLE transaction_idempotency_keys ADD CONSTRAINT transaction_idempotency_keys_period_days_valid CHECK (period_days IS NULL OR (jsonb_typeof(period_days) = 'array' AND jsonb_array_length(period_days) <= 8))");
    }
}
