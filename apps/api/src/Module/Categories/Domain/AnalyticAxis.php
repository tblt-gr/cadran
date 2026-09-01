<?php

declare(strict_types=1);

namespace App\Module\Categories\Domain;

enum AnalyticAxis: string
{
    case DISCRETIONARY = 'DISCRETIONARY';
    case ESSENTIAL = 'ESSENTIAL';
    case FIXED = 'FIXED';
    case PERSONAL = 'PERSONAL';
    case PROFESSIONAL = 'PROFESSIONAL';
    case VARIABLE = 'VARIABLE';
}
