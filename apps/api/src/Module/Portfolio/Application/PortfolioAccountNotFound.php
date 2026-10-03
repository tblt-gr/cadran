<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

/** Unknown and foreign accounts are the same refusal, so a caller cannot probe other workspaces. */
final class PortfolioAccountNotFound extends \RuntimeException
{
}
