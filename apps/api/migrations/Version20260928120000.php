<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Module\Budget\Domain\Goals\GoalSchedule;
use App\Module\Budget\Domain\Goals\InvalidGoalSchedule;
use App\Module\Foundation\Domain\DecimalValue;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the twelve-part monthly schedule of annual goal versions and splits existing ones linearly.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE budget_goal_versions ADD CONSTRAINT budget_goal_versions_schedule_mode_valid
                CHECK (schedule_mode IS NULL OR (schedule_mode IN ('LINEAR', 'CUSTOM') AND target_amount IS NOT NULL))
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE budget_goal_schedule_parts (
                goal_version_id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                month_index SMALLINT NOT NULL,
                amount NUMERIC(50, 24) NOT NULL,
                amount_scale SMALLINT NOT NULL,
                PRIMARY KEY (goal_version_id, month_index),
                CONSTRAINT budget_goal_schedule_parts_version_fk FOREIGN KEY (workspace_id, goal_version_id)
                    REFERENCES budget_goal_versions (workspace_id, id) ON DELETE CASCADE,
                CONSTRAINT budget_goal_schedule_parts_month_valid CHECK (month_index BETWEEN 1 AND 12),
                CONSTRAINT budget_goal_schedule_parts_amount_valid CHECK (amount >= 0),
                CONSTRAINT budget_goal_schedule_parts_scale_valid CHECK (amount_scale BETWEEN 0 AND 24)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION budget_goal_schedule_parts_immutable() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'a goal schedule part is never edited in place';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER budget_goal_schedule_parts_no_update
                BEFORE UPDATE ON budget_goal_schedule_parts
                FOR EACH ROW EXECUTE FUNCTION budget_goal_schedule_parts_immutable()
            SQL);
        // The count is checked at commit, once the version and its parts have
        // all been written; the application checks that the parts sum exactly.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION budget_goal_schedule_complete() RETURNS TRIGGER AS $$
            DECLARE
                target UUID;
                mode VARCHAR(8);
                part_count INTEGER;
            BEGIN
                IF TG_TABLE_NAME = 'budget_goal_versions' THEN
                    target := NEW.id;
                ELSE
                    target := coalesce(NEW.goal_version_id, OLD.goal_version_id);
                END IF;
                SELECT schedule_mode INTO mode FROM budget_goal_versions WHERE id = target;
                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;
                SELECT count(*) INTO part_count FROM budget_goal_schedule_parts WHERE goal_version_id = target;
                IF (mode IS NULL AND part_count <> 0) OR (mode IS NOT NULL AND part_count <> 12) THEN
                    RAISE EXCEPTION 'a scheduled goal version has exactly twelve parts';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER budget_goal_versions_schedule_check
                AFTER INSERT OR UPDATE OF schedule_mode ON budget_goal_versions
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION budget_goal_schedule_complete()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER budget_goal_schedule_parts_count_check
                AFTER INSERT OR DELETE ON budget_goal_schedule_parts
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION budget_goal_schedule_complete()
            SQL);

        $this->splitExistingAnnualVersions();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER budget_goal_versions_schedule_check ON budget_goal_versions');
        $this->addSql('DROP TABLE budget_goal_schedule_parts');
        $this->addSql('DROP FUNCTION budget_goal_schedule_complete()');
        $this->addSql('DROP FUNCTION budget_goal_schedule_parts_immutable()');
        $this->addSql('UPDATE budget_goal_versions SET schedule_mode = NULL');
        $this->addSql('ALTER TABLE budget_goal_versions DROP CONSTRAINT budget_goal_versions_schedule_mode_valid');
    }

    /**
     * Splits every existing annual version through the same domain rule the
     * application uses, never through SQL division. A target the rule refuses
     * (finer than the currency precision, or too small) keeps no schedule and
     * reads as not calculable rather than as an invented calendar.
     */
    private function splitExistingAnnualVersions(): void
    {
        $versions = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT v.id, v.workspace_id, v.target_amount::text AS target_amount, a.display_precision
            FROM budget_goal_versions v
            JOIN budget_goals g ON g.workspace_id = v.workspace_id AND g.id = v.goal_id
            JOIN reference_assets a ON a.code = g.asset_code
            WHERE v.target_amount IS NOT NULL AND v.schedule_mode IS NULL
            ORDER BY v.id
            SQL);
        foreach ($versions as $version) {
            $precision = (int) $version['display_precision'];
            try {
                $schedule = GoalSchedule::linear(DecimalValue::fromString((string) $version['target_amount']), $precision);
            } catch (InvalidGoalSchedule) {
                continue;
            }
            foreach ($schedule->parts as $index => $part) {
                $this->addSql(
                    'INSERT INTO budget_goal_schedule_parts (goal_version_id, workspace_id, month_index, amount, amount_scale) VALUES (?, ?, ?, ?, ?)',
                    [$version['id'], $version['workspace_id'], $index + 1, $part->toString(), $part->scale()],
                );
            }
            $this->addSql("UPDATE budget_goal_versions SET schedule_mode = 'LINEAR' WHERE id = ?", [$version['id']]);
        }
    }
}
