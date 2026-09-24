<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Categories\Application\BudgetCategoryScopeTooLarge;
use App\Module\Categories\Application\ReadBudgetCategoryFlags;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Reporting\Domain\InvalidRecapVisibility;
use App\Module\Reporting\Domain\RecapPreferences;
use App\Module\Reporting\Domain\RecapPreferencesRepository;
use App\Module\Reporting\Domain\RecapVisibility;

/**
 * Stores which optional breakdowns the calling workspace wants in its compact
 * recap.
 *
 * Only identifiers the caller's own workspace owns are accepted: a stored
 * preference is read back on every recap, so an identifier smuggled in here
 * would be a lasting probe into another workspace's categories.
 */
final readonly class SaveRecapPreferences
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private RecapPreferencesRepository $preferences,
        private ReadBudgetCategoryFlags $categories,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(SaveRecapPreferencesInput $input): RecapPreferencesView
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner may change recap preferences.');
        }
        $workspace = $context->workspace;
        try {
            $visibility = RecapVisibility::of($input->visibleCategoryIds, $input->visibleAxes, RecapAxes::all());
        } catch (InvalidRecapVisibility $exception) {
            throw new InvalidRecapPreferencesInput($exception->getMessage(), previous: $exception);
        }
        if ($input->version < RecapPreferences::UNSAVED_VERSION) {
            throw new InvalidRecapPreferencesInput('A recap preference version is never negative.');
        }

        try {
            $owned = $this->categories->__invoke($workspace);
        } catch (BudgetCategoryScopeTooLarge $exception) {
            throw new InvalidRecapPreferencesInput('This workspace holds more categories than one recap selection reads.', previous: $exception);
        }
        foreach ($visibility->categoryIds as $categoryId) {
            if (!array_key_exists($categoryId, $owned)) {
                throw new InvalidRecapPreferencesInput('A recap selection names only categories of the calling workspace.');
            }
        }

        $saved = (new RecapPreferences($workspace, $visibility, $input->version))
            ->saved($visibility, $this->calendar->now());
        if (!$this->preferences->save($saved, $input->version)) {
            throw new StaleRecapPreferencesVersion('The recap selection changed since it was read.');
        }

        return RecapPreferencesView::of($saved);
    }
}
