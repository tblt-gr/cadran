<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\GoalInputError;
use App\Module\Budget\Application\Goals\GoalScheduleInput;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\MalformedPayload;

/**
 * Reads the optional `schedule` object of a goal body, shared by creation and update:
 * a mode, plus the part strings of a custom schedule.
 */
final class GoalScheduleBody
{
    private const int MAX_PARTS = 24;

    public static function read(JsonPayload $payload): ?GoalScheduleInput
    {
        try {
            $schedule = $payload->nullableObject('schedule', ['mode'], ['parts']);
            if (null === $schedule) {
                return null;
            }

            return new GoalScheduleInput(
                $schedule->string('mode'),
                null === $schedule->raw('parts') ? null : $schedule->stringList('parts', self::MAX_PARTS),
            );
        } catch (MalformedPayload $error) {
            throw new GoalInputError('invalid_goal_schedule', $error->getMessage());
        }
    }
}
