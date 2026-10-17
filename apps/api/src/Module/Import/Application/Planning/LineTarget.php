<?php

declare(strict_types=1);

namespace App\Module\Import\Application\Planning;

use App\Module\Import\Application\ImportAccount;
use App\Module\Import\Application\ImportCategory;

/** Where the movements of one labelled line go once its mapping is resolved against the workspace. */
final readonly class LineTarget
{
    /**
     * @param list<string>|null $axes
     */
    public function __construct(
        public ImportAccount $account,
        public ImportCategory $category,
        public ?array $axes,
    ) {
    }
}
