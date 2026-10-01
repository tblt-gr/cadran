<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

enum InstrumentRegion: string
{
    case FRANCE = 'FRANCE';
    case EUROPE = 'EUROPE';
    case NORTH_AMERICA = 'NORTH_AMERICA';
    case ASIA_PACIFIC = 'ASIA_PACIFIC';
    case EMERGING = 'EMERGING';
    case WORLD = 'WORLD';
    case OTHER = 'OTHER';
}
