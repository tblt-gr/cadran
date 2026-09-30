<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\Goals\GoalSchedule;
use App\Module\Budget\Domain\Goals\GoalScheduleMode;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

final class GoalVersionRow
{
    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $parts the version's stored schedule parts, January first
     */
    public static function hydrate(array $row, WorkspaceScope $workspace, array $parts): GoalVersion
    {
        if ($workspace->id !== GoalRow::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A goal version row escaped its requested workspace.');
        }
        $common = [GoalRow::text($row['id'] ?? null), $workspace, GoalRow::text($row['goal_id'] ?? null), (int) GoalRow::text($row['version_no'] ?? null), GoalRow::text($row['effective_from'] ?? null)];
        $createdAt = new \DateTimeImmutable(GoalRow::text($row['created_at'] ?? null));
        $createdBy = GoalRow::text($row['created_by'] ?? null);
        $retroactive = filter_var($row['retroactive'] ?? false, FILTER_VALIDATE_BOOL);
        $reason = null === ($row['reason'] ?? null) ? null : GoalRow::text($row['reason']);
        if (null !== ($row['target_amount'] ?? null)) {
            $target = DecimalValue::fromString(self::decimal($row['target_amount'], $row['target_scale']));
            $mode = $row['schedule_mode'] ?? null;
            $schedule = null === $mode ? null : GoalSchedule::restore(GoalScheduleMode::from(GoalRow::text($mode)), $target, array_map(static fn (array $part): DecimalValue => DecimalValue::fromString(self::decimal($part['amount'] ?? null, $part['amount_scale'] ?? null)), $parts));

            return GoalVersion::annual(...[...$common, (int) GoalRow::text($row['year'] ?? null), $target, $schedule, $createdAt, $createdBy, $retroactive, $reason]);
        }

        return GoalVersion::rolling(...[...$common, DecimalValue::fromString(self::decimal($row['ratio'] ?? null, $row['ratio_scale'] ?? null)), $createdAt, $createdBy, $retroactive, $reason]);
    }

    /** @return array<string, mixed> */
    public static function columns(GoalVersion $version): array
    {
        return ['goal_id' => $version->goalId, 'version_no' => $version->versionNo, 'effective_from' => $version->effectiveFrom, 'year' => $version->year, 'target_amount' => $version->targetAmount?->toString(), 'target_scale' => $version->targetAmount?->scale(), 'ratio' => $version->ratio?->toString(), 'ratio_scale' => $version->ratio?->scale(), 'schedule_mode' => $version->scheduleMode(), 'retroactive' => $version->retroactive, 'reason' => $version->reason, 'created_at' => $version->createdAt->format('Y-m-d H:i:s.uP'), 'created_by' => $version->createdBy];
    }

    private static function decimal(mixed $value, mixed $scale): string
    {
        $numeric = GoalRow::text($value);
        $requestedScale = (int) GoalRow::text($scale);
        [$integer, $fraction] = array_pad(explode('.', $numeric, 2), 2, '');

        return 0 === $requestedScale ? $integer : $integer.'.'.substr(str_pad($fraction, $requestedScale, '0'), 0, $requestedScale);
    }
}
