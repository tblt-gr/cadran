<?php

declare(strict_types=1);

namespace App\Module\Audit\Domain;

interface AuditEventRepository
{
    /**
     * Appends one event. The trail has no update and no delete: a correction is
     * a later event, never a rewrite of an earlier one.
     */
    public function append(AuditEvent $event): void;
}
