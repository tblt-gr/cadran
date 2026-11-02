<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Identity\Domain\InterfacePreferencesRepository;

/** The interface preferences of the calling workspace, or the defaults when it never saved any. */
final readonly class ReadInterfacePreferences
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private InterfacePreferencesRepository $preferences,
    ) {
    }

    public function __invoke(): InterfacePreferencesView
    {
        return InterfacePreferencesView::of($this->preferences->find($this->caller->resolveContext()->workspace));
    }
}
