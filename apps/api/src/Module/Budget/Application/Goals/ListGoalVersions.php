<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalSchedule;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Budget\Domain\Goals\GoalVersionResolver;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Identity\Application\AuthenticationUserRepository;

final readonly class ListGoalVersions
{
    public function __construct(
        private CallerWorkspace $caller,
        private GoalRepository $goals,
        private GoalVersionRepository $versions,
        private AuthenticationUserRepository $users,
    ) {
    }

    public function __invoke(string $goalId): GoalVersionHistoryView
    {
        $workspace = $this->caller->resolve();
        $this->goals->find($workspace, $goalId) ?? throw new GoalNotFound('This goal does not exist in this workspace.');
        $versions = $this->versions->listByGoal($workspace, $goalId, 50);
        $names = [];
        foreach (array_unique(array_map(static fn (GoalVersion $version): string => $version->createdBy, $versions)) as $authorId) {
            $author = $this->users->findById($authorId);
            $names[$authorId] = null === $author ? 'Unknown user' : $author->displayName;
        }

        return new GoalVersionHistoryView(array_map(function (GoalVersion $version) use ($versions, $names): GoalVersionHistoryItemView {
            $priorVersions = array_values(array_filter($versions, static fn (GoalVersion $candidate): bool => $candidate->versionNo < $version->versionNo));
            $before = GoalVersionResolver::forMonth($priorVersions, CalendarMonth::fromString($version->effectiveFrom));

            return new GoalVersionHistoryItemView(
                self::definition($version),
                $version->retroactive,
                $version->reason,
                $version->createdAt,
                $version->createdBy,
                $names[$version->createdBy],
                self::changes($before, $version),
            );
        }, $versions));
    }

    private static function definition(GoalVersion $version): GoalVersionView
    {
        return new GoalVersionView($version->versionNo, $version->effectiveFrom, $version->year, $version->targetAmount?->toString(), $version->ratio?->toString(), null === $version->schedule ? null : GoalScheduleView::of($version->schedule, $version->year));
    }

    /** @return list<GoalVersionChangeView> */
    private static function changes(?GoalVersion $before, GoalVersion $after): array
    {
        $changes = [];
        foreach (['targetAmount', 'ratio', 'scheduleMode', 'schedule'] as $field) {
            $old = self::field($before, $field);
            $new = self::field($after, $field);
            if ($old !== $new) {
                $changes[] = new GoalVersionChangeView($field, $old, $new);
            }
        }

        return $changes;
    }

    private static function field(?GoalVersion $version, string $field): mixed
    {
        return match ($field) {
            'targetAmount' => $version?->targetAmount?->toString(),
            'ratio' => $version?->ratio?->toString(),
            'scheduleMode' => $version?->scheduleMode(),
            'schedule' => null === $version?->schedule ? null : self::parts($version->schedule),
            default => throw new \LogicException('Unsupported goal version field.'),
        };
    }

    /** @return list<string> */
    private static function parts(GoalSchedule $schedule): array
    {
        return array_map(static fn (DecimalValue $part): string => $part->toString(), $schedule->parts);
    }
}
