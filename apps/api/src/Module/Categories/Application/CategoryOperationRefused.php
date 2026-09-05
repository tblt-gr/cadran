<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

/**
 * A lifecycle operation the impact assessment refuses. The blockers travel with
 * the exception so the API can name them instead of answering a bare 422.
 */
final class CategoryOperationRefused extends \RuntimeException
{
    /** @param list<CategoryImpactBlocker> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('The category operation was refused by its impact assessment.');
    }
}
