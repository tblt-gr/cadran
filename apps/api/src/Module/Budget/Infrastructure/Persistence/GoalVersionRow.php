<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

final class GoalVersionRow
{
    /** @param array<string, mixed> $row */
    public static function hydrate(array $row, WorkspaceScope $workspace): GoalVersion
    {
        if ($workspace->id !== GoalRow::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A goal version row escaped its requested workspace.');
        }
        $common = [GoalRow::text($row['id'] ?? null), $workspace, GoalRow::text($row['goal_id'] ?? null), (int) GoalRow::text($row['version_no'] ?? null), GoalRow::text($row['effective_from'] ?? null)];
        $createdAt = new \DateTimeImmutable(GoalRow::text($row['created_at'] ?? null));
        $createdBy = GoalRow::text($row['created_by'] ?? null);
        if (null !== ($row['target_amount'] ?? null)) {
            return GoalVersion::annual(...[...$common, (int) GoalRow::text($row['year'] ?? null), DecimalValue::fromString(self::decimal($row['target_amount'], $row['target_scale'])), $createdAt, $createdBy]);
        }

        return GoalVersion::rolling(...[...$common, DecimalValue::fromString(self::decimal($row['ratio'] ?? null, $row['ratio_scale'] ?? null)), $createdAt, $createdBy]);
    }

    /** @return array<string, mixed> */
    public static function columns(GoalVersion $version): array
    {
        return ['goal_id' => $version->goalId, 'version_no' => $version->versionNo, 'effective_from' => $version->effectiveFrom, 'year' => $version->year, 'target_amount' => $version->targetAmount?->toString(), 'target_scale' => $version->targetAmount?->scale(), 'ratio' => $version->ratio?->toString(), 'ratio_scale' => $version->ratio?->scale(), 'schedule_mode' => $version->scheduleMode, 'reason' => $version->reason, 'created_at' => $version->createdAt->format('Y-m-d H:i:s.uP'), 'created_by' => $version->createdBy];
    }

    private static function decimal(mixed $value, mixed $scale): string
    {
        $numeric = GoalRow::text($value);
        $requestedScale = (int) GoalRow::text($scale);
        [$integer, $fraction] = array_pad(explode('.', $numeric, 2), 2, '');

        return 0 === $requestedScale ? $integer : $integer.'.'.substr(str_pad($fraction, $requestedScale, '0'), 0, $requestedScale);
    }
}
