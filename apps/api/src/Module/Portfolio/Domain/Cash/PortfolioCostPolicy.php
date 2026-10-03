<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Cash;

/** The constant cost policy later performance figures follow. No setting changes it in this release. */
final readonly class PortfolioCostPolicy
{
    public const string PERFORMANCE_BASIS = 'NET_OF_FEES_AND_TAXES';
    public const string BUDGET_TREATMENT = 'OUTSIDE_BUDGET_INFORMATIVE';
}
