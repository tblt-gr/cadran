<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\GoalSchedule;
use App\Module\Budget\Domain\Goals\GoalScheduleMode;
use App\Module\Budget\Domain\Goals\InvalidGoalSchedule;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Reference\Application\AssetCatalog;

/**
 * Turns a submitted schedule into the domain schedule of one annual version,
 * at the display precision of the goal's asset, and gives every refusal its
 * public error code.
 */
final readonly class BuildGoalSchedule
{
    public function __construct(private AssetCatalog $assets)
    {
    }

    public function __invoke(AssetCode $asset, DecimalValue $target, GoalScheduleInput $input): GoalSchedule
    {
        $mode = GoalScheduleMode::tryFrom($input->mode) ?? throw new GoalInputError('invalid_goal_schedule', 'A goal schedule is LINEAR or CUSTOM.');
        $precision = $this->displayPrecision($asset);
        try {
            if (GoalScheduleMode::LINEAR === $mode) {
                if (null !== $input->parts) {
                    throw new GoalInputError('invalid_goal_schedule', 'A linear schedule computes its own parts.');
                }

                return GoalSchedule::linear($target, $precision);
            }
            // Counted before parsing so an oversized list is refused cheaply.
            if (null === $input->parts || GoalSchedule::MONTHS !== count($input->parts)) {
                throw new GoalInputError('schedule_part_count', 'A custom schedule has exactly twelve monthly parts.');
            }

            return GoalSchedule::custom($target, array_map(DecimalValue::fromString(...), $input->parts), $precision);
        } catch (InvalidGoalSchedule $error) {
            throw new GoalInputError($error->errorCode, $error->getMessage(), $error->details);
        } catch (MalformedDecimal|PrecisionExceeded $error) {
            throw new GoalInputError('invalid_goal_schedule', $error->getMessage());
        }
    }

    public function linear(AssetCode $asset, DecimalValue $target): GoalSchedule
    {
        return $this($asset, $target, new GoalScheduleInput(GoalScheduleMode::LINEAR->value, null));
    }

    private function displayPrecision(AssetCode $asset): int
    {
        return ($this->assets->findByCode($asset) ?? throw new \LogicException('A goal asset must exist in the reference catalogue.'))->precision->display;
    }
}
