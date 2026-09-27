<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\AnnualReportPreferences;
use App\Module\Reporting\Domain\AnnualReportPreferencesRepository;

/**
 * Stores the annual report columns of the calling workspace.
 *
 * Only columns of the caller's own workspace are accepted: a stored id is read back on every
 * report, so a foreign one would be a lasting probe into another workspace.
 */
final readonly class SaveAnnualPreferences
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AnnualReportPreferencesRepository $preferences,
        private ReadAnnualCatalogue $catalogue,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(SaveAnnualPreferencesInput $input): AnnualPreferencesView
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner may change annual report preferences.');
        }
        $workspace = $context->workspace;
        $incomplete = IncompleteMonths::tryFrom($input->incompleteMonths)
            ?? throw new InvalidAnnualPreferencesInput('The incomplete-month setting is exclude or include.');
        if ($input->version < AnnualReportPreferences::UNSAVED_VERSION) {
            throw new InvalidAnnualPreferencesInput('An annual report preference version is never negative.');
        }
        if (count($input->columns) > AnnualReportPreferences::MAX_COLUMNS) {
            throw new InvalidAnnualPreferencesInput('An annual report shows at most 40 columns.');
        }
        if (count($input->columns) !== count(array_unique($input->columns))) {
            throw new InvalidAnnualPreferencesInput('An annual report column appears once.');
        }
        $catalogue = ($this->catalogue)($workspace);
        foreach ($input->columns as $id) {
            if (!$catalogue->has($id)) {
                throw new InvalidAnnualPreferencesInput('An annual report column names a known indicator or an entity of this workspace.');
            }
        }

        $saved = (new AnnualReportPreferences($workspace, [], $incomplete, $input->version))
            ->saved($input->columns, $incomplete, $this->calendar->now());
        if (!$this->preferences->save($saved, $input->version)) {
            throw new StaleAnnualPreferencesVersion('The annual report selection changed since it was read.');
        }

        return new AnnualPreferencesView($saved->columns, $saved->incompleteMonths->value, $saved->version);
    }
}
