<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceBaseCurrencyReader;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;

/**
 * Computes the linear parts of a target in the caller's workspace currency
 * without writing anything, so the editor never divides on the client.
 */
final readonly class PreviewGoalSchedule
{
    public function __construct(private CallerWorkspace $caller, private WorkspaceBaseCurrencyReader $currencies, private BuildGoalSchedule $schedules)
    {
    }

    public function __invoke(string $target, string $mode): GoalScheduleView
    {
        $workspace = $this->caller->resolve();
        if ('LINEAR' !== $mode) {
            throw new GoalInputError('invalid_schedule_preview', 'Only a linear schedule can be previewed.');
        }
        try {
            $amount = DecimalValue::fromString($target);
        } catch (MalformedDecimal|PrecisionExceeded $error) {
            throw new GoalInputError('invalid_schedule_preview', $error->getMessage());
        }
        if ($amount->compareTo(DecimalValue::zero()) <= 0) {
            throw new GoalInputError('invalid_schedule_preview', 'A goal target amount is positive.');
        }

        return GoalScheduleView::of($this->schedules->linear($this->currencies->currency($workspace), $amount), null);
    }
}
