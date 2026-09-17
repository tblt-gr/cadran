<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

/**
 * The most recent (updated_at, id) pair written in a workspace, used to
 * detect a background change between two pages of the same keyset query.
 *
 * HTTP pagination cannot hold a database snapshot open across requests, so
 * this is a change *detector*, not a guarantee of isolation: a later page
 * compares its cursor's watermark against the current one and refuses to
 * serve a possibly-inconsistent page when they differ.
 */
final readonly class TransactionWatermark
{
    public function __construct(
        public \DateTimeImmutable $updatedAt,
        public string $id,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->updatedAt->format('Y-m-d H:i:s.u') === $other->updatedAt->format('Y-m-d H:i:s.u')
            && $this->id === $other->id;
    }
}
