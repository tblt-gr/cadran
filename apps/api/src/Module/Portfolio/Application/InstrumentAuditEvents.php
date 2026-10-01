<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final class InstrumentAuditEvents
{
    public const string ENTITY = 'portfolio_instrument';
    public const string CREATED = 'instrument.created';
    public const string UPDATED = 'instrument.updated';
    public const string DEACTIVATED = 'instrument.deactivated';
    public const string REACTIVATED = 'instrument.reactivated';
}
