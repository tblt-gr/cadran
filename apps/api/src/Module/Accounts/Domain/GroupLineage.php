<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * A group and the exclusive ancestors that inherit its weight, self first.
 *
 * Parent roll-up uses this list so a child contribution is counted once on
 * the child and once on each ancestor — the exclusive tree — never via tags.
 */
final readonly class GroupLineage
{
    /**
     * @param list<string> $ancestorIdsIncludingSelf
     */
    public function __construct(
        public string $groupId,
        public array $ancestorIdsIncludingSelf,
    ) {
    }
}
