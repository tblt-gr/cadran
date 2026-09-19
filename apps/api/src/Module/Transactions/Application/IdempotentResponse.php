<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class IdempotentResponse
{
    /**
     * @param array<string, mixed> $body
     * @param list<string>         $periodDays business days (Y-m-d) the write touched, kept so a replay
     *                                         can be refused once one of their months has closed
     */
    public function __construct(public array $body, public int $status, public ?string $entityId = null, public array $periodDays = [])
    {
    }
}
