<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Audit\Domain\AuditEvent;
use App\Module\Audit\Domain\AuditEventRepository;

final class CollectingAuditEventRepository implements AuditEventRepository
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}
