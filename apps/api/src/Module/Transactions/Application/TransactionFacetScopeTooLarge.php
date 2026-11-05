<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/** Rolling counts up to parent categories needs the whole tree, and the workspace holds more than {@see ReadTransactionFacets::MAX_CATEGORIES} categories. */
final class TransactionFacetScopeTooLarge extends \RuntimeException
{
}
