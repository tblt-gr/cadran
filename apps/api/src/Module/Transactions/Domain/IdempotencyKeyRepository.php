<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface IdempotencyKeyRepository
{
    public function begin(
        WorkspaceScope $workspace,
        string $useCase,
        string $key,
        string $fingerprint,
        \DateTimeImmutable $now,
        \DateTimeImmutable $expiresAt,
    ): IdempotencyKey;

    /**
     * @param array<string, mixed> $responseBody
     * @param list<string>         $periodDays
     */
    public function complete(
        WorkspaceScope $workspace,
        IdempotencyKey $key,
        int $responseStatus,
        array $responseBody,
        ?string $entityId,
        \DateTimeImmutable $completedAt,
        array $periodDays = [],
    ): void;

    /** Deletes at most $limit expired records and returns the number removed. */
    public function purgeExpired(\DateTimeImmutable $now, int $limit): int;
}
