<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\AnnualReportPreferences;
use App\Module\Reporting\Domain\AnnualReportPreferencesRepository;

/** The annual report selection of the calling workspace, or the default one when it never saved any. */
final readonly class ReadAnnualPreferences
{
    public function __construct(
        private CallerWorkspace $caller,
        private AnnualReportPreferencesRepository $preferences,
        private ReadAnnualCatalogue $catalogue,
    ) {
    }

    public function __invoke(): AnnualPreferencesView
    {
        $stored = $this->stored();

        return new AnnualPreferencesView($stored->columns, $stored->incompleteMonths->value, $stored->version);
    }

    public function stored(): AnnualReportPreferences
    {
        $workspace = $this->caller->resolve();

        return $this->preferences->find($workspace) ?? new AnnualReportPreferences(
            $workspace,
            ($this->catalogue)($workspace)->defaultSelection(),
            IncompleteMonths::EXCLUDE,
            AnnualReportPreferences::UNSAVED_VERSION,
        );
    }
}
