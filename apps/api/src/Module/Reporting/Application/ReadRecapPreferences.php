<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Reporting\Domain\RecapPreferences;
use App\Module\Reporting\Domain\RecapPreferencesRepository;

/**
 * The recap selection of the calling workspace, or the default one when it
 * never saved any.
 */
final readonly class ReadRecapPreferences
{
    public function __construct(
        private CallerWorkspace $caller,
        private RecapPreferencesRepository $preferences,
    ) {
    }

    public function __invoke(): RecapPreferencesView
    {
        return RecapPreferencesView::of($this->stored());
    }

    public function stored(): RecapPreferences
    {
        return $this->preferences->find($this->caller->resolve(), RecapAxes::all());
    }
}
